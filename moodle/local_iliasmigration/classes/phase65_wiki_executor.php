<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Prepared guarded executor for ILIAS Wiki -> Moodle mod_wiki.
 *
 * This class is intentionally unreachable while the Wiki package validator
 * reports apply_implemented=false. It is committed ahead of the real multi-page
 * POC so the remaining validation can focus on ILIAS link/media semantics.
 */
final class phase65_wiki_executor {
    private string $packageroot;
    private string $migrationjson;
    private string $sourceinstance = '';

    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        $file = realpath($migrationjson);
        if ($root === false || !is_dir($root) || $file === false || !is_file($file)) {
            throw new \coding_exception('Unable to resolve migration.json or its package directory.');
        }
        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
        $this->migrationjson = $file;
    }

    /**
     * Execute a fully validated Wiki plan.
     *
     * This remains guarded by phase65_wiki_package.apply_implemented/apply_ready.
     */
    public function execute(array $document, int $categoryid): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/wiki/lib.php');
        require_once($CFG->dirroot . '/mod/wiki/locallib.php');
        require_once($CFG->libdir . '/filelib.php');

        $plan = $this->validated_plan($document, $categoryid);
        $this->assert_applyable($plan);

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourseid = (string) ($document['course']['source_id'] ?? '');
        $sourceversion = (string) ($document['source']['version'] ?? '');

        $courseoperation = $plan['operations'][0] ?? null;
        if (!is_array($courseoperation) || ($courseoperation['kind'] ?? '') !== 'course') {
            throw new \coding_exception('Wiki plan does not contain the Moodle course operation.');
        }

        $courseid = (int) ($courseoperation['target_id'] ?? 0);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        $originaluser = $USER;
        \core\session\manager::set_user(get_admin());

        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $results = [];
                foreach ($plan['operations'] as $operation) {
                    if ((string) ($operation['kind'] ?? '') !== 'wiki') {
                        $results[] = $operation;
                        continue;
                    }

                    $sectionnumber = $this->resolve_section_number(
                        $course,
                        $operation,
                        $sourcecourseid
                    );
                    $results[] = $this->apply_wiki(
                        $course,
                        $operation,
                        $sourcecourseid,
                        $sourceversion,
                        $sectionnumber
                    );
                }

                rebuild_course_cache($course->id, true);
                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
        } finally {
            if ($originaluser instanceof \stdClass) {
                \core\session\manager::set_user($originaluser);
            }
        }

        $plan['mode'] = 'apply';
        $plan['writes_performed'] = true;
        $plan['phase'] = '6.5.3';
        $plan['phase65_object'] = 'wiki';
        $plan['course']['target_id'] = (int) $course->id;
        $plan['operations'] = $results;
        $plan['warnings'][] = [
            'code' => 'COURSE_REMAINS_HIDDEN',
            'message' => 'The imported course remains hidden until the Wiki POC is visually validated.',
        ];
        return $plan;
    }

    /** Rebuild exactly the same validator chain as Wiki dry-run. */
    private function validated_plan(array $document, int $categoryid): array {
        $plan = (new phase6_plan_builder($categoryid))->build($document);
        $plan['phase'] = '6.5.3';
        $plan = (new phase3_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase4_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase5_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase6_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase6_scoring_policy_validator($this->migrationjson))->validate($plan);
        return (new phase65_wiki_package_validator($this->migrationjson))->validate($plan);
    }

    /** Refuse any write until the real multi-page POC explicitly enables apply. */
    private function assert_applyable(array $plan): void {
        $package = is_array($plan['phase65_wiki_package'] ?? null)
            ? $plan['phase65_wiki_package']
            : [];

        if (empty($package['ready'])
                || empty($package['apply_implemented'])
                || empty($package['apply_ready'])
                || !empty($package['blocked_wikis'])
                || !empty($package['page_blocked_count'])) {
            throw new \coding_exception('Wiki package validation failed; apply is refused.');
        }

        foreach ($plan['operations'] as $operation) {
            if ((string) ($operation['kind'] ?? '') !== 'wiki') {
                continue;
            }
            if (!in_array((string) ($operation['action'] ?? ''), ['CREATE', 'UPDATE'], true)) {
                throw new \coding_exception('Wiki parent has no approved CREATE/UPDATE action.');
            }
            $validation = is_array($operation['wiki_validation'] ?? null)
                ? $operation['wiki_validation']
                : [];
            if ((string) ($validation['status'] ?? '') !== 'OK'
                    || (string) ($validation['code'] ?? '') !== 'WIKI_READY') {
                throw new \coding_exception('Wiki validation is not ready for apply.');
            }
            foreach ((array) ($validation['pages'] ?? []) as $page) {
                if (!is_array($page)
                        || !in_array((string) ($page['action'] ?? ''), ['CREATE', 'UPDATE'], true)) {
                    throw new \coding_exception('At least one Wiki page has no approved action.');
                }
            }
        }
    }

    /** Create/update one Moodle Wiki and its current pages. */
    private function apply_wiki(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion,
        int $sectionnumber
    ): array {
        global $DB;

        $requested = (string) ($operation['action'] ?? '');
        $structure = $this->load_structure($operation);
        $render = (new phase65_wiki_renderer())->render($structure);

        $expectedfingerprint = (string) (
            $operation['wiki_validation']['fingerprint_sha256'] ?? ''
        );
        $actualfingerprint = (string) ($render['fingerprint_sha256'] ?? '');
        if ($expectedfingerprint === ''
                || !hash_equals($expectedfingerprint, $actualfingerprint)) {
            throw new \coding_exception(
                'Wiki HTML differs from the approved dry-run fingerprint; apply is refused.'
            );
        }

        $description = (string) ($operation['description'] ?? '');
        $starttitle = (string) ($operation['wiki_validation']['start_page_title'] ?? '');

        if ($requested === 'CREATE') {
            $moduledata = (object) [
                'modulename' => 'wiki',
                'course' => (int) $course->id,
                'section' => $sectionnumber,
                'visible' => 1,
                'name' => (string) ($operation['title'] ?? ''),
                'introeditor' => $this->intro_editor($description),
                'intro' => $description,
                'introformat' => FORMAT_HTML,
                'wikimode' => 'collaborative',
                'firstpagetitle' => $starttitle,
                'defaultformat' => 'html',
                'forceformat' => 1,
            ];
            $created = create_module($moduledata);
            $cmid = (int) $created->coursemodule;
            $instanceid = (int) $created->instance;
            $performed = 'CREATED';
        } else {
            $cmid = (int) ($operation['target_id'] ?? 0);
            $cm = get_coursemodule_from_id('wiki', $cmid, $course->id, false, MUST_EXIST);
            $this->assert_same_section($cm, $course, $sectionnumber);

            $existingwiki = $DB->get_record('wiki', ['id' => (int) $cm->instance], '*', MUST_EXIST);
            if ((string) $existingwiki->firstpagetitle !== $starttitle) {
                throw new \coding_exception(
                    'Changing the Moodle Wiki first page title is not yet supported.'
                );
            }
            if ((string) $existingwiki->wikimode !== 'collaborative'
                    || (string) $existingwiki->defaultformat !== 'html') {
                throw new \coding_exception(
                    'Mapped Moodle Wiki does not match the collaborative HTML migration contract.'
                );
            }

            [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
            $moduleinfo->name = (string) ($operation['title'] ?? '');
            $moduleinfo->introeditor = $this->intro_editor($description);
            update_module($moduleinfo);
            $instanceid = (int) $cm->instance;
            $performed = 'UPDATED';
        }

        $cm = get_coursemodule_from_id('wiki', $cmid, $course->id, false, MUST_EXIST);
        $wiki = $DB->get_record('wiki', ['id' => $instanceid], '*', MUST_EXIST);
        $context = \context_module::instance($cmid);

        $subwiki = wiki_get_subwiki_by_group((int) $wiki->id, 0, 0);
        if (!$subwiki) {
            $subwikiid = (int) wiki_add_subwiki((int) $wiki->id, 0, 0);
            $subwiki = wiki_get_subwiki($subwikiid);
        }
        if (!$subwiki) {
            throw new \coding_exception('Unable to create or resolve Moodle collaborative subwiki.');
        }

        $pageresults = $this->apply_pages(
            $operation,
            $render,
            $subwiki,
            $sourcecourseid,
            $sourceversion
        );

        $mediaresult = $this->reconcile_assets(
            $context,
            (int) $subwiki->id,
            (string) ($operation['source_ref_id'] ?? ''),
            (array) ($render['assets'] ?? [])
        );

        $this->save_mapping(
            $sourcecourseid,
            (string) ($operation['source_ref_id'] ?? ''),
            (string) ($operation['source_obj_id'] ?? ''),
            'wiki',
            $cmid,
            $sourceversion
        );

        $result = $operation;
        $result['requested_action'] = $requested;
        $result['action'] = $performed;
        $result['target_id'] = $cmid;
        $result['instance_id'] = $instanceid;
        $result['subwiki_id'] = (int) $subwiki->id;
        $result['moodle_section'] = $sectionnumber;
        $result['moodle_page_count'] = count($pageresults);
        $result['moodle_file_count'] = (int) ($mediaresult['stored_files'] ?? 0);
        $result['fingerprint_sha256'] = $actualfingerprint;
        $result['pages'] = $pageresults;
        $result['media_reconciliation'] = $mediaresult;
        return $result;
    }

    /**
     * Two-pass page application.
     *
     * Pass 1 establishes stable Moodle page ids and mappings. Pass 2 resolves
     * ILIAS Wiki-page placeholders to those target ids and saves current content.
     */
    private function apply_pages(
        array $operation,
        array $render,
        \stdClass $subwiki,
        string $sourcecourseid,
        string $sourceversion
    ): array {
        global $CFG, $DB, $USER;

        $planned = [];
        foreach ((array) ($operation['wiki_validation']['pages'] ?? []) as $page) {
            if (is_array($page)) {
                $planned[(string) ($page['source_id'] ?? '')] = $page;
            }
        }

        $rendered = [];
        foreach ((array) ($render['pages'] ?? []) as $page) {
            if (is_array($page)) {
                $rendered[(string) ($page['source_id'] ?? '')] = $page;
            }
        }

        $targetids = [];
        $pageobjects = [];
        $requestedactions = [];

        foreach ($planned as $sourceid => $pageplan) {
            $requested = (string) ($pageplan['action'] ?? '');
            $title = (string) ($pageplan['title'] ?? '');
            $page = null;

            if ($requested === 'CREATE') {
                $pageid = (int) wiki_create_page(
                    (int) $subwiki->id,
                    $title,
                    'html',
                    (int) $USER->id
                );
                $page = wiki_get_page($pageid);
            } else if ($requested === 'UPDATE') {
                $pageid = (int) ($pageplan['target_page_id'] ?? 0);
                $page = $DB->get_record(
                    'wiki_pages',
                    ['id' => $pageid, 'subwikiid' => (int) $subwiki->id],
                    '*',
                    MUST_EXIST
                );
                if ((string) $page->title !== $title) {
                    throw new \coding_exception(
                        "Mapped Wiki page title differs for source page {$sourceid}."
                    );
                }
            } else {
                throw new \coding_exception("Unsupported Wiki page action {$requested}.");
            }

            if (!$page) {
                throw new \coding_exception("Unable to resolve Moodle Wiki page for {$sourceid}.");
            }

            $targetids[$sourceid] = (int) $page->id;
            $pageobjects[$sourceid] = $page;
            $requestedactions[$sourceid] = $requested;

            $this->save_mapping(
                $sourcecourseid,
                (string) ($pageplan['mapping_ref'] ?? ''),
                $sourceid,
                'wiki_page',
                (int) $page->id,
                $sourceversion
            );
        }

        $results = [];
        foreach ($planned as $sourceid => $pageplan) {
            $renderedpage = $rendered[$sourceid] ?? null;
            if (!is_array($renderedpage)) {
                throw new \coding_exception(
                    "Rendered Wiki page {$sourceid} has no approved page plan."
                );
            }

            $expectedhash = (string) ($pageplan['html_sha256'] ?? '');
            $actualhash = (string) ($renderedpage['html_sha256'] ?? '');
            if ($expectedhash === '' || !hash_equals($expectedhash, $actualhash)) {
                throw new \coding_exception(
                    "Wiki page {$sourceid} HTML differs from the dry-run fingerprint."
                );
            }

            $html = $this->resolve_page_links(
                (string) ($renderedpage['html'] ?? ''),
                $targetids
            );
            $page = $pageobjects[$sourceid];
            $current = wiki_get_current_version((int) $page->id);
            $performed = 'UNCHANGED';

            if (!$current || (string) $current->content !== $html) {
                $saved = wiki_save_page($page, $html, (int) $USER->id);
                if ($saved === false) {
                    throw new \coding_exception("Moodle refused Wiki page save for {$sourceid}.");
                }
                $performed = $requestedactions[$sourceid] === 'CREATE'
                    ? 'CREATED'
                    : 'UPDATED';
            }

            $finalversion = wiki_get_current_version((int) $page->id);
            if (!$finalversion || (string) $finalversion->content !== $html) {
                throw new \coding_exception(
                    "Wiki page {$sourceid} current content does not match the migration output."
                );
            }

            $results[] = [
                'source_id' => $sourceid,
                'title' => (string) ($pageplan['title'] ?? ''),
                'mapping_ref' => (string) ($pageplan['mapping_ref'] ?? ''),
                'requested_action' => $requestedactions[$sourceid],
                'action' => $performed,
                'target_page_id' => (int) $page->id,
                'html_sha256' => hash('sha256', $html),
                'version' => (int) $finalversion->version,
            ];
        }

        return $results;
    }

    /** Resolve deterministic dry-run Wiki-page anchors after all target ids exist. */
    private function resolve_page_links(string $html, array $targetids): string {
        global $CFG;

        return (string) preg_replace_callback(
            '/href="#ilias-wiki-page-([0-9]+)"/',
            static function(array $matches) use ($targetids, $CFG): string {
                $sourceid = (string) ($matches[1] ?? '');
                $targetid = (int) ($targetids[$sourceid] ?? 0);
                if ($targetid <= 0) {
                    throw new \coding_exception(
                        "No Moodle Wiki page mapping exists for internal source page {$sourceid}."
                    );
                }
                $url = $CFG->wwwroot . '/mod/wiki/view.php?pageid=' . $targetid;
                return 'href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            },
            $html
        );
    }

    /**
     * Replace only files under the source-owned /wikis/<ref>/ namespace.
     *
     * Wiki attachments are shared by all pages in one subwiki. The nested
     * normalized paths prevent collisions while preserving @@PLUGINFILE@@ URLs.
     */
    private function reconcile_assets(
        \context_module $context,
        int $subwikiid,
        string $wikiref,
        array $assets
    ): array {
        $fs = get_file_storage();
        $prefix = '/wikis/' . $wikiref . '/';

        foreach ($fs->get_area_files(
            $context->id,
            'mod_wiki',
            'attachments',
            $subwikiid,
            'id',
            false
        ) as $existing) {
            if (str_starts_with((string) $existing->get_filepath(), $prefix)) {
                $existing->delete();
            }
        }

        $unique = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $migrationpath = trim((string) ($asset['migration_path'] ?? ''));
            if ($migrationpath !== '') {
                $unique[$migrationpath] = true;
            }
        }

        $checks = [];
        foreach (array_keys($unique) as $migrationpath) {
            $source = $this->resolve_relative_file($migrationpath);
            [$filepath, $filename] = $this->migration_destination($migrationpath);

            $created = $fs->create_file_from_pathname(
                [
                    'contextid' => $context->id,
                    'component' => 'mod_wiki',
                    'filearea' => 'attachments',
                    'itemid' => $subwikiid,
                    'filepath' => $filepath,
                    'filename' => $filename,
                ],
                $source
            );

            $verification = $this->verify_storage_policy($created, $source, $filename);
            $checks[] = [
                'migration_path' => $migrationpath,
                'filepath' => $filepath,
                'filename' => $filename,
                'source_size' => filesize($source),
                'source_sha1' => sha1_file($source),
                'stored_size' => (int) $created->get_filesize(),
                'stored_sha1' => (string) $created->get_contenthash(),
                'verification_mode' => $verification['mode'],
                'verified' => true,
            ];
        }

        $storedfiles = array_values(array_filter(
            $fs->get_area_files(
                $context->id,
                'mod_wiki',
                'attachments',
                $subwikiid,
                'id',
                false
            ),
            static fn(\stored_file $file): bool =>
                str_starts_with((string) $file->get_filepath(), $prefix)
        ));

        if (count($storedfiles) !== count($unique)) {
            throw new \coding_exception(
                'Wiki attachment reconciliation stored an unexpected number of files.'
            );
        }

        return [
            'expected_files' => count($unique),
            'stored_files' => count($storedfiles),
            'verified_files' => count($checks),
            'checks' => $checks,
            'ready' => true,
        ];
    }

    /**
     * Accept exact source bytes or the exact output of Moodle's active redactor.
     */
    private function verify_storage_policy(
        \stored_file $stored,
        string $source,
        string $filename
    ): array {
        $sourcehash = sha1_file($source);
        $sourcesize = filesize($source);
        $storedhash = (string) $stored->get_contenthash();
        $storedsize = (int) $stored->get_filesize();

        if ($sourcehash !== false
                && $sourcesize !== false
                && hash_equals($sourcehash, $storedhash)
                && (int) $sourcesize === $storedsize) {
            return ['mode' => 'source'];
        }

        if (class_exists('\\core_files\\redactor\\manager')
                && class_exists('\\core\\di')) {
            $mimetype = \file_storage::mimetype($source, $filename);
            $manager = \core\di::get(\core_files\redactor\manager::class);
            $redacted = $manager->redact_file($mimetype, $source);

            if ($redacted !== null && is_file($redacted)) {
                $redactedhash = sha1_file($redacted);
                $redactedsize = filesize($redacted);
                if ($redactedhash !== false
                        && $redactedsize !== false
                        && hash_equals($redactedhash, $storedhash)
                        && (int) $redactedsize === $storedsize) {
                    return ['mode' => 'moodle_redactor'];
                }
            }
        }

        throw new \coding_exception(
            'Wiki attachment matches neither the package source nor Moodle redaction output.'
        );
    }

    /** Convert wikis/<ref>/.../file.ext to a safe Moodle file path. */
    private function migration_destination(string $migrationpath): array {
        $relative = trim(str_replace('\\', '/', $migrationpath), '/');
        if ($relative === '' || str_contains($relative, '../')) {
            throw new \coding_exception('Unsafe Wiki migration asset path.');
        }

        $parts = array_values(array_filter(
            explode('/', $relative),
            static fn(string $part): bool => $part !== ''
        ));
        if (count($parts) < 2) {
            throw new \coding_exception('Wiki migration asset path has no filename.');
        }

        $filename = array_pop($parts);
        foreach (array_merge($parts, [$filename]) as $part) {
            if ($part === '.' || $part === '..' || str_contains($part, "\0")) {
                throw new \coding_exception('Unsafe Wiki migration path component.');
            }
        }

        return ['/' . implode('/', $parts) . '/', $filename];
    }

    private function load_structure(array $operation): array {
        $path = $this->resolve_relative_file(
            (string) ($operation['migration_structure_path'] ?? '')
        );
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \coding_exception('Unable to read Wiki structure.json.');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \coding_exception(
                'Invalid Wiki structure.json: ' . $exception->getMessage()
            );
        }

        if (!is_array($decoded)) {
            throw new \coding_exception('Wiki structure.json must contain a JSON object.');
        }
        return $decoded;
    }

    private function intro_editor(string $text): array {
        return [
            'text' => $text,
            'format' => FORMAT_HTML,
            'itemid' => file_get_unused_draft_itemid(),
        ];
    }

    /** Resolve target section through the same Phase 3 policy as other activities. */
    private function resolve_section_number(
        \stdClass $course,
        array $operation,
        string $sourcecourseid
    ): int {
        global $DB;

        $parentref = (string) ($operation['parent_source_ref_id'] ?? '');
        $expected = $this->parent_section_number($course, $parentref, $sourcecourseid);
        if ($parentref !== '' || (string) ($operation['action'] ?? '') !== 'UPDATE') {
            return $expected;
        }

        $targetid = (int) ($operation['target_id'] ?? 0);
        if ($targetid <= 0) {
            return $expected;
        }

        $cm = $DB->get_record(
            'course_modules',
            ['id' => $targetid, 'course' => (int) $course->id],
            'id,section',
            MUST_EXIST
        );
        $section = $DB->get_record(
            'course_sections',
            ['id' => (int) $cm->section, 'course' => (int) $course->id],
            'id,section',
            MUST_EXIST
        );

        return phase3_section_policy::effective_update_section(
            $parentref,
            $expected,
            (int) $section->section,
            $this->is_owned_synthetic_section($sourcecourseid, (int) $section->id)
        );
    }

    private function parent_section_number(
        \stdClass $course,
        string $parentref,
        string $sourcecourseid
    ): int {
        global $DB;

        if ($parentref === '') {
            return 0;
        }

        $sectionmapping = $this->find_mapping($sourcecourseid, $parentref, 'section');
        if ($sectionmapping) {
            $section = $DB->get_record(
                'course_sections',
                ['id' => (int) $sectionmapping->targetid, 'course' => (int) $course->id],
                'id,section',
                MUST_EXIST
            );
            return (int) $section->section;
        }

        $subsectionmapping = $this->find_mapping($sourcecourseid, $parentref, 'subsection');
        if ($subsectionmapping) {
            $cm = get_coursemodule_from_id(
                'subsection',
                (int) $subsectionmapping->targetid,
                (int) $course->id,
                false,
                MUST_EXIST
            );
            $delegated = $DB->get_record(
                'course_sections',
                [
                    'course' => (int) $course->id,
                    'component' => 'mod_subsection',
                    'itemid' => (int) $cm->instance,
                ],
                'id,section',
                MUST_EXIST
            );
            return (int) $delegated->section;
        }

        throw new \coding_exception(
            "No Moodle section mapping exists for ILIAS parent ref_id {$parentref}."
        );
    }

    private function assert_same_section(
        \stdClass $cm,
        \stdClass $course,
        int $expectedsectionnumber
    ): void {
        global $DB;

        $section = $DB->get_record(
            'course_sections',
            ['id' => (int) $cm->section, 'course' => (int) $course->id],
            'id,section',
            MUST_EXIST
        );
        if ((int) $section->section !== $expectedsectionnumber) {
            throw new \coding_exception(
                'Moving an existing Wiki to another Moodle section is not supported.'
            );
        }
    }

    private function is_owned_synthetic_section(string $sourcecourse, int $sectionid): bool {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $sourcecourse,
            'targettype' => 'synthetic_section',
            'targetid' => $sectionid,
        ];
        if ($DB->record_exists('local_iliasmigration_map', $conditions)) {
            return true;
        }
        if ($this->sourceinstance === '') {
            return false;
        }
        $conditions['sourceinstance'] = '';
        return $DB->record_exists('local_iliasmigration_map', $conditions);
    }

    private function find_mapping(
        string $sourcecourse,
        string $sourceref,
        string $targettype
    ): \stdClass|false {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => $targettype,
        ];
        $mapping = $DB->get_record('local_iliasmigration_map', $conditions);
        if ($mapping) {
            return $mapping;
        }
        if ($this->sourceinstance === '') {
            return false;
        }
        $conditions['sourceinstance'] = '';
        return $DB->get_record('local_iliasmigration_map', $conditions);
    }

    /** Persist parent Wiki or individual Wiki page mapping. */
    private function save_mapping(
        string $sourcecourse,
        string $sourceref,
        string $sourceobj,
        string $targettype,
        int $targetid,
        string $sourceversion
    ): void {
        global $DB;

        if ($sourceref === '') {
            throw new \coding_exception('Wiki mapping source reference cannot be empty.');
        }

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => $targettype,
        ];
        $existing = $DB->get_record('local_iliasmigration_map', $conditions);
        if (!$existing && $this->sourceinstance !== '') {
            $legacy = $conditions;
            $legacy['sourceinstance'] = '';
            $existing = $DB->get_record('local_iliasmigration_map', $legacy);
        }

        $now = time();
        $record = (object) ($conditions + [
            'sourceversion' => $sourceversion !== '' ? $sourceversion : null,
            'sourceobj' => $sourceobj !== '' ? $sourceobj : null,
            'targetid' => $targetid,
            'status' => 'READY',
            'timemodified' => $now,
        ]);

        if ($existing) {
            $record->id = (int) $existing->id;
            $record->timecreated = (int) $existing->timecreated;
            $DB->update_record('local_iliasmigration_map', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('local_iliasmigration_map', $record);
        }
    }

    private function resolve_relative_file(string $relative): string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === ''
                || str_starts_with($relative, '/')
                || str_contains($relative, '../')) {
            throw new \coding_exception('Unsafe Wiki package-relative path.');
        }

        $candidate = realpath(
            $this->packageroot . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative)
        );
        if ($candidate === false || !is_file($candidate)) {
            throw new \coding_exception('Validated Wiki package file is missing.');
        }
        if (!str_starts_with($candidate, $this->packageroot . DIRECTORY_SEPARATOR)) {
            throw new \coding_exception('Wiki package path escapes the package root.');
        }
        return $candidate;
    }
}

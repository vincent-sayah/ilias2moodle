<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Execute guarded ILIAS Glossary -> Moodle mod_glossary writes.
 *
 * The executor rebuilds the same validated dry-run plan immediately before
 * writing, checks the aggregate and per-term HTML fingerprints, persists the
 * parent Glossary mapping and a stable mapping for every imported term.
 */
final class phase65_glossary_executor {
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
     * Create/update every validated Glossary operation.
     */
    public function execute(array $document, int $categoryid): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/glossary/lib.php');
        require_once($CFG->libdir . '/filelib.php');

        $plan = $this->validated_plan($document, $categoryid);
        $this->assert_applyable($plan);

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourseid = (string) ($document['course']['source_id'] ?? '');
        $sourceversion = (string) ($document['source']['version'] ?? '');

        $courseoperation = $plan['operations'][0] ?? null;
        if (!is_array($courseoperation) || ($courseoperation['kind'] ?? '') !== 'course') {
            throw new \coding_exception('Glossary plan does not contain the Moodle course operation.');
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
                    if ((string) ($operation['kind'] ?? '') !== 'glossary') {
                        $results[] = $operation;
                        continue;
                    }

                    $sectionnumber = $this->resolve_section_number(
                        $course,
                        $operation,
                        $sourcecourseid
                    );
                    $results[] = $this->apply_glossary(
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
        $plan['phase'] = '6.5';
        $plan['phase65_object'] = 'glossary';
        $plan['course']['target_id'] = (int) $course->id;
        $plan['operations'] = $results;
        $plan['warnings'][] = [
            'code' => 'COURSE_REMAINS_HIDDEN',
            'message' => 'The imported course remains hidden until the Glossary POC is visually validated.',
        ];
        return $plan;
    }

    /** Build exactly the same chain used by the dedicated Glossary dry-run. */
    private function validated_plan(array $document, int $categoryid): array {
        $plan = (new phase6_plan_builder($categoryid))->build($document);
        $plan['phase'] = '6.5';
        $plan = (new phase3_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase4_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase5_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase6_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase6_scoring_policy_validator($this->migrationjson))->validate($plan);
        return (new phase65_glossary_apply_validator($this->migrationjson))->validate($plan);
    }

    /** Refuse writes unless the dedicated Glossary contract is fully ready. */
    private function assert_applyable(array $plan): void {
        $package = is_array($plan['phase65_glossary_package'] ?? null)
            ? $plan['phase65_glossary_package']
            : [];
        if (empty($package['ready'])
                || empty($package['apply_implemented'])
                || empty($package['apply_ready'])
                || !empty($package['blocked_glossaries'])
                || !empty($package['entry_blocked_count'])) {
            throw new \coding_exception('Glossary package validation failed; apply is refused.');
        }

        foreach ($plan['operations'] as $operation) {
            if ((string) ($operation['kind'] ?? '') !== 'glossary') {
                continue;
            }
            $action = (string) ($operation['action'] ?? '');
            if (!in_array($action, ['CREATE', 'UPDATE'], true)) {
                throw new \coding_exception("Cannot apply Glossary with planned action {$action}.");
            }
            $validation = is_array($operation['glossary_validation'] ?? null)
                ? $operation['glossary_validation']
                : [];
            if ((string) ($validation['status'] ?? '') !== 'OK'
                    || (string) ($validation['code'] ?? '') !== 'GLOSSARY_READY') {
                throw new \coding_exception('Glossary validation is not ready for apply.');
            }
            foreach ((array) ($validation['terms'] ?? []) as $term) {
                if (!is_array($term)
                        || !in_array((string) ($term['action'] ?? ''), ['CREATE', 'UPDATE'], true)) {
                    throw new \coding_exception('At least one Glossary entry has no approved CREATE/UPDATE action.');
                }
            }
        }
    }

    /** Create/update one mod_glossary and all of its validated entries. */
    private function apply_glossary(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion,
        int $sectionnumber
    ): array {
        global $CFG, $DB;

        $requested = (string) ($operation['action'] ?? '');
        $structure = $this->load_structure($operation);
        $render = (new phase65_glossary_renderer())->render($structure);

        $expectedfingerprint = (string) (
            $operation['glossary_validation']['fingerprint_sha256'] ?? ''
        );
        $actualfingerprint = (string) ($render['fingerprint_sha256'] ?? '');
        if ($expectedfingerprint === '' || !hash_equals($expectedfingerprint, $actualfingerprint)) {
            throw new \coding_exception(
                'Glossary HTML differs from the approved dry-run fingerprint; apply is refused.'
            );
        }

        $description = (string) ($operation['description'] ?? '');
        if ($requested === 'CREATE') {
            $moduledata = (object) [
                'modulename' => 'glossary',
                'course' => (int) $course->id,
                'section' => $sectionnumber,
                'visible' => 1,
                'name' => (string) ($operation['title'] ?? ''),
                'introeditor' => $this->intro_editor($description),
                'intro' => $description,
                'introformat' => FORMAT_HTML,
                'globalglossary' => 0,
                'mainglossary' => 0,
                'defaultapproval' => 1,
                'editalways' => 0,
                'allowduplicatedentries' => 0,
                'allowcomments' => 0,
                'usedynalink' => 0,
                'displayformat' => 'dictionary',
                'approvaldisplayformat' => 'default',
                'entbypage' => 10,
                'showalphabet' => 1,
                'showall' => 1,
                'showspecial' => 1,
                'allowprintview' => 1,
                'rsstype' => 0,
                'rssarticles' => 0,
                'assessed' => 0,
                'completionentries' => 0,
            ];
            $created = create_module($moduledata);
            $cmid = (int) $created->coursemodule;
            $instanceid = (int) $created->instance;
            $performed = 'CREATED';
        } else {
            $cmid = (int) ($operation['target_id'] ?? 0);
            $cm = get_coursemodule_from_id('glossary', $cmid, $course->id, false, MUST_EXIST);
            $this->assert_same_section($cm, $course, $sectionnumber);

            [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
            $moduleinfo->name = (string) ($operation['title'] ?? '');
            $moduleinfo->introeditor = $this->intro_editor($description);
            update_module($moduleinfo);
            $instanceid = (int) $cm->instance;
            $performed = 'UPDATED';
        }

        $cm = get_coursemodule_from_id('glossary', $cmid, $course->id, false, MUST_EXIST);
        $glossary = $DB->get_record('glossary', ['id' => $instanceid], '*', MUST_EXIST);
        $context = \context_module::instance($cmid);

        $entryresults = $this->apply_entries(
            $course,
            $cm,
            $glossary,
            $context,
            $operation,
            $render,
            $sourcecourseid,
            $sourceversion
        );

        $this->save_mapping(
            $sourcecourseid,
            (string) ($operation['source_ref_id'] ?? ''),
            (string) ($operation['source_obj_id'] ?? ''),
            'glossary',
            $cmid,
            $sourceversion
        );

        $result = $operation;
        $result['requested_action'] = $requested;
        $result['action'] = $performed;
        $result['target_id'] = $cmid;
        $result['instance_id'] = $instanceid;
        $result['moodle_section'] = $sectionnumber;
        $result['moodle_entry_count'] = count($entryresults);
        $result['moodle_file_count'] = array_sum(array_map(
            static fn(array $entry): int => (int) ($entry['file_count'] ?? 0),
            $entryresults
        ));
        $result['fingerprint_sha256'] = $actualfingerprint;
        $result['entries'] = $entryresults;
        return $result;
    }

    /** Create/update the validated Glossary entries. */
    private function apply_entries(
        \stdClass $course,
        \stdClass $cm,
        \stdClass $glossary,
        \context_module $context,
        array $operation,
        array $render,
        string $sourcecourseid,
        string $sourceversion
    ): array {
        global $DB;

        $plannedterms = [];
        foreach ((array) ($operation['glossary_validation']['terms'] ?? []) as $term) {
            if (is_array($term)) {
                $plannedterms[(string) ($term['source_id'] ?? '')] = $term;
            }
        }

        $assetsbyterm = [];
        foreach ((array) ($render['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $termid = (string) ($asset['term_id'] ?? '');
            $assetsbyterm[$termid][] = $asset;
        }

        $results = [];
        foreach ((array) ($render['terms'] ?? []) as $renderedterm) {
            if (!is_array($renderedterm)) {
                continue;
            }
            $termid = (string) ($renderedterm['source_id'] ?? '');
            $planned = $plannedterms[$termid] ?? null;
            if (!is_array($planned)) {
                throw new \coding_exception('Rendered Glossary term has no approved dry-run plan.');
            }

            $expectedhash = (string) ($planned['html_sha256'] ?? '');
            $actualhash = (string) ($renderedterm['html_sha256'] ?? '');
            if ($expectedhash === '' || !hash_equals($expectedhash, $actualhash)) {
                throw new \coding_exception(
                    'Glossary term HTML differs from the approved dry-run fingerprint.'
                );
            }

            $requested = (string) ($planned['action'] ?? '');
            $entry = null;
            if ($requested === 'UPDATE') {
                $entryid = (int) ($planned['target_entry_id'] ?? 0);
                $entry = $DB->get_record(
                    'glossary_entries',
                    ['id' => $entryid, 'glossaryid' => (int) $glossary->id],
                    '*',
                    MUST_EXIST
                );
            } else if ($requested === 'CREATE') {
                $entry = new \stdClass();
                $entry->id = null;
                $entry->attachment = '';
            } else {
                throw new \coding_exception("Unsupported Glossary entry action {$requested}.");
            }

            $draft = $this->build_entry_draft($assetsbyterm[$termid] ?? []);
            $entry->concept = (string) ($renderedterm['term'] ?? '');
            $entry->aliases = '';
            $entry->categories = [];
            $entry->usedynalink = 0;
            $entry->casesensitive = 0;
            $entry->fullmatch = 1;
            $entry->definition_editor = [
                'text' => (string) ($renderedterm['html'] ?? ''),
                'format' => FORMAT_HTML,
                'itemid' => $draft['draft_item_id'],
            ];

            $saved = glossary_edit_entry($entry, $course, $cm, $glossary, $context);
            $performed = $requested === 'CREATE' ? 'CREATED' : 'UPDATED';

            $entryref = (string) ($planned['mapping_ref'] ?? '');
            if ($entryref === '') {
                throw new \coding_exception('Glossary entry mapping reference is missing.');
            }
            $this->save_mapping(
                $sourcecourseid,
                $entryref,
                $termid,
                'glossary_entry',
                (int) $saved->id,
                $sourceversion
            );

            $results[] = [
                'source_id' => $termid,
                'term' => (string) ($renderedterm['term'] ?? ''),
                'requested_action' => $requested,
                'action' => $performed,
                'target_entry_id' => (int) $saved->id,
                'html_bytes' => (int) ($renderedterm['html_bytes'] ?? 0),
                'html_sha256' => $actualhash,
                'file_count' => $draft['file_count'],
                'mapping_ref' => $entryref,
            ];
        }

        return $results;
    }

    /** Create a user draft containing only the assets used by one term. */
    private function build_entry_draft(array $assets): array {
        global $USER;

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $copied = 0;

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $relative = trim((string) ($asset['migration_path'] ?? ''));
            if ($relative === '') {
                continue;
            }
            $source = $this->resolve_relative_file($relative);
            $draftrelative = ltrim(str_replace('\\', '/', $relative), '/');
            if ($draftrelative === '' || str_contains($draftrelative, '../')) {
                throw new \coding_exception('Unsafe Glossary draft path.');
            }
            $dirname = str_replace('\\', '/', dirname($draftrelative));
            $filepath = ($dirname === '.' || $dirname === '')
                ? '/'
                : '/' . trim($dirname, '/') . '/';

            $fs->create_file_from_pathname(
                [
                    'contextid' => $usercontext->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => $draftitemid,
                    'filepath' => $filepath,
                    'filename' => basename($draftrelative),
                ],
                $source
            );
            $copied++;
        }

        return ['draft_item_id' => $draftitemid, 'file_count' => $copied];
    }

    private function load_structure(array $operation): array {
        $path = $this->resolve_relative_file(
            (string) ($operation['migration_structure_path'] ?? '')
        );
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \coding_exception('Unable to read Glossary structure.json.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \coding_exception('Invalid Glossary structure.json: ' . $exception->getMessage());
        }
        if (!is_array($decoded)) {
            throw new \coding_exception('Glossary structure.json must contain a JSON object.');
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

    /** Resolve the Moodle section using the same policy as Content Page. */
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
                'Moving an existing Glossary to another Moodle section is not supported.'
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

    /** Persist parent Glossary or individual entry mapping. */
    private function save_mapping(
        string $sourcecourse,
        string $sourceref,
        string $sourceobj,
        string $targettype,
        int $targetid,
        string $sourceversion
    ): void {
        global $DB;

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
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../')) {
            throw new \coding_exception('Unsafe Glossary package-relative path.');
        }
        $candidate = realpath($this->packageroot . DIRECTORY_SEPARATOR . $relative);
        if ($candidate === false || !is_file($candidate)) {
            throw new \coding_exception('Validated Glossary package file is missing.');
        }
        if (!str_starts_with($candidate, $this->packageroot . DIRECTORY_SEPARATOR)) {
            throw new \coding_exception('Glossary package path escapes the package root.');
        }
        return $candidate;
    }
}

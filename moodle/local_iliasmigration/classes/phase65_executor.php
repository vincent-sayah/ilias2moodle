<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Execute Phase 6.5 ILIAS Content Page -> Moodle Page writes.
 *
 * The executor reuses the exact internal-link resolutions produced by the
 * validated dry-run and refuses to write if the rendered HTML fingerprint
 * differs from the approved validation fingerprint.
 */
final class phase65_executor {
    /** @var string Canonical migration package root. */
    private string $packageroot;

    /** @var string Absolute migration.json path. */
    private string $migrationjson;

    /** @var string Stable ILIAS source instance identity. */
    private string $sourceinstance = '';

    /**
     * @param string $migrationjson Absolute path to migration.json.
     */
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
     * Create/update all validated Content Page operations.
     */
    public function execute(array $document, int $categoryid): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/page/lib.php');
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        $plan = $this->validated_plan($document, $categoryid);
        $this->assert_applyable($plan);

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourseid = (string) ($document['course']['source_id'] ?? '');
        $sourceversion = (string) ($document['source']['version'] ?? '');

        $courseoperation = $plan['operations'][0] ?? null;
        if (!is_array($courseoperation) || ($courseoperation['kind'] ?? '') !== 'course') {
            throw new \coding_exception('Phase 6.5 plan does not contain the Moodle course operation.');
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
                    if ((string) ($operation['kind'] ?? '') !== 'content_page') {
                        $results[] = $operation;
                        continue;
                    }

                    $sectionnumber = $this->resolve_section_number(
                        $course,
                        $operation,
                        $sourcecourseid
                    );
                    $results[] = $this->apply_page(
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
        $plan['course']['target_id'] = (int) $course->id;
        $plan['operations'] = $results;
        $plan['warnings'][] = [
            'code' => 'COURSE_REMAINS_HIDDEN',
            'message' => 'The imported course remains hidden until the Content Page POC is validated.',
        ];
        return $plan;
    }

    /** Build the same complete validation chain as the Phase 6.5 dry-run. */
    private function validated_plan(array $document, int $categoryid): array {
        $plan = (new phase65_plan_builder($categoryid))->build($document);
        $plan = (new phase3_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase4_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase5_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase6_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase6_scoring_policy_validator($this->migrationjson))->validate($plan);
        return (new phase65_package_validator($this->migrationjson))->validate($plan);
    }

    /** Refuse writes unless the full dry-run contract is ready. */
    private function assert_applyable(array $plan): void {
        if (empty($plan['phase65_package']['ready'])
                || empty($plan['phase65_package']['apply_ready'])
                || !empty($plan['phase65_package']['blocked_pages'])) {
            throw new \coding_exception('Phase 6.5 package validation failed; apply is refused.');
        }

        foreach ($plan['operations'] as $operation) {
            if ((string) ($operation['kind'] ?? '') !== 'content_page') {
                continue;
            }
            $action = (string) ($operation['action'] ?? '');
            if (!in_array($action, ['CREATE', 'UPDATE'], true)) {
                throw new \coding_exception(
                    "Cannot apply Content Page with planned action {$action}."
                );
            }
            $validation = is_array($operation['content_page_validation'] ?? null)
                ? $operation['content_page_validation']
                : [];
            if ((string) ($validation['status'] ?? '') !== 'OK'
                    || (string) ($validation['code'] ?? '') !== 'CONTENT_PAGE_READY') {
                throw new \coding_exception('Content Page validation is not ready for apply.');
            }
        }
    }

    /** Create or update one mod_page from an approved operation. */
    private function apply_page(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion,
        int $sectionnumber
    ): array {
        global $DB;

        $requested = (string) ($operation['action'] ?? '');
        $structure = $this->load_structure($operation);
        $render = $this->render_from_approved_resolutions($structure, $operation);
        $html = (string) ($render['html'] ?? '');

        $expectedhash = (string) (
            $operation['content_page_validation']['html_sha256'] ?? ''
        );
        $actualhash = hash('sha256', $html);
        if ($expectedhash === '' || !hash_equals($expectedhash, $actualhash)) {
            throw new \coding_exception(
                'Content Page HTML differs from the approved dry-run fingerprint; apply is refused.'
            );
        }

        $draft = $this->build_page_draft($structure);
        $description = (string) ($operation['description'] ?? '');
        $config = get_config('page');
        $display = isset($config->display) ? (int) $config->display : RESOURCELIB_DISPLAY_OPEN;
        $popupwidth = isset($config->popupwidth) ? (int) $config->popupwidth : 620;
        $popupheight = isset($config->popupheight) ? (int) $config->popupheight : 450;
        $printintro = !empty($config->printintro) ? 1 : 0;
        $printlastmodified = !empty($config->printlastmodified) ? 1 : 0;

        if ($requested === 'CREATE') {
            $moduledata = (object) [
                'modulename' => 'page',
                'course' => (int) $course->id,
                'section' => $sectionnumber,
                'visible' => 1,
                'name' => (string) ($operation['title'] ?? ''),
                'introeditor' => $this->intro_editor($description),
                'intro' => $description,
                'introformat' => FORMAT_HTML,
                'content' => $html,
                'contentformat' => FORMAT_HTML,
                'display' => $display,
                'popupwidth' => $popupwidth,
                'popupheight' => $popupheight,
                'printintro' => $printintro,
                'printlastmodified' => $printlastmodified,
                'legacyfiles' => RESOURCELIB_LEGACYFILES_NO,
            ];

            $created = create_module($moduledata);
            $cmid = (int) $created->coursemodule;
            $instanceid = (int) $created->instance;
            $context = \context_module::instance($cmid);
            $savedhtml = file_save_draft_area_files(
                $draft['draft_item_id'],
                $context->id,
                'mod_page',
                'content',
                0,
                page_get_editor_options($context),
                $html
            );
            $DB->set_field('page', 'content', $savedhtml, ['id' => $instanceid]);
            $DB->set_field('page', 'contentformat', FORMAT_HTML, ['id' => $instanceid]);
            $performed = 'CREATED';
        } else {
            $cmid = (int) ($operation['target_id'] ?? 0);
            $cm = get_coursemodule_from_id('page', $cmid, $course->id, false, MUST_EXIST);
            $this->assert_same_section($cm, $course, $sectionnumber);

            [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
            $moduleinfo->name = (string) ($operation['title'] ?? '');
            $moduleinfo->introeditor = $this->intro_editor($description);
            $moduleinfo->page = [
                'text' => $html,
                'format' => FORMAT_HTML,
                'itemid' => $draft['draft_item_id'],
            ];
            $moduleinfo->display = $display;
            $moduleinfo->popupwidth = $popupwidth;
            $moduleinfo->popupheight = $popupheight;
            $moduleinfo->printintro = $printintro;
            $moduleinfo->printlastmodified = $printlastmodified;
            update_module($moduleinfo);
            $instanceid = (int) $cm->instance;
            $performed = 'UPDATED';
        }

        $this->save_mapping(
            $sourcecourseid,
            (string) ($operation['source_ref_id'] ?? ''),
            (string) ($operation['source_obj_id'] ?? ''),
            $cmid,
            $sourceversion
        );

        $result = $operation;
        $result['requested_action'] = $requested;
        $result['action'] = $performed;
        $result['target_id'] = $cmid;
        $result['instance_id'] = $instanceid;
        $result['moodle_section'] = $sectionnumber;
        $result['moodle_file_count'] = $draft['file_count'];
        $result['html_bytes'] = strlen($html);
        $result['html_sha256'] = $actualhash;
        $result['internal_link_resolutions'] = $render['internal_link_resolutions'] ?? [];
        return $result;
    }

    /** Render with only the link decisions already approved by validation. */
    private function render_from_approved_resolutions(array $structure, array $operation): array {
        $approved = is_array(
            $operation['content_page_validation']['internal_link_resolutions'] ?? null
        ) ? $operation['content_page_validation']['internal_link_resolutions'] : [];

        $renderer = new phase65_content_renderer();
        return $renderer->render(
            $structure,
            static function(array $link) use ($approved): array {
                $target = (string) ($link['target'] ?? '');
                $refid = (string) ($link['source_ref_id'] ?? '');
                foreach ($approved as $resolution) {
                    if (!is_array($resolution)) {
                        continue;
                    }
                    if ((string) ($resolution['target'] ?? '') === $target
                            && (string) ($resolution['source_ref_id'] ?? '') === $refid) {
                        return $resolution;
                    }
                }
                throw new \coding_exception(
                    'No approved dry-run resolution exists for a Content Page internal link.'
                );
            }
        );
    }

    /** Load one validated structure.json document. */
    private function load_structure(array $operation): array {
        $path = $this->resolve_relative_file(
            (string) ($operation['migration_structure_path'] ?? '')
        );
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \coding_exception('Unable to read Content Page structure.json.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \coding_exception(
                'Invalid Content Page structure.json: ' . $exception->getMessage()
            );
        }
        if (!is_array($decoded)) {
            throw new \coding_exception('Content Page structure.json must contain a JSON object.');
        }
        return $decoded;
    }

    /** Build a Moodle draft containing all six normalized POC assets. */
    private function build_page_draft(array $structure): array {
        global $USER;

        $sourceref = (string) (($structure['source'] ?? [])['ref_id'] ?? '');
        $prefix = $sourceref !== '' ? 'content_pages/' . $sourceref . '/' : '';
        $paths = $this->collect_asset_paths($structure);
        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();

        foreach ($paths as $relative) {
            $source = $this->resolve_relative_file($relative);
            $draftrelative = ltrim($relative, '/');
            if ($prefix !== '' && str_starts_with($draftrelative, $prefix)) {
                $draftrelative = substr($draftrelative, strlen($prefix));
            }
            if ($draftrelative === '' || str_contains($draftrelative, '../')) {
                throw new \coding_exception('Unsafe Content Page draft path.');
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
        }

        return [
            'draft_item_id' => $draftitemid,
            'file_count' => count($paths),
        ];
    }

    /** Collect media and embedded-file migration paths. */
    private function collect_asset_paths(array $structure): array {
        $paths = [];
        foreach ((array) ($structure['media'] ?? []) as $mediaobject) {
            if (!is_array($mediaobject)) {
                continue;
            }
            foreach ((array) ($mediaobject['items'] ?? []) as $item) {
                if (is_array($item) && !empty($item['migration_path'])) {
                    $paths[] = (string) $item['migration_path'];
                }
            }
        }
        foreach ((array) ($structure['files'] ?? []) as $file) {
            if (is_array($file) && !empty($file['migration_path'])) {
                $paths[] = (string) $file['migration_path'];
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** Standard intro editor payload. */
    private function intro_editor(string $text): array {
        return [
            'text' => $text,
            'format' => FORMAT_HTML,
            'itemid' => file_get_unused_draft_itemid(),
        ];
    }

    /** Resolve the Moodle section for a root or parented Content Page. */
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

    /** Resolve an ILIAS folder/subsection mapping to its target section number. */
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

    /** Refuse to move an already mapped Page during UPDATE. */
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
                'Moving an existing Content Page to another Moodle section is not supported.'
            );
        }
    }

    /** Test whether a current root section is migration-owned synthetic placement. */
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

    /** Find a current or legacy mapping. */
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
        $conditions['sourceinstance'] = '';
        return $DB->get_record('local_iliasmigration_map', $conditions);
    }

    /** Persist the Content Page -> mod_page CMID mapping. */
    private function save_mapping(
        string $sourcecourse,
        string $sourceref,
        string $sourceobj,
        int $targetid,
        string $sourceversion
    ): void {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => 'page',
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

    /** Resolve one safe package-relative file. */
    private function resolve_relative_file(string $relative): string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../')) {
            throw new \coding_exception('Unsafe Content Page package-relative path.');
        }
        $candidate = realpath($this->packageroot . DIRECTORY_SEPARATOR . $relative);
        if ($candidate === false || !is_file($candidate)) {
            throw new \coding_exception('Validated Content Page package file is missing.');
        }
        if (!str_starts_with($candidate, $this->packageroot . DIRECTORY_SEPARATOR)) {
            throw new \coding_exception('Content Page package path escapes the package root.');
        }
        return $candidate;
    }
}

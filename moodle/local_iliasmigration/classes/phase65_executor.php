<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Execute Phase 6.5 ILIAS Content Page -> Moodle Page writes.
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
     * Create or update Content Pages as mod_page activities.
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
            throw new \coding_exception('Phase 6.5 execution plan does not contain a Moodle course operation.');
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

                    $sectionnumber = $this->resolve_operation_section_number(
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
        $plan['phase65_package']['ready'] = true;
        $plan['phase65_package']['apply_implemented'] = true;
        $plan['phase65_package']['apply_ready'] = true;
        $plan['warnings'][] = [
            'code' => 'COURSE_REMAINS_HIDDEN',
            'message' => 'The imported course remains hidden until the extended-object POC is validated.',
        ];

        return $plan;
    }

    /** Build the same fully validated plan used by the Phase 6.5 dry-run. */
    private function validated_plan(array $document, int $categoryid): array {
        $plan = (new phase65_plan_builder($categoryid))->build($document);
        $plan = (new phase3_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase4_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase5_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase6_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase6_scoring_policy_validator($this->migrationjson))->validate($plan);
        return (new phase65_package_validator($this->migrationjson))->validate($plan);
    }

    /** Refuse writes unless the exact validated dry-run contract is ready. */
    private function assert_applyable(array $plan): void {
        if (empty($plan['phase65_package']['ready'])
                || empty($plan['phase65_package']['apply_ready'])
                || !empty($plan['phase65_package']['blocked_pages'])) {
            throw new \coding_exception('Phase 6.5 package validation failed; apply is refused.');
        }

        foreach ($plan['operations'] as $operation) {
            $kind = (string) ($operation['kind'] ?? '');
            $action = (string) ($operation['action'] ?? '');

            if ($kind === 'content_page') {
                if (!in_array($action, ['CREATE', 'UPDATE'], true)) {
                    throw new \coding_exception(
                        "Cannot apply Phase 6.5 Content Page: planned action is {$action}."
                    );
                }
                continue;
            }

            if (in_array(
                $kind,
                [
                    'course', 'section', 'subsection', 'file', 'url', 'html_module',
                    'scorm', 'learning_module', 'question_pool', 'test',
                ],
                true
            ) && $action !== 'UPDATE') {
                throw new \coding_exception(
                    "Phase 6.5 requires earlier object {$kind} to be synchronized first; action is {$action}."
                );
            }

            if (in_array(
                $action,
                ['BLOCKED', 'FLATTEN_REQUIRED', 'ERROR_STALE_MAPPING', 'ERROR_MAPPING_TYPE', 'CONFLICT'],
                true
            )) {
                throw new \coding_exception(
                    "Cannot apply Phase 6.5 while operation {$kind} is {$action}."
                );
            }
        }
    }

    /** Create/update one Moodle Page. */
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
        $render = $this->render_structure($structure, $sourcecourseid, (int) $course->id);
        $html = (string) ($render['html'] ?? '');
        if (trim($html) === '') {
            throw new \coding_exception('Content Page rendered empty HTML during apply.');
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
                'name' => (string) $operation['title'],
                'introeditor' => $this->build_intro_editor($description),
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
            $this->assert_module_section($cm, $course, $sectionnumber);

            [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
            $moduleinfo->name = (string) $operation['title'];
            $moduleinfo->introeditor = $this->build_intro_editor($description);
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
            'page',
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
        $result['html_sha256'] = hash('sha256', $html);
        $result['internal_link_resolutions'] = $render['internal_link_resolutions'] ?? [];

        return $result;
    }

    /** Build a standard editor draft payload for the activity description. */
    private function build_intro_editor(string $text): array {
        return [
            'text' => $text,
            'format' => FORMAT_HTML,
            'itemid' => file_get_unused_draft_itemid(),
        ];
    }

    /** Load and verify the normalized Content Page structure. */
    private function load_structure(array $operation): array {
        $relative = (string) ($operation['migration_structure_path'] ?? '');
        $path = $this->resolve_relative_file($relative);
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \coding_exception('Unable to read Content Page structure.json during apply.');
        }
        try {
            $structure = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \coding_exception(
                'Invalid Content Page structure.json during apply: ' . $exception->getMessage()
            );
        }
        if (!is_array($structure)) {
            throw new \coding_exception('Content Page structure.json must contain a JSON object.');
        }
        return $structure;
    }

    /** Render using the same mapping semantics as the dry-run validator. */
    private function render_structure(array $structure, string $sourcecourse, int $targetcourseid): array {
        $renderer = new phase65_content_renderer();
        return $renderer->render(
            $structure,
            function(array $link) use ($sourcecourse, $targetcourseid): array {
                return $this->resolve_internal_link($link, $sourcecourse, $targetcourseid);
            }
        );
    }

    /** Build one Moodle user draft area containing every Page asset. */
    private function build_page_draft(array $structure): array {
        global $USER;

        $sourceref = (string) (($structure['source'] ?? [])['ref_id'] ?? '');
        $prefix = $sourceref !== '' ? 'content_pages/' . $sourceref . '/' : '';
        $paths = $this->collect_asset_paths($structure);
        if (!$paths) {
            return [
                'draft_item_id' => file_get_unused_draft_itemid(),
                'file_count' => 0,
            ];
        }

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
                throw new \coding_exception('Unsafe Content Page draft relative path.');
            }

            $dirname = str_replace('\\', '/', dirname($draftrelative));
            $filepath = ($dirname === '.' || $dirname === '')
                ? '/'
                : '/' . trim($dirname, '/') . '/';
            $filename = basename($draftrelative);

            $fs->create_file_from_pathname(
                [
                    'contextid' => $usercontext->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => $draftitemid,
                    'filepath' => $filepath,
                    'filename' => $filename,
                ],
                $source
            );
        }

        return [
            'draft_item_id' => $draftitemid,
            'file_count' => count($paths),
        ];
    }

    /** Collect normalized media/file paths in deterministic order. */
    private function collect_asset_paths(array $structure): array {
        $paths = [];
        foreach ((array) ($structure['media'] ?? []) as $mediaobject) {
            if (!is_array($mediaobject)) {
                continue;
            }
            foreach ((array) ($mediaobject['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $path = trim((string) ($item['migration_path'] ?? ''));
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        }
        foreach ((array) ($structure['files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }
            $path = trim((string) ($file['migration_path'] ?? ''));
            if ($path !== '') {
                $paths[] = $path;
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** Resolve one ILIAS internal link to a unique existing Moodle target. */
    private function resolve_internal_link(array $link, string $sourcecourse, int $targetcourseid): array {
        global $DB;

        $targettype = trim((string) ($link['target_type'] ?? ''));
        $sourceref = trim((string) ($link['source_ref_id'] ?? ''));
        $fallback = $this->source_fallback_url($targettype, $sourceref);

        if ($sourceref === '') {
            return [
                'status' => 'PRESERVED',
                'reason' => 'INVALID_TARGET',
                'candidate_count' => 0,
                'url' => $fallback,
                'fallback_url' => $fallback,
            ];
        }

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
        ];
        $mappings = $DB->get_records('local_iliasmigration_map', $conditions);
        if (!$mappings && $this->sourceinstance !== '') {
            $conditions['sourceinstance'] = '';
            $mappings = $DB->get_records('local_iliasmigration_map', $conditions);
        }

        $candidates = [];
        foreach ($mappings as $mapping) {
            $candidate = $this->mapping_candidate($mapping, $targetcourseid);
            if ($candidate !== null) {
                $candidates[$candidate['url']] = $candidate;
            }
        }
        $candidates = array_values($candidates);

        if (count($candidates) === 1) {
            $candidate = $candidates[0];
            return [
                'status' => 'REWRITTEN',
                'reason' => null,
                'candidate_count' => 1,
                'url' => (string) $candidate['url'],
                'fallback_url' => $fallback,
                'moodle_target_type' => (string) $candidate['target_type'],
                'moodle_target_id' => (int) $candidate['target_id'],
                'moodle_module' => (string) ($candidate['module'] ?? ''),
                'target' => (string) ($link['target'] ?? ''),
                'target_type' => $targettype,
                'source_ref_id' => $sourceref,
            ];
        }

        return [
            'status' => 'PRESERVED',
            'reason' => count($candidates) > 1 ? 'AMBIGUOUS_TARGET' : 'TARGET_NOT_MIGRATED',
            'candidate_count' => count($candidates),
            'url' => $fallback,
            'fallback_url' => $fallback,
            'target' => (string) ($link['target'] ?? ''),
            'target_type' => $targettype,
            'source_ref_id' => $sourceref,
        ];
    }

    /** Verify one mapping and convert it to a navigation URL. */
    private function mapping_candidate(object $mapping, int $targetcourseid): ?array {
        global $DB;

        $targettype = (string) ($mapping->targettype ?? '');
        $targetid = (int) ($mapping->targetid ?? 0);
        if ($targetid <= 0) {
            return null;
        }

        if ($targettype === 'course') {
            $course = $DB->get_record('course', ['id' => $targetid], 'id');
            if (!$course || ($targetcourseid > 0 && $targetid !== $targetcourseid)) {
                return null;
            }
            return [
                'url' => (new \moodle_url('/course/view.php', ['id' => $targetid]))->out(false),
                'target_type' => 'course',
                'target_id' => $targetid,
                'module' => '',
            ];
        }

        if ($targettype === 'section') {
            $section = $DB->get_record('course_sections', ['id' => $targetid], 'id,course,section');
            if (!$section || ($targetcourseid > 0 && (int) $section->course !== $targetcourseid)) {
                return null;
            }
            return [
                'url' => (new \moodle_url('/course/view.php', ['id' => (int) $section->course]))->out(false)
                    . '#section-' . (int) $section->section,
                'target_type' => 'section',
                'target_id' => $targetid,
                'module' => '',
            ];
        }

        if (!in_array(
            $targettype,
            ['subsection', 'url', 'file', 'html_module', 'scorm', 'book', 'qbank', 'quiz', 'page'],
            true
        )) {
            return null;
        }

        $record = $DB->get_record_sql(
            'SELECT cm.id, cm.course, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = ?',
            [$targetid]
        );
        if (!$record || ($targetcourseid > 0 && (int) $record->course !== $targetcourseid)) {
            return null;
        }

        $module = (string) $record->modulename;
        if (!in_array(
            $module,
            ['subsection', 'url', 'resource', 'scorm', 'book', 'qbank', 'quiz', 'page'],
            true
        )) {
            return null;
        }

        return [
            'url' => (new \moodle_url('/mod/' . $module . '/view.php', ['id' => $targetid]))->out(false),
            'target_type' => $targettype,
            'target_id' => $targetid,
            'module' => $module,
        ];
    }

    /** Build the source ILIAS fallback URL. */
    private function source_fallback_url(string $type, string $refid): string {
        if ($this->sourceinstance === '' || $type === '' || $refid === '') {
            return '';
        }
        return rtrim($this->sourceinstance, '/') . '/goto.php?target=' . rawurlencode($type . '_' . $refid);
    }

    /** Resolve the effective Moodle section number for one Content Page operation. */
    private function resolve_operation_section_number(
        \stdClass $course,
        array $operation,
        string $sourcecourseid
    ): int {
        global $DB;

        $parentsourceref = (string) ($operation['parent_source_ref_id'] ?? '');
        $expected = $this->resolve_parent_section_number($course, $parentsourceref, $sourcecourseid);

        if ($parentsourceref !== '' || (string) ($operation['action'] ?? '') !== 'UPDATE') {
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
        $currentsection = $DB->get_record(
            'course_sections',
            ['id' => (int) $cm->section, 'course' => (int) $course->id],
            'id,section',
            MUST_EXIST
        );

        return phase3_section_policy::effective_update_section(
            $parentsourceref,
            $expected,
            (int) $currentsection->section,
            $this->is_owned_synthetic_section($sourcecourseid, (int) $currentsection->id)
        );
    }

    /** Resolve a mapped folder/subsection parent to its Moodle section number. */
    private function resolve_parent_section_number(
        \stdClass $course,
        string $parentsourceref,
        string $sourcecourseid
    ): int {
        global $DB;

        if ($parentsourceref === '') {
            return 0;
        }

        $sectionmapping = $this->find_mapping($sourcecourseid, $parentsourceref, 'section');
        if ($sectionmapping) {
            $section = $DB->get_record(
                'course_sections',
                ['id' => (int) $sectionmapping->targetid, 'course' => (int) $course->id],
                'id,section',
                MUST_EXIST
            );
            return (int) $section->section;
        }

        $subsectionmapping = $this->find_mapping($sourcecourseid, $parentsourceref, 'subsection');
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
            "No Moodle section mapping exists for ILIAS parent ref_id {$parentsourceref}."
        );
    }

    /** Ensure an UPDATE does not silently move an existing Page. */
    private function assert_module_section(
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
                'Moving an existing Phase 6.5 Page to another Moodle section is not supported yet.'
            );
        }
    }

    /** Whether a section is a synthetic section owned by this migration source. */
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

    /** Find a mapping for the current source instance, with legacy fallback. */
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

    /** Persist or refresh one Content Page mapping. */
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
            return;
        }

        $record->timecreated = $now;
        $DB->insert_record('local_iliasmigration_map', $record);
    }

    /** Resolve and validate a package-relative file. */
    private function resolve_relative_file(string $relative): string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../')) {
            throw new \coding_exception('Unsafe Content Page package-relative file path.');
        }
        $candidate = realpath($this->packageroot . DIRECTORY_SEPARATOR . $relative);
        if ($candidate === false || !is_file($candidate)) {
            throw new \coding_exception('Validated Content Page package file is missing.');
        }
        $rootprefix = $this->packageroot . DIRECTORY_SEPARATOR;
        if (!str_starts_with($candidate, $rootprefix)) {
            throw new \coding_exception('Content Page package path escapes the package root.');
        }
        return $candidate;
    }
}

<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Executes Phase 3 simple-resource migration in Moodle.
 */
final class resource_executor {
    /** @var string Canonical migration package root. */
    private string $packageroot;

    /** @var string Absolute migration.json path used by the validator. */
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
     * Create or update Phase 3 URL/file/HTML resources.
     *
     * Phase 2 structure must already exist. Database writes are transactional;
     * resource payloads are written exclusively through Moodle's File API.
     *
     * @param array $document Validated migration document.
     * @param int $categoryid Moodle target course category id.
     * @return array Execution report.
     */
    public function execute(array $document, int $categoryid): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        $planner = new plan_builder($categoryid, 3);
        $plan = $planner->build($document);
        $validator = new phase3_package_validator($this->migrationjson);
        $plan = $validator->validate($plan);
        $this->assert_applyable($plan);

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourseid = (string) $document['course']['source_id'];
        $sourceversion = (string) ($document['source']['version'] ?? '');

        $courseoperation = $plan['operations'][0] ?? null;
        if (!is_array($courseoperation) || ($courseoperation['kind'] ?? '') !== 'course') {
            throw new \coding_exception('Phase 3 execution plan does not contain a Moodle course operation.');
        }
        $courseid = (int) ($courseoperation['target_id'] ?? 0);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        $originaluser = $USER;
        // create_module()/update_module() enforce capabilities. CLI migration is an
        // administrative operation, as already established for Phase 2.
        \core\session\manager::set_user(get_admin());

        try {
            $transaction = $DB->start_delegated_transaction();

            try {
                $results = [];
                foreach ($plan['operations'] as $operation) {
                    $kind = (string) ($operation['kind'] ?? '');

                    if (in_array($kind, ['url', 'file', 'html_module'], true)) {
                        $sectionnumber = $this->resolve_parent_section_number(
                            $course,
                            (string) ($operation['parent_source_ref_id'] ?? ''),
                            $sourcecourseid
                        );

                        if ($kind === 'url') {
                            $results[] = $this->apply_url(
                                $course,
                                $operation,
                                $sourcecourseid,
                                $sourceversion,
                                $sectionnumber
                            );
                        } else {
                            $results[] = $this->apply_resource(
                                $course,
                                $operation,
                                $sourcecourseid,
                                $sourceversion,
                                $sectionnumber
                            );
                        }
                        continue;
                    }

                    // Phase 2 structure is only verified here. Later activities remain
                    // deferred and are copied unchanged into the execution report.
                    $results[] = $operation;
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
        $plan['phase3_package']['ready'] = true;
        $plan['warnings'][] = [
            'code' => 'COURSE_REMAINS_HIDDEN',
            'message' => 'The imported course remains hidden until later migration phases are validated.',
        ];

        return $plan;
    }

    /**
     * Refuse Phase 3 apply unless Phase 2 structure already exists and every
     * Phase 3 package check passed.
     *
     * @param array $plan Validated Phase 3 plan.
     */
    private function assert_applyable(array $plan): void {
        if (empty($plan['phase3_package']['ready'])
                || !empty($plan['phase3_package']['blocked_resources'])) {
            throw new \coding_exception('Phase 3 package validation failed; apply is refused.');
        }

        foreach ($plan['operations'] as $operation) {
            $kind = (string) ($operation['kind'] ?? '');
            $action = (string) ($operation['action'] ?? '');

            if (in_array($kind, ['course', 'section', 'subsection'], true)) {
                if ($action !== 'UPDATE' || empty($operation['target_id'])) {
                    throw new \coding_exception(
                        'Phase 3 requires Phase 2 structure to exist first; run Phase 2 --apply before Phase 3.'
                    );
                }
                continue;
            }

            if (in_array($kind, ['url', 'file', 'html_module'], true)) {
                if (!in_array($action, ['CREATE', 'UPDATE'], true)) {
                    throw new \coding_exception(
                        "Cannot apply Phase 3 {$kind}: planned action is {$action}."
                    );
                }
                continue;
            }

            if (in_array($action, ['BLOCKED', 'FLATTEN_REQUIRED', 'ERROR_STALE_MAPPING', 'CONFLICT'], true)) {
                throw new \coding_exception(
                    "Cannot apply Phase 3 while operation {$kind} is {$action}."
                );
            }
        }
    }

    /**
     * Create/update one Moodle URL activity.
     *
     * @param \stdClass $course Moodle course.
     * @param array $operation Planned URL operation.
     * @param string $sourcecourseid ILIAS course ref_id.
     * @param string $sourceversion ILIAS version.
     * @param int $sectionnumber Moodle section number.
     * @return array Execution operation.
     */
    private function apply_url(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion,
        int $sectionnumber
    ): array {
        $requested = (string) $operation['action'];
        $externalurl = trim((string) ($operation['resolved_url'] ?? $operation['source_url'] ?? ''));
        if ($externalurl === '') {
            throw new \coding_exception('Resolved URL is empty during Phase 3 apply.');
        }

        $config = get_config('url');
        $moduledata = (object) [
            'modulename' => 'url',
            'course' => (int) $course->id,
            'section' => $sectionnumber,
            'visible' => 1,
            'name' => (string) $operation['title'],
            'intro' => (string) ($operation['description'] ?? ''),
            'introformat' => FORMAT_HTML,
            'externalurl' => $externalurl,
            'display' => isset($config->display) ? (int) $config->display : RESOURCELIB_DISPLAY_AUTO,
            'popupwidth' => isset($config->popupwidth) ? (int) $config->popupwidth : 620,
            'popupheight' => isset($config->popupheight) ? (int) $config->popupheight : 450,
            'printintro' => !empty($config->printintro) ? 1 : 0,
        ];

        if ($requested === 'CREATE') {
            $created = create_module($moduledata);
            $cmid = (int) $created->coursemodule;
            $instanceid = (int) $created->instance;
            $performed = 'CREATED';
        } else {
            $cmid = (int) $operation['target_id'];
            $cm = get_coursemodule_from_id('url', $cmid, $course->id, false, MUST_EXIST);
            $this->assert_module_section($cm, $course, $sectionnumber);

            [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
            foreach (get_object_vars($moduledata) as $property => $value) {
                if (!in_array($property, ['modulename', 'course', 'section', 'visible'], true)) {
                    $moduleinfo->{$property} = $value;
                }
            }
            update_module($moduleinfo);
            $instanceid = (int) $cm->instance;
            $performed = 'UPDATED';
        }

        $this->save_mapping(
            $sourcecourseid,
            (string) $operation['source_ref_id'],
            (string) ($operation['source_obj_id'] ?? ''),
            'url',
            $cmid,
            $sourceversion
        );

        $result = $operation;
        $result['requested_action'] = $requested;
        $result['action'] = $performed;
        $result['target_id'] = $cmid;
        $result['instance_id'] = $instanceid;
        $result['moodle_section'] = $sectionnumber;
        $result['external_url'] = $externalurl;

        return $result;
    }

    /**
     * Create/update a Moodle File resource from a single file or HTML package.
     *
     * @param \stdClass $course Moodle course.
     * @param array $operation Planned file/html operation.
     * @param string $sourcecourseid ILIAS course ref_id.
     * @param string $sourceversion ILIAS version.
     * @param int $sectionnumber Moodle section number.
     * @return array Execution operation.
     */
    private function apply_resource(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion,
        int $sectionnumber
    ): array {
        $requested = (string) $operation['action'];
        $draft = $this->build_resource_draft($operation);
        $config = get_config('resource');

        $moduledata = (object) [
            'modulename' => 'resource',
            'course' => (int) $course->id,
            'section' => $sectionnumber,
            'visible' => 1,
            'name' => (string) $operation['title'],
            'intro' => (string) ($operation['description'] ?? ''),
            'introformat' => FORMAT_HTML,
            'files' => $draft['draft_item_id'],
            'display' => isset($config->display) ? (int) $config->display : RESOURCELIB_DISPLAY_AUTO,
            'popupwidth' => isset($config->popupwidth) ? (int) $config->popupwidth : 620,
            'popupheight' => isset($config->popupheight) ? (int) $config->popupheight : 450,
            'printintro' => !empty($config->printintro) ? 1 : 0,
            'showsize' => 0,
            'showtype' => 0,
            'showdate' => 0,
        ];

        if ($requested === 'CREATE') {
            $created = create_module($moduledata);
            $cmid = (int) $created->coursemodule;
            $instanceid = (int) $created->instance;
            $performed = 'CREATED';
        } else {
            $cmid = (int) $operation['target_id'];
            $cm = get_coursemodule_from_id('resource', $cmid, $course->id, false, MUST_EXIST);
            $this->assert_module_section($cm, $course, $sectionnumber);

            [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
            $moduleinfo->name = $moduledata->name;
            $moduleinfo->intro = $moduledata->intro;
            $moduleinfo->introformat = FORMAT_HTML;
            $moduleinfo->files = $moduledata->files;
            update_module($moduleinfo);
            $instanceid = (int) $cm->instance;
            $performed = 'UPDATED';
        }

        $kind = (string) $operation['kind'];
        $this->save_mapping(
            $sourcecourseid,
            (string) $operation['source_ref_id'],
            (string) ($operation['source_obj_id'] ?? ''),
            $kind,
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
        $result['main_file'] = $draft['main_file'];

        return $result;
    }

    /**
     * Build a Moodle user draft area from the migration package.
     *
     * @param array $operation Planned file/html operation.
     * @return array Draft descriptor.
     */
    private function build_resource_draft(array $operation): array {
        global $USER;

        $kind = (string) ($operation['kind'] ?? '');
        $entries = [];
        $mainrelative = '';

        if ($kind === 'file') {
            $relative = (string) ($operation['migration_path'] ?? '');
            $source = $this->resolve_relative_file($relative);
            $entries[] = [
                'source' => $source,
                'relative' => basename($relative),
            ];
            $mainrelative = basename($relative);
        } else if ($kind === 'html_module') {
            $contentrelative = trim((string) ($operation['migration_content_dir'] ?? ''), '/');
            $startrelative = trim((string) ($operation['migration_start_file'] ?? ''), '/');
            $contentdir = $this->resolve_relative_directory($contentrelative);

            $prefix = $contentrelative . '/';
            if (!str_starts_with($startrelative, $prefix)) {
                throw new \coding_exception('HTML start file is outside its validated content directory.');
            }
            $mainrelative = substr($startrelative, strlen($prefix));

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($contentdir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if (!$entry->isFile() || $entry->isLink()) {
                    continue;
                }
                $source = $entry->getRealPath();
                if ($source === false) {
                    throw new \coding_exception('Unable to resolve an HTML resource package file.');
                }
                $relative = str_replace('\\', '/', substr($source, strlen($contentdir) + 1));
                $entries[] = ['source' => $source, 'relative' => $relative];
            }
        } else {
            throw new \coding_exception('Unsupported resource kind for Phase 3 draft creation.');
        }

        if (!$entries) {
            throw new \coding_exception('No file was found for a Phase 3 Moodle resource.');
        }

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $mainfound = false;

        foreach ($entries as $entry) {
            $relative = trim((string) $entry['relative'], '/');
            $dirname = str_replace('\\', '/', dirname($relative));
            $filepath = ($dirname === '.' || $dirname === '') ? '/' : '/' . trim($dirname, '/') . '/';
            $filename = basename($relative);
            $ismain = $relative === $mainrelative;

            $record = [
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => $filepath,
                'filename' => $filename,
                // mod_resource uses sortorder=1 for its selected main file.
                'sortorder' => $ismain ? 1 : 0,
            ];
            $fs->create_file_from_pathname($record, (string) $entry['source']);
            $mainfound = $mainfound || $ismain;
        }

        if (!$mainfound) {
            throw new \coding_exception('Validated main file was not found while building the Moodle draft area.');
        }

        return [
            'draft_item_id' => $draftitemid,
            'file_count' => count($entries),
            'main_file' => $mainrelative,
        ];
    }

    /**
     * Resolve the Moodle section number for a resource parent.
     *
     * Root ILIAS resources use section 0. First-level folders map to normal
     * sections. Second-level folders map to mod_subsection delegated sections.
     *
     * @param \stdClass $course Moodle course.
     * @param string $parentsourceref Parent ILIAS ref_id or empty for root.
     * @param string $sourcecourseid ILIAS course ref_id.
     * @return int Moodle section number.
     */
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

    /**
     * Ensure an updated course module remains in its expected section.
     *
     * @param \stdClass $cm Moodle course module.
     * @param \stdClass $course Moodle course.
     * @param int $expectedsectionnumber Expected section number.
     */
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
                'Moving an existing Phase 3 resource to another Moodle section is not supported yet.'
            );
        }
    }

    /**
     * Find a mapping for the current source instance, with legacy fallback.
     *
     * @param string $sourcecourse ILIAS course ref_id.
     * @param string $sourceref ILIAS object ref_id.
     * @param string $targettype Mapping target type.
     * @return \stdClass|false
     */
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

    /**
     * Insert or refresh one Phase 3 mapping row.
     *
     * Legacy mappings with sourceinstance='' are upgraded in place.
     *
     * @param string $sourcecourse ILIAS course ref_id.
     * @param string $sourceref ILIAS object ref_id.
     * @param string $sourceobj ILIAS obj_id.
     * @param string $targettype Mapping target type.
     * @param int $targetid Moodle course_modules id.
     * @param string $sourceversion ILIAS version.
     */
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

    /**
     * Resolve and validate a package-relative file.
     *
     * @param string $relative Package-relative path.
     * @return string Canonical file path.
     */
    private function resolve_relative_file(string $relative): string {
        $resolved = $this->resolve_relative($relative);
        if (!is_file($resolved)) {
            throw new \coding_exception('Validated Phase 3 package file is missing.');
        }
        return $resolved;
    }

    /**
     * Resolve and validate a package-relative directory.
     *
     * @param string $relative Package-relative path.
     * @return string Canonical directory path.
     */
    private function resolve_relative_directory(string $relative): string {
        $resolved = $this->resolve_relative($relative);
        if (!is_dir($resolved)) {
            throw new \coding_exception('Validated Phase 3 package directory is missing.');
        }
        return $resolved;
    }

    /**
     * Resolve one relative package path without allowing traversal.
     *
     * @param string $relative Package-relative path.
     * @return string Canonical path.
     */
    private function resolve_relative(string $relative): string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_starts_with($relative, '/')) {
            throw new \coding_exception('Unsafe empty/absolute migration package path.');
        }

        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \coding_exception('Unsafe migration package path traversal.');
            }
        }

        $candidate = $this->packageroot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        $resolved = realpath($candidate);
        if ($resolved === false
                || !str_starts_with($resolved, $this->packageroot . DIRECTORY_SEPARATOR)) {
            throw new \coding_exception('Migration package path resolves outside the package root.');
        }

        return $resolved;
    }
}

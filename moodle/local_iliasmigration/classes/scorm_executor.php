<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Executes Phase 4 SCORM migration in Moodle.
 */
final class scorm_executor {
    /** @var string Canonical migration package root. */
    private string $packageroot;

    /** @var string Absolute migration.json path used by validators. */
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
     * Create or update Phase 4 SCORM activities.
     *
     * @param array $document Validated migration document.
     * @param int $categoryid Moodle target course category id.
     * @return array Execution report.
     */
    public function execute(array $document, int $categoryid): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/scorm/lib.php');
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        require_once($CFG->libdir . '/filelib.php');

        $planner = new phase4_plan_builder($categoryid);
        $plan = $planner->build($document);
        $phase3validator = new phase3_package_validator($this->migrationjson);
        $plan = $phase3validator->validate($plan);
        $phase4validator = new phase4_package_validator($this->migrationjson);
        $plan = $phase4validator->validate($plan);
        $this->assert_applyable($plan);

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourseid = (string) $document['course']['source_id'];
        $sourceversion = (string) ($document['source']['version'] ?? '');

        $courseoperation = $plan['operations'][0] ?? null;
        if (!is_array($courseoperation) || ($courseoperation['kind'] ?? '') !== 'course') {
            throw new \coding_exception('Phase 4 execution plan does not contain a Moodle course operation.');
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
                    if (($operation['kind'] ?? '') !== 'scorm') {
                        $results[] = $operation;
                        continue;
                    }

                    $sectionnumber = $this->resolve_parent_section_number(
                        $course,
                        (string) ($operation['parent_source_ref_id'] ?? ''),
                        $sourcecourseid
                    );
                    $results[] = $this->apply_scorm(
                        $course,
                        $operation,
                        $sourcecourseid,
                        $sourceversion,
                        $sectionnumber,
                        $plan['warnings']
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
        $plan['phase4_package']['ready'] = true;
        $plan['warnings'][] = [
            'code' => 'COURSE_REMAINS_HIDDEN',
            'message' => 'The imported course remains hidden until later migration phases are validated.',
        ];

        return $plan;
    }

    /**
     * Refuse apply unless all previous phases and SCORM package checks are ready.
     */
    private function assert_applyable(array $plan): void {
        if (empty($plan['phase4_package']['ready'])
                || !empty($plan['phase4_package']['blocked_scorm_packages'])) {
            throw new \coding_exception('Phase 4 package validation failed; apply is refused.');
        }
        if (empty($plan['phase4_prerequisites']['ready'])
                || !empty($plan['phase4_prerequisites']['pending_count'])) {
            throw new \coding_exception('Phase 4 prerequisites are not synchronized; apply is refused.');
        }

        $scormcount = 0;
        foreach ($plan['operations'] as $operation) {
            $kind = (string) ($operation['kind'] ?? '');
            $action = (string) ($operation['action'] ?? '');

            if (in_array($kind, ['course', 'section', 'subsection', 'file', 'url', 'html_module'], true)) {
                if ($action !== 'UPDATE' || empty($operation['target_id'])) {
                    throw new \coding_exception(
                        'Phase 4 requires Phase 2/3 objects to exist first; synchronize earlier phases before SCORM.'
                    );
                }
                continue;
            }

            if ($kind === 'scorm') {
                $scormcount++;
                if (!in_array($action, ['CREATE', 'UPDATE'], true)) {
                    throw new \coding_exception("Cannot apply Phase 4 SCORM: planned action is {$action}.");
                }
                continue;
            }

            if (in_array($action, ['BLOCKED', 'FLATTEN_REQUIRED', 'ERROR_STALE_MAPPING', 'ERROR_MAPPING_TYPE', 'CONFLICT'], true)) {
                throw new \coding_exception("Cannot apply Phase 4 while operation {$kind} is {$action}.");
            }
        }

        if ($scormcount === 0) {
            throw new \coding_exception('Phase 4 apply requires at least one SCORM CREATE/UPDATE operation.');
        }
    }

    /**
     * Create/update one Moodle SCORM activity using the official module APIs.
     */
    private function apply_scorm(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion,
        int $sectionnumber,
        array &$warnings
    ): array {
        global $DB;

        $requested = (string) $operation['action'];
        $package = $this->build_package_draft($operation);
        $description = (string) ($operation['description'] ?? '');
        $config = get_config('scorm');
        $maxattempt = $this->map_max_attempts(
            (int) ($operation['tries'] ?? 0),
            (string) ($operation['source_ref_id'] ?? ''),
            $config,
            $warnings
        );

        $width = (int) ($operation['width'] ?? 0);
        if ($width <= 0) {
            $width = isset($config->framewidth) ? (int) $config->framewidth : 100;
        }
        $height = (int) ($operation['height'] ?? 0);
        if ($height <= 0) {
            $height = isset($config->frameheight) ? (int) $config->frameheight : 500;
        }

        $moduledata = (object) [
            'modulename' => 'scorm',
            'course' => (int) $course->id,
            'section' => $sectionnumber,
            'visible' => 1,
            'name' => (string) $operation['title'],
            'introeditor' => $this->build_intro_editor($description),
            'intro' => $description,
            'introformat' => FORMAT_HTML,
            'scormtype' => SCORM_TYPE_LOCAL,
            'packagefile' => $package['draft_item_id'],
            'packageurl' => '',
            'reference' => '',
            'version' => '',
            'md5hash' => '',
            'options' => '',
            'launch' => 0,
            'updatefreq' => 0,
            'popup' => isset($config->popup) ? (int) $config->popup : 0,
            'width' => $width,
            'height' => $height,
            'skipview' => isset($config->skipview) ? (int) $config->skipview : 0,
            'hidebrowse' => isset($config->hidebrowse) ? (int) $config->hidebrowse : 0,
            'displaycoursestructure' => isset($config->displaycoursestructure)
                ? (int) $config->displaycoursestructure : 0,
            'hidetoc' => isset($config->hidetoc) ? (int) $config->hidetoc : 0,
            'nav' => isset($config->nav) ? (int) $config->nav : 1,
            'navpositionleft' => isset($config->navpositionleft) ? (int) $config->navpositionleft : -100,
            'navpositiontop' => isset($config->navpositiontop) ? (int) $config->navpositiontop : -100,
            'displayattemptstatus' => isset($config->displayattemptstatus)
                ? (int) $config->displayattemptstatus : 1,
            'grademethod' => isset($config->grademethod) ? (int) $config->grademethod : 0,
            'maxgrade' => isset($config->maxgrade) ? (float) $config->maxgrade : 100,
            'maxattempt' => $maxattempt,
            'whatgrade' => isset($config->whatgrade) ? (int) $config->whatgrade : 0,
            'forcecompleted' => isset($config->forcecompleted) ? (int) $config->forcecompleted : 0,
            'forcenewattempt' => isset($config->forcenewattempt) ? (int) $config->forcenewattempt : 0,
            'lastattemptlock' => isset($config->lastattemptlock) ? (int) $config->lastattemptlock : 0,
            'masteryoverride' => isset($config->masteryoverride) ? (int) $config->masteryoverride : 1,
            'auto' => isset($config->auto) ? (int) $config->auto : 0,
            'autocommit' => isset($config->autocommit) ? (int) $config->autocommit : 0,
            'timeopen' => 0,
            'timeclose' => 0,
            'completionstatusallscos' => 0,
        ];

        if ($requested === 'CREATE') {
            $created = create_module($moduledata);
            $cmid = (int) $created->coursemodule;
            $instanceid = (int) $created->instance;
            $performed = 'CREATED';
        } else {
            $cmid = (int) $operation['target_id'];
            $cm = get_coursemodule_from_id('scorm', $cmid, $course->id, false, MUST_EXIST);
            $this->assert_module_section($cm, $course, $sectionnumber);

            [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
            $moduleinfo->name = $moduledata->name;
            $moduleinfo->introeditor = $moduledata->introeditor;
            $moduleinfo->scormtype = $moduledata->scormtype;
            $moduleinfo->packagefile = $moduledata->packagefile;
            $moduleinfo->packageurl = '';
            $moduleinfo->updatefreq = 0;
            $moduleinfo->popup = $moduledata->popup;
            $moduleinfo->width = $moduledata->width;
            $moduleinfo->height = $moduledata->height;
            $moduleinfo->skipview = $moduledata->skipview;
            $moduleinfo->hidebrowse = $moduledata->hidebrowse;
            $moduleinfo->displaycoursestructure = $moduledata->displaycoursestructure;
            $moduleinfo->hidetoc = $moduledata->hidetoc;
            $moduleinfo->nav = $moduledata->nav;
            $moduleinfo->navpositionleft = $moduledata->navpositionleft;
            $moduleinfo->navpositiontop = $moduledata->navpositiontop;
            $moduleinfo->displayattemptstatus = $moduledata->displayattemptstatus;
            $moduleinfo->grademethod = $moduledata->grademethod;
            $moduleinfo->maxgrade = $moduledata->maxgrade;
            $moduleinfo->maxattempt = $moduledata->maxattempt;
            $moduleinfo->whatgrade = $moduledata->whatgrade;
            $moduleinfo->forcecompleted = $moduledata->forcecompleted;
            $moduleinfo->forcenewattempt = $moduledata->forcenewattempt;
            $moduleinfo->lastattemptlock = $moduledata->lastattemptlock;
            $moduleinfo->masteryoverride = $moduledata->masteryoverride;
            $moduleinfo->auto = $moduledata->auto;
            $moduleinfo->autocommit = $moduledata->autocommit;
            $moduleinfo->timeopen = 0;
            $moduleinfo->timeclose = 0;
            $moduleinfo->completionstatusallscos = 0;
            update_module($moduleinfo);
            $instanceid = (int) $cm->instance;
            $performed = 'UPDATED';
        }

        $verification = $this->verify_scorm(
            $course,
            $cmid,
            $instanceid,
            $package['source_size'],
            $sectionnumber
        );

        $this->save_mapping(
            $sourcecourseid,
            (string) $operation['source_ref_id'],
            (string) ($operation['source_obj_id'] ?? ''),
            'scorm',
            $cmid,
            $sourceversion
        );

        $result = $operation;
        $result['requested_action'] = $requested;
        $result['action'] = $performed;
        $result['target_id'] = $cmid;
        $result['instance_id'] = $instanceid;
        $result['moodle_section'] = $sectionnumber;
        $result['moodle_package_file'] = $verification['package_file'];
        $result['moodle_package_size'] = $verification['package_size'];
        $result['moodle_package_contenthash'] = $verification['package_contenthash'];
        $result['moodle_content_file_count'] = $verification['content_file_count'];
        $result['scorm_version'] = $verification['version'];
        $result['revision'] = $verification['revision'];
        $result['sco_count'] = $verification['sco_count'];
        $result['launchable_sco_count'] = $verification['launchable_sco_count'];
        $result['maxattempt'] = $verification['maxattempt'];
        $result['width'] = $verification['width'];
        $result['height'] = $verification['height'];

        return $result;
    }

    /** Build the editor payload required by create_module()/update_module(). */
    private function build_intro_editor(string $text): array {
        return [
            'text' => $text,
            'format' => FORMAT_HTML,
            'itemid' => file_get_unused_draft_itemid(),
        ];
    }

    /** Create a one-file Moodle user draft area containing the SCORM ZIP. */
    private function build_package_draft(array $operation): array {
        global $USER;

        $relative = (string) ($operation['migration_package_path'] ?? '');
        $source = $this->resolve_relative_file($relative);
        $size = filesize($source);
        if ($size === false || $size <= 0) {
            throw new \coding_exception('Validated SCORM package is empty or unreadable during apply.');
        }

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $filename = basename($source);
        $record = [
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
        ];
        $fs->create_file_from_pathname($record, $source);

        return [
            'draft_item_id' => $draftitemid,
            'source_size' => (int) $size,
            'filename' => $filename,
        ];
    }

    /** Verify Moodle stored and parsed the SCORM package successfully. */
    private function verify_scorm(
        \stdClass $course,
        int $cmid,
        int $instanceid,
        int $expectedpackagesize,
        int $expectedsectionnumber
    ): array {
        global $DB;

        $cm = get_coursemodule_from_id('scorm', $cmid, $course->id, false, MUST_EXIST);
        $this->assert_module_section($cm, $course, $expectedsectionnumber);
        if ((int) $cm->instance !== $instanceid) {
            throw new \coding_exception('Created/updated SCORM course-module instance does not match the expected instance id.');
        }

        $scorm = $DB->get_record('scorm', ['id' => $instanceid, 'course' => $course->id], '*', MUST_EXIST);
        if ((string) $scorm->version === '' || strtoupper((string) $scorm->version) === 'ERROR') {
            throw new \coding_exception('Moodle could not parse the SCORM package manifest.');
        }
        if ((string) $scorm->reference === '') {
            throw new \coding_exception('Moodle SCORM package reference is empty after import.');
        }

        $context = \context_module::instance($cmid);
        $fs = get_file_storage();
        $packagefile = $fs->get_file(
            $context->id,
            'mod_scorm',
            'package',
            0,
            '/',
            (string) $scorm->reference
        );
        if (!$packagefile) {
            throw new \coding_exception('Moodle File API does not contain the SCORM package after import.');
        }
        if ((int) $packagefile->get_filesize() !== $expectedpackagesize) {
            throw new \coding_exception('Stored Moodle SCORM package size differs from the validated source package.');
        }

        $manifest = $fs->get_file($context->id, 'mod_scorm', 'content', 0, '/', 'imsmanifest.xml');
        if (!$manifest) {
            throw new \coding_exception('Moodle did not extract imsmanifest.xml into the SCORM content file area.');
        }

        $contentfiles = $fs->get_area_files(
            $context->id,
            'mod_scorm',
            'content',
            0,
            'id',
            false
        );
        $scocount = $DB->count_records('scorm_scoes', ['scorm' => $instanceid]);
        $launchables = $DB->count_records_select(
            'scorm_scoes',
            'scorm = ? AND launch IS NOT NULL AND launch <> ?',
            [$instanceid, '']
        );
        if ($scocount <= 0 || $launchables <= 0) {
            throw new \coding_exception('Moodle parsed the package but did not create any launchable SCORM SCO.');
        }

        return [
            'package_file' => (string) $scorm->reference,
            'package_size' => (int) $packagefile->get_filesize(),
            'package_contenthash' => (string) $packagefile->get_contenthash(),
            'content_file_count' => count($contentfiles),
            'version' => (string) $scorm->version,
            'revision' => (int) $scorm->revision,
            'sco_count' => (int) $scocount,
            'launchable_sco_count' => (int) $launchables,
            'maxattempt' => (int) $scorm->maxattempt,
            'width' => (int) $scorm->width,
            'height' => (int) $scorm->height,
        ];
    }

    /** Map ILIAS tries to Moodle's supported attempt limit. */
    private function map_max_attempts(
        int $tries,
        string $sourceref,
        \stdClass $config,
        array &$warnings
    ): int {
        if ($tries >= 1 && $tries <= 6) {
            return $tries;
        }
        if ($tries > 6) {
            $warnings[] = [
                'code' => 'SCORM_TRIES_APPROXIMATED',
                'source_ref_id' => $sourceref,
                'source_tries' => $tries,
                'moodle_maxattempt' => 0,
                'message' => 'ILIAS tries exceeds Moodle SCORM UI limits; migrated as unlimited attempts.',
            ];
            return 0;
        }
        return isset($config->maxattempt) ? (int) $config->maxattempt : 0;
    }

    /** Resolve the Moodle section number for an activity parent. */
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

        throw new \coding_exception("No Moodle section mapping exists for ILIAS parent ref_id {$parentsourceref}.");
    }

    /** Ensure an updated course module remains in its expected section. */
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
            throw new \coding_exception('Moving an existing Phase 4 SCORM to another Moodle section is not supported yet.');
        }
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

    /** Insert or refresh one SCORM mapping row. */
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

    /** Resolve and revalidate a package-relative SCORM file during apply. */
    private function resolve_relative_file(string $relative): string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_starts_with($relative, '/')) {
            throw new \coding_exception('Unsafe empty/absolute SCORM migration package path.');
        }
        if (preg_match('/^[A-Za-z]:\//', $relative) === 1) {
            throw new \coding_exception('Unsafe Windows absolute SCORM migration package path.');
        }

        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \coding_exception('Unsafe SCORM migration package path traversal.');
            }
        }

        $candidate = $this->packageroot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        $resolved = realpath($candidate);
        if ($resolved === false
                || !str_starts_with($resolved, $this->packageroot . DIRECTORY_SEPARATOR)
                || !is_file($resolved)
                || is_link($candidate)) {
            throw new \coding_exception('SCORM migration package path is missing or resolves outside the package root.');
        }
        if (strtolower((string) pathinfo($resolved, PATHINFO_EXTENSION)) !== 'zip') {
            throw new \coding_exception('SCORM migration package must be a ZIP file.');
        }

        return $resolved;
    }
}

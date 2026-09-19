<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Apply a validated ILIAS Exercise as one Moodle Assignment per ILIAS unit.
 *
 * Phase 6.5.4 migrates structure only:
 * - no user submissions;
 * - no grades;
 * - no tutor/peer feedback;
 * - no team memberships.
 */
final class phase65_exercise_executor {
    private string $packageroot;
    private string $migrationjson;
    private string $sourceinstance = '';

    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        $file = realpath($migrationjson);

        if ($root === false || !is_dir($root)
                || $file === false || !is_file($file)) {
            throw new \coding_exception(
                'Unable to resolve migration.json or its package directory.'
            );
        }

        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
        $this->migrationjson = $file;
    }

    public function execute(array $document, int $categoryid): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');
        require_once($CFG->libdir . '/filelib.php');

        $plan = $this->validated_plan($document, $categoryid);
        $this->assert_applyable($plan);

        $this->sourceinstance =
            (string) ($plan['source']['instance'] ?? '');

        $sourcecourseid =
            (string) ($document['course']['source_id'] ?? '');

        $sourceversion =
            (string) ($document['source']['version'] ?? '');

        $courseoperation = $plan['operations'][0] ?? null;

        if (!is_array($courseoperation)
                || ($courseoperation['kind'] ?? '') !== 'course') {
            throw new \coding_exception(
                'Exercise plan has no Moodle course operation.'
            );
        }

        $courseid = (int) ($courseoperation['target_id'] ?? 0);

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            '*',
            MUST_EXIST
        );

        $originaluser = $USER;
        \core\session\manager::set_user(get_admin());

        try {
            $transaction = $DB->start_delegated_transaction();

            try {
                $results = [];

                foreach ($plan['operations'] as $operation) {
                    if (($operation['kind'] ?? '') !== 'exercise') {
                        $results[] = $operation;
                        continue;
                    }

                    $structure = $this->load_structure($operation);
                    $assignments = $this->assignments_by_id($structure);

                    $parentvalidation =
                        $operation['exercise_parent_validation'] ?? [];

                    $sectionnumber =
                        (int) ($parentvalidation['section_number'] ?? 0);

                    $unitresults = [];

                    foreach (
                        (array) (
                            $operation['exercise_validation']['units']
                            ?? []
                        ) as $unit
                    ) {
                        $assignmentid =
                            (string) ($unit['source_id'] ?? '');

                        if (!isset($assignments[$assignmentid])) {
                            throw new \coding_exception(
                                "Exercise unit {$assignmentid} "
                                . 'is missing from structure.json.'
                            );
                        }

                        $unitresults[] = $this->apply_assignment(
                            $course,
                            $operation,
                            $assignments[$assignmentid],
                            $unit,
                            $sourcecourseid,
                            $sourceversion,
                            $sectionnumber
                        );
                    }

                    $operation['requested_action'] =
                        (string) ($operation['action'] ?? '');

                    $operation['action'] = 'APPLIED';

                    $operation['exercise_apply'] = [
                        'unit_count' => count($unitresults),
                        'units' => $unitresults,
                    ];

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
        $plan['phase'] = '6.5.4';
        $plan['phase65_object'] = 'exercise';
        $plan['operations'] = $results;

        return $plan;
    }

    private function validated_plan(
        array $document,
        int $categoryid
    ): array {
        $plan = (new phase6_plan_builder($categoryid))->build($document);

        $plan['phase'] = '6.5.4';

        return (
            new phase65_exercise_package_validator(
                $this->migrationjson
            )
        )->validate($plan);
    }

    private function assert_applyable(array $plan): void {
        $package = $plan['phase65_exercise_package'] ?? [];

        if (empty($package['ready'])
                || empty($package['apply_ready'])
                || empty($package['apply_implemented'])) {
            throw new \coding_exception(
                'Exercise package is not ready for apply.'
            );
        }

        foreach ($plan['operations'] as $operation) {
            if (($operation['kind'] ?? '') !== 'exercise') {
                continue;
            }

            $validation = $operation['exercise_validation'] ?? [];

            if (($validation['status'] ?? '') !== 'OK'
                    || ($validation['code'] ?? '')
                        !== 'EXERCISE_READY_FOR_POC') {
                throw new \coding_exception(
                    'Exercise validation is not ready.'
                );
            }

            foreach ((array) ($validation['units'] ?? []) as $unit) {
                $status = (string) ($unit['status'] ?? '');

                if (!in_array(
                    $status,
                    ['READY', 'READY_WITH_PHASE7_DEPENDENCY'],
                    true
                )) {
                    throw new \coding_exception(
                        'Exercise contains a blocked unit.'
                    );
                }

                $action = (string) ($unit['action'] ?? '');

                if (!in_array($action, ['CREATE', 'UPDATE'], true)) {
                    throw new \coding_exception(
                        "Unsupported Exercise unit action {$action}."
                    );
                }
            }
        }
    }

    private function load_structure(array $operation): array {
        $relative =
            (string) ($operation['migration_structure_path'] ?? '');

        $path = $this->resolve_relative_file($relative);

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \coding_exception(
                'Unable to read Exercise structure.json.'
            );
        }

        try {
            $decoded = json_decode(
                $raw,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \coding_exception(
                'Invalid Exercise structure.json: '
                . $exception->getMessage()
            );
        }

        if (!is_array($decoded)) {
            throw new \coding_exception(
                'Exercise structure.json must be an object.'
            );
        }

        return $decoded;
    }

    private function assignments_by_id(array $structure): array {
        $result = [];

        foreach ((array) ($structure['assignments'] ?? []) as $assignment) {
            if (!is_array($assignment)) {
                continue;
            }

            $id = (string) ($assignment['source_id'] ?? '');

            if ($id !== '') {
                $result[$id] = $assignment;
            }
        }

        return $result;
    }

    private function apply_assignment(
        \stdClass $course,
        array $operation,
        array $assignment,
        array $unit,
        string $sourcecourseid,
        string $sourceversion,
        int $sectionnumber
    ): array {
        global $DB;

        $requested = (string) ($unit['action'] ?? '');

        $draft = $this->build_intro_editor($assignment);

        $start = $this->timestamp(
            (string) ($assignment['start_time_utc'] ?? '')
        );

        $due = $this->timestamp(
            (string) ($assignment['deadline_utc'] ?? '')
        );

        $cutoff = $this->timestamp(
            (string) ($assignment['extended_deadline_utc'] ?? '')
        );

        if ($cutoff > 0 && $due > 0 && $cutoff < $due) {
            $cutoff = $due;
        }

        $type = is_array($assignment['type'] ?? null)
            ? $assignment['type']
            : [];

        $fileenabled = !empty($type['uses_files']);
        $onlineenabled = !empty($type['uses_online_text']);
        $teamsubmission = !empty($type['uses_teams']);

        $maxfiles = (int) ($assignment['max_files'] ?? 0);

        // Moodle needs a finite plugin value. 20 is used only when
        // ILIAS explicitly expresses unlimited file uploads.
        if ($fileenabled && $maxfiles <= 0) {
            $maxfiles = 20;
        }

        if (!$fileenabled) {
            $maxfiles = 1;
        }

        $title = trim((string) ($assignment['title'] ?? ''));

        if ($title === '') {
            throw new \coding_exception(
                'Exercise assignment title is empty.'
            );
        }

        if ($requested === 'CREATE') {
            $moduledata = (object) [
                'modulename' => 'assign',
                'course' => (int) $course->id,
                'section' => $sectionnumber,
                'visible' => 1,

                'name' => $title,
                'introeditor' => $draft['editor'],
                'intro' => $draft['editor']['text'],
                'introformat' => FORMAT_HTML,

                'alwaysshowdescription' => 1,

                'allowsubmissionsfromdate' => $start,
                'duedate' => $due,
                'cutoffdate' => $cutoff,
                'gradingduedate' => 0,

                'grade' => 100,

                'submissiondrafts' => 0,
                'requiresubmissionstatement' => 0,

                'teamsubmission' => $teamsubmission ? 1 : 0,
                'requireallteammemberssubmit' => 0,
                'teamsubmissiongroupingid' => 0,

                'blindmarking' => 0,
                'hidegrader' => 0,
                'markingworkflow' => 0,
                'markingallocation' => 0,

                'sendnotifications' => 0,
                'sendlatenotifications' => 0,

                'attemptreopenmethod' => 'none',
                'maxattempts' => -1,

                'assignsubmission_file_enabled' =>
                    $fileenabled ? 1 : 0,

                'assignsubmission_file_maxfiles' =>
                    $maxfiles,

                'assignsubmission_file_maxsizebytes' =>
                    0,

                'assignsubmission_onlinetext_enabled' =>
                    $onlineenabled ? 1 : 0,
            ];

            $created = create_module($moduledata);

            $cmid = (int) $created->coursemodule;
            $instanceid = (int) $created->instance;
            $performed = 'CREATED';
        } else {
            $cmid = (int) ($unit['target_id'] ?? 0);

            $cm = get_coursemodule_from_id(
                'assign',
                $cmid,
                (int) $course->id,
                false,
                MUST_EXIST
            );

            $section = $DB->get_record(
                'course_sections',
                [
                    'id' => (int) $cm->section,
                    'course' => (int) $course->id,
                ],
                'id,section',
                MUST_EXIST
            );

            if ((int) $section->section !== $sectionnumber) {
                throw new \coding_exception(
                    'Moving an existing Exercise Assignment '
                    . 'to another section is not supported.'
                );
            }

            [, , , $moduleinfo] =
                get_moduleinfo_data($cm, $course);

            $moduleinfo->name = $title;

            $moduleinfo->introeditor = $draft['editor'];

            $moduleinfo->alwaysshowdescription = 1;

            $moduleinfo->allowsubmissionsfromdate = $start;
            $moduleinfo->duedate = $due;
            $moduleinfo->cutoffdate = $cutoff;
            $moduleinfo->gradingduedate = 0;

            $moduleinfo->teamsubmission =
                $teamsubmission ? 1 : 0;

            $moduleinfo->requireallteammemberssubmit = 0;
            $moduleinfo->teamsubmissiongroupingid = 0;

            $moduleinfo->assignsubmission_file_enabled =
                $fileenabled ? 1 : 0;

            $moduleinfo->assignsubmission_file_maxfiles =
                $maxfiles;

            $moduleinfo->assignsubmission_file_maxsizebytes = 0;

            $moduleinfo->assignsubmission_onlinetext_enabled =
                $onlineenabled ? 1 : 0;

            update_module($moduleinfo);

            $instanceid = (int) $cm->instance;
            $performed = 'UPDATED';
        }

        $mappingref =
            (string) ($operation['source_ref_id'] ?? '')
            . ':assignment:'
            . (string) ($assignment['source_id'] ?? '');

        $this->save_mapping(
            $sourcecourseid,
            $mappingref,
            (string) ($operation['source_obj_id'] ?? ''),
            $cmid,
            $sourceversion
        );

        return [
            'source_id' =>
                (string) ($assignment['source_id'] ?? ''),

            'title' => $title,

            'requested_action' => $requested,
            'action' => $performed,

            'target_id' => $cmid,
            'instance_id' => $instanceid,
            'moodle_section' => $sectionnumber,

            'submission_file' => $fileenabled,
            'submission_onlinetext' => $onlineenabled,
            'team_submission' => $teamsubmission,

            'allowsubmissionsfromdate' => $start,
            'duedate' => $due,
            'cutoffdate' => $cutoff,

            'instruction_file_count' =>
                $draft['file_count'],

            'phase7_dependency' =>
                $teamsubmission,
        ];
    }

    private function build_intro_editor(array $assignment): array {
        global $USER;

        $draftitemid = file_get_unused_draft_itemid();

        $usercontext =
            \context_user::instance((int) $USER->id);

        $fs = get_file_storage();

        $instruction =
            trim((string) ($assignment['instruction'] ?? ''));

        $links = [];
        $usednames = [];

        foreach (
            (array) ($assignment['instruction_files'] ?? [])
            as $file
        ) {
            if (!is_array($file)) {
                continue;
            }

            $relative =
                (string) ($file['migration_path'] ?? '');

            $source = $this->resolve_relative_file($relative);

            $original =
                trim((string) ($file['filename'] ?? ''));

            if ($original === '') {
                $original =
                    trim((string) ($file['original_name'] ?? ''));
            }

            if ($original === '') {
                $original = basename($source);
            }

            $filename = $this->unique_filename(
                $original,
                $usednames
            );

            $usednames[$filename] = true;

            $fs->create_file_from_pathname(
                [
                    'contextid' => $usercontext->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => $draftitemid,
                    'filepath' => '/',
                    'filename' => $filename,
                ],
                $source
            );

            $links[] =
                '<li><a href="@@PLUGINFILE@@/'
                . rawurlencode($filename)
                . '">'
                . htmlspecialchars(
                    $filename,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                )
                . '</a></li>';
        }

        $html = $instruction;

        if ($links) {
            if ($html !== '') {
                $html .= "\n";
            }

            $html .=
                '<p><strong>Fichiers de consigne</strong></p>'
                . '<ul>'
                . implode('', $links)
                . '</ul>';
        }

        return [
            'editor' => [
                'text' => $html,
                'format' => FORMAT_HTML,
                'itemid' => $draftitemid,
            ],
            'file_count' => count($links),
        ];
    }

    private function unique_filename(
        string $filename,
        array $used
    ): string {
        $filename = str_replace(
            ["\0", '/', '\\'],
            '_',
            trim($filename)
        );

        if ($filename === ''
                || $filename === '.'
                || $filename === '..') {
            $filename = 'instruction-file';
        }

        if (!isset($used[$filename])) {
            return $filename;
        }

        $extension = pathinfo(
            $filename,
            PATHINFO_EXTENSION
        );

        $basename = pathinfo(
            $filename,
            PATHINFO_FILENAME
        );

        $number = 2;

        do {
            $candidate =
                $basename
                . '_'
                . $number
                . ($extension !== ''
                    ? '.' . $extension
                    : '');

            $number++;
        } while (isset($used[$candidate]));

        return $candidate;
    }

    private function timestamp(string $value): int {
        $value = trim($value);

        if ($value === '' || $value === '0') {
            return 0;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            throw new \coding_exception(
                "Invalid Exercise UTC date: {$value}"
            );
        }

        return $timestamp;
    }

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
            'targettype' => 'assign',
        ];

        $existing = $DB->get_record(
            'local_iliasmigration_map',
            $conditions
        );

        if (!$existing && $this->sourceinstance !== '') {
            $legacy = $conditions;
            $legacy['sourceinstance'] = '';

            $existing = $DB->get_record(
                'local_iliasmigration_map',
                $legacy
            );
        }

        $now = time();

        $record = (object) ($conditions + [
            'sourceversion' =>
                $sourceversion !== ''
                    ? $sourceversion
                    : null,

            'sourceobj' =>
                $sourceobj !== ''
                    ? $sourceobj
                    : null,

            'targetid' => $targetid,
            'status' => 'READY',
            'timemodified' => $now,
        ]);

        if ($existing) {
            $record->id = (int) $existing->id;
            $record->timecreated =
                (int) $existing->timecreated;

            $DB->update_record(
                'local_iliasmigration_map',
                $record
            );
        } else {
            $record->timecreated = $now;

            $DB->insert_record(
                'local_iliasmigration_map',
                $record
            );
        }
    }

    private function resolve_relative_file(
        string $relative
    ): string {
        $relative = trim(
            str_replace('\\', '/', $relative)
        );

        if ($relative === ''
                || str_starts_with($relative, '/')
                || str_contains($relative, '../')) {
            throw new \coding_exception(
                'Unsafe Exercise package path.'
            );
        }

        $candidate = realpath(
            $this->packageroot
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relative
            )
        );

        if ($candidate === false || !is_file($candidate)) {
            throw new \coding_exception(
                "Validated Exercise file is missing: {$relative}"
            );
        }

        if (!str_starts_with(
            $candidate,
            $this->packageroot . DIRECTORY_SEPARATOR
        )) {
            throw new \coding_exception(
                'Exercise file escapes package root.'
            );
        }

        return $candidate;
    }
}

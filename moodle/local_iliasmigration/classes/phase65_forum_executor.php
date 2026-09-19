<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Apply the Phase 6.5.5 Forum container only.
 *
 * Discussions/posts are intentionally not created until Phase 7 can resolve
 * their source authors to Moodle users.
 */
final class phase65_forum_executor {
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
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $plan = (new phase6_plan_builder($categoryid))->build($document);
        $plan['phase'] = '6.5.5';
        $plan = (new phase65_forum_package_validator($this->migrationjson))
            ->validate($plan);

        $this->assert_applyable($plan);

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourseid = (string) ($document['course']['source_id'] ?? '');
        $sourceversion = (string) ($document['source']['version'] ?? '');

        $courseoperation = $plan['operations'][0] ?? null;
        if (!is_array($courseoperation)
                || ($courseoperation['kind'] ?? '') !== 'course') {
            throw new \coding_exception(
                'Forum plan has no Moodle course operation.'
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
                    if (($operation['kind'] ?? '') !== 'forum') {
                        $results[] = $operation;
                        continue;
                    }

                    if (($operation['forum_validation']['status'] ?? '')
                            === 'SKIPPED_INCREMENTAL') {
                        $results[] = $operation;
                        continue;
                    }

                    $results[] = $this->apply_forum(
                        $course,
                        $operation,
                        $sourcecourseid,
                        $sourceversion
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
        $plan['phase'] = '6.5.5';
        $plan['phase65_object'] = 'forum';
        $plan['operations'] = $results;

        return $plan;
    }

    private function assert_applyable(array $plan): void {
        $package = $plan['phase65_forum_package'] ?? [];

        if (empty($package['ready'])
                || empty($package['apply_ready'])
                || empty($package['apply_implemented'])) {
            throw new \coding_exception(
                'Forum package is not ready for apply.'
            );
        }

        foreach ($plan['operations'] as $operation) {
            if (($operation['kind'] ?? '') !== 'forum') {
                continue;
            }

            $validation = $operation['forum_validation'] ?? [];

            if (($validation['status'] ?? '') === 'SKIPPED_INCREMENTAL') {
                continue;
            }

            if (($validation['status'] ?? '')
                    !== 'READY_WITH_PHASE7_DEPENDENCY'
                    || ($validation['code'] ?? '')
                    !== 'FORUM_CONTAINER_READY') {
                throw new \coding_exception(
                    'Forum validation is not ready.'
                );
            }

            if (!in_array(
                (string) ($operation['action'] ?? ''),
                ['CREATE', 'UPDATE'],
                true
            )) {
                throw new \coding_exception(
                    'Forum action is not CREATE/UPDATE.'
                );
            }
        }
    }

    private function apply_forum(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion
    ): array {
        global $CFG, $DB;

        $requested = (string) ($operation['action'] ?? '');
        $sectionnumber = (int) (
            $operation['forum_parent_validation']['section_number']
            ?? 0
        );

        $structure = $this->load_structure($operation);
        $description = (string) ($structure['description'] ?? '');

        if ($requested === 'CREATE') {
            $moduledata = (object) [
                'modulename' => 'forum',
                'course' => (int) $course->id,
                'section' => $sectionnumber,
                'visible' => 1,
                'name' => (string) ($operation['title'] ?? ''),
                'introeditor' => $this->intro_editor($description),
                'intro' => $description,
                'introformat' => FORMAT_HTML,
                'type' => 'general',
                'duedate' => 0,
                'cutoffdate' => 0,
                'maxbytes' => (int) ($CFG->forum_maxbytes ?? 0),
                'maxattachments' => (int) ($CFG->forum_maxattachments ?? 9),
                'displaywordcount' => 0,
                'forcesubscribe' => FORUM_CHOOSESUBSCRIBE,
                'trackingtype' => FORUM_TRACKING_OPTIONAL,
                'rsstype' => 0,
                'rssarticles' => 0,
                'assessed' => 0,
                'scale' => 0,
                'grade_forum' => 0,
                'blockperiod' => 0,
                'blockafter' => 0,
                'warnafter' => 0,
                'lockdiscussionafter' => 0,
                'completiondiscussions' => 0,
                'completionreplies' => 0,
                'completionposts' => 0,
            ];

            $created = create_module($moduledata);
            $cmid = (int) $created->coursemodule;
            $instanceid = (int) $created->instance;
            $performed = 'CREATED';
        } else {
            $cmid = (int) ($operation['target_id'] ?? 0);
            $cm = get_coursemodule_from_id(
                'forum',
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
                    'Moving an existing Forum to another Moodle section is not supported.'
                );
            }

            [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
            $moduleinfo->name = (string) ($operation['title'] ?? '');
            $moduleinfo->introeditor = $this->intro_editor($description);
            $moduleinfo->type = 'general';
            $moduleinfo->assessed = 0;
            $moduleinfo->grade_forum = 0;

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
        $result['forum_type'] = 'general';
        $result['discussions_created'] = 0;
        $result['posts_created'] = 0;
        $result['post_assets_created'] = 0;
        $result['phase7_dependency'] = true;
        $result['phase7_reason'] = 'AUTHOR_IDENTITY_RESOLUTION_REQUIRED';

        return $result;
    }

    private function load_structure(array $operation): array {
        $relative = trim((string) ($operation['migration_structure_path'] ?? ''));
        $path = $this->resolve_relative_file($relative);

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \coding_exception(
                'Unable to read Forum structure.json.'
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
                'Invalid Forum structure.json: '
                . $exception->getMessage()
            );
        }

        if (!is_array($decoded)) {
            throw new \coding_exception(
                'Forum structure.json must be an object.'
            );
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
            'targettype' => 'forum',
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
                $sourceversion !== '' ? $sourceversion : null,
            'sourceobj' =>
                $sourceobj !== '' ? $sourceobj : null,
            'targetid' => $targetid,
            'status' => 'READY',
            'timemodified' => $now,
        ]);

        if ($existing) {
            $record->id = (int) $existing->id;
            $record->timecreated = (int) $existing->timecreated;
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

    private function resolve_relative_file(string $relative): string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === ''
                || str_starts_with($relative, '/')
                || str_contains($relative, '../')) {
            throw new \coding_exception(
                'Unsafe Forum package-relative path.'
            );
        }

        $candidate = realpath(
            $this->packageroot
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative)
        );

        if ($candidate === false || !is_file($candidate)) {
            throw new \coding_exception(
                'Validated Forum package file is missing.'
            );
        }

        if (!str_starts_with(
            $candidate,
            $this->packageroot . DIRECTORY_SEPARATOR
        )) {
            throw new \coding_exception(
                'Forum package path escapes the package root.'
            );
        }

        return $candidate;
    }
}

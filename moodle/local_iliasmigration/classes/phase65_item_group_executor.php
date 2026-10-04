<?php

namespace local_iliasmigration;

use core_courseformat\formatactions;

defined('MOODLE_INTERNAL') || die();

/**
 * Apply ILIAS Item Groups as Moodle structural containers.
 *
 * Policy:
 * - root Item Group -> regular Moodle section;
 * - Item Group below a first-level folder -> mod_subsection;
 * - member activities must already exist and are only moved;
 * - no migrated activity is recreated;
 * - source HideTitle/Behaviour remain audit metadata only.
 */
final class phase65_item_group_executor {
    private string $migrationjson;
    private string $sourceinstance = '';

    public function __construct(string $migrationjson) {
        $file = realpath($migrationjson);

        if ($file === false || !is_file($file)) {
            throw new \coding_exception(
                'Unable to resolve migration.json for Item Group apply.'
            );
        }

        $this->migrationjson = $file;
    }

    public function execute(
        array $document,
        int $categoryid
    ): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $plan = (
            new phase6_plan_builder($categoryid)
        )->build($document);

        $plan['phase'] = '6.5.9';

        $plan = (
            new phase65_item_group_package_validator(
                $this->migrationjson
            )
        )->validate($plan);

        $this->assert_applyable($plan);

        $this->sourceinstance = (string) (
            $plan['source']['instance'] ?? ''
        );

        $sourcecourse = (string) (
            $document['course']['source_id'] ?? ''
        );

        $sourceversion = (string) (
            $document['source']['version'] ?? ''
        );

        $courseoperation = $plan['operations'][0] ?? null;

        if (!is_array($courseoperation)
                || ($courseoperation['kind'] ?? '') !== 'course') {
            throw new \coding_exception(
                'Item Group plan has no Moodle course operation.'
            );
        }

        $courseid = (int) (
            $courseoperation['target_id'] ?? 0
        );

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            '*',
            MUST_EXIST
        );

        $operationbyref = [];

        foreach ($plan['operations'] as $operation) {
            if (($operation['kind'] ?? '') !== 'itgr') {
                continue;
            }

            $ref = (string) (
                $operation['source_ref_id'] ?? ''
            );

            if ($ref !== '') {
                $operationbyref[$ref] = $operation;
            }
        }

        $resultsbyref = [];
        $rootapply = [];

        $originaluser = $USER;

        \core\session\manager::set_user(
            get_admin()
        );

        try {
            $transaction =
                $DB->start_delegated_transaction();

            try {
                /*
                 * First establish the complete top-level section order.
                 *
                 * Processing in desired order is important:
                 * creating section 3 and then section 4 automatically shifts
                 * the pre-existing Activités section towards position 5.
                 */
                $rootplan = (
                    $plan['phase65_item_group_package']
                        ['root_structure_plan']
                    ?? []
                );

                foreach ($rootplan as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $type = (string) (
                        $entry['source_type'] ?? ''
                    );

                    $ref = (string) (
                        $entry['source_ref_id'] ?? ''
                    );

                    if ($type === 'folder') {
                        $rootapply[] =
                            $this->align_existing_section(
                                $course,
                                $entry
                            );

                        continue;
                    }

                    if ($type !== 'itgr') {
                        throw new \coding_exception(
                            'Unexpected root structure type in Item Group plan.'
                        );
                    }

                    if (!isset($operationbyref[$ref])) {
                        throw new \coding_exception(
                            "Missing Item Group operation for ref {$ref}."
                        );
                    }

                    $result =
                        $this->apply_root_group(
                            $course,
                            $operationbyref[$ref],
                            $entry,
                            $sourcecourse,
                            $sourceversion
                        );

                    $resultsbyref[$ref] =
                        $result['operation'];

                    $rootapply[] =
                        $result['root'];
                }

                /*
                 * Root positions are now final.
                 * Only now create/update parented Item Groups.
                 *
                 * Their parent is resolved by stable course_sections.id,
                 * never by the old mutable section number.
                 */
                foreach ($operationbyref as $ref => $operation) {
                    if (($operation['target_structure'] ?? '')
                            !== 'subsection') {
                        continue;
                    }

                    $resultsbyref[$ref] =
                        $this->apply_subsection_group(
                            $course,
                            $operation,
                            $sourcecourse,
                            $sourceversion
                        );
                }

                rebuild_course_cache(
                    $course->id,
                    true
                );

                $this->assert_final_root_layout(
                    $course,
                    $rootplan,
                    $resultsbyref
                );

                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback(
                    $exception
                );
            }
        } finally {
            if ($originaluser instanceof \stdClass) {
                \core\session\manager::set_user(
                    $originaluser
                );
            }
        }

        $results = [];

        foreach ($plan['operations'] as $operation) {
            if (($operation['kind'] ?? '') !== 'itgr') {
                $results[] = $operation;
                continue;
            }

            $ref = (string) (
                $operation['source_ref_id'] ?? ''
            );

            if (!isset($resultsbyref[$ref])) {
                throw new \coding_exception(
                    "Item Group {$ref} was not applied."
                );
            }

            $results[] =
                $resultsbyref[$ref];
        }

        $plan['mode'] = 'apply';
        $plan['writes_performed'] = true;
        $plan['phase'] = '6.5.9';
        $plan['phase65_object'] = 'item_group';
        $plan['operations'] = $results;

        $plan['phase65_item_group_package']
            ['root_structure_apply'] = $rootapply;

        return $plan;
    }

    private function assert_applyable(
        array $plan
    ): void {
        $package = (
            $plan['phase65_item_group_package']
            ?? []
        );

        if (empty($package['ready'])
                || empty($package['apply_ready'])
                || empty($package['apply_implemented'])
                || empty($package['root_structure_ready'])
                || !empty($package['blocked_item_groups'])) {
            throw new \coding_exception(
                'Item Group package is not ready for apply.'
            );
        }

        foreach ($plan['operations'] as $operation) {
            if (($operation['kind'] ?? '') !== 'itgr') {
                continue;
            }

            $validation = is_array(
                $operation['item_group_validation']
                    ?? null
            )
                ? $operation['item_group_validation']
                : [];

            if (($validation['status'] ?? '')
                    !== 'READY'
                    || ($validation['code'] ?? '')
                    !== 'ITEM_GROUP_READY') {
                throw new \coding_exception(
                    'Item Group validation is not READY.'
                );
            }

            if (!in_array(
                (string) (
                    $operation['action'] ?? ''
                ),
                ['CREATE', 'UPDATE'],
                true
            )) {
                throw new \coding_exception(
                    'Item Group action is not CREATE/UPDATE.'
                );
            }
        }
    }

    /**
     * Reuse/reposition an existing ILIAS folder section.
     *
     * No folder mapping is created here: it must already exist.
     */
    private function align_existing_section(
        \stdClass $course,
        array $entry
    ): array {
        global $DB;

        $sectionid = (int) (
            $entry['target_id'] ?? 0
        );

        $desired = (int) (
            $entry['desired_section_number'] ?? 0
        );

        $preservefinalorder = (
            (string) (
                $entry['position_policy'] ?? ''
            ) === 'PRESERVE_RECONCILED_ORDER'
        );

        if ($sectionid <= 0 || $desired <= 0) {
            throw new \coding_exception(
                'Existing root section has an invalid target/position.'
            );
        }

        $before = $DB->get_record(
            'course_sections',
            [
                'id' => $sectionid,
                'course' => (int) $course->id,
            ],
            'id,section,name,component,itemid',
            MUST_EXIST
        );

        if (!empty($before->component)) {
            throw new \coding_exception(
                'Root source folder unexpectedly maps to a delegated section.'
            );
        }

        $beforeposition =
            (int) $before->section;

        if ($preservefinalorder
                && $beforeposition !== $desired) {
            throw new \coding_exception(
                'Final reconciled root order changed unexpectedly.'
            );
        }

        if (!$preservefinalorder
                && $beforeposition !== $desired) {
            $actions =
                formatactions::section($course);

            rebuild_course_cache(
                $course->id,
                true
            );

            $info = get_fast_modinfo(
                $course->id
            )->get_section_info_by_id(
                $sectionid
            );

            if (!$info) {
                throw new \coding_exception(
                    'Unable to resolve existing root section.'
                );
            }

            $actions->move_at(
                $info,
                $desired
            );

            rebuild_course_cache(
                $course->id,
                true
            );
        }

        $after = $DB->get_record(
            'course_sections',
            [
                'id' => $sectionid,
                'course' => (int) $course->id,
            ],
            'id,section,name',
            MUST_EXIST
        );

        if ((int) $after->section !== $desired) {
            throw new \coding_exception(
                'Existing root section did not reach its desired position.'
            );
        }

        return array_merge(
            $entry,
            [
                'planned_action' =>
                    (string) ($entry['action'] ?? ''),
                'action' =>
                    $beforeposition === $desired
                        ? 'REUSED'
                        : 'REPOSITIONED',
                'runtime_before_section_number' =>
                    $beforeposition,
                'runtime_after_section_number' =>
                    (int) $after->section,
            ]
        );
    }

    /**
     * Create/update one root Item Group as a regular Moodle section.
     */
    private function apply_root_group(
        \stdClass $course,
        array $operation,
        array $entry,
        string $sourcecourse,
        string $sourceversion
    ): array {
        global $DB;

        $requested = (string) (
            $operation['action'] ?? ''
        );

        $desired = (int) (
            $entry['desired_section_number']
                ?? 0
        );

        $preservefinalorder = (
            (string) (
                $entry['position_policy'] ?? ''
            ) === 'PRESERVE_RECONCILED_ORDER'
        );

        if ($desired <= 0) {
            throw new \coding_exception(
                'Root Item Group has no valid desired section number.'
            );
        }

        if ($preservefinalorder
                && $requested === 'CREATE') {
            throw new \coding_exception(
                'Creating a root Item Group after final order '
                . 'reconciliation is not allowed.'
            );
        }

        if ($requested === 'CREATE') {
            $created = course_create_section(
                $course,
                $desired
            );

            $sectionid =
                (int) $created->id;

            $performed = 'CREATED';
        } else {
            $sectionid = (int) (
                $operation['target_id']
                    ?? 0
            );

            $section = $DB->get_record(
                'course_sections',
                [
                    'id' => $sectionid,
                    'course' => (int) $course->id,
                ],
                'id,section,component',
                MUST_EXIST
            );

            if (!empty($section->component)) {
                throw new \coding_exception(
                    'Mapped root Item Group is not a regular Moodle section.'
                );
            }

            $performed = 'UPDATED';
        }

        rebuild_course_cache(
            $course->id,
            true
        );

        $info = get_fast_modinfo(
            $course->id
        )->get_section_info_by_id(
            $sectionid
        );

        if (!$info) {
            throw new \coding_exception(
                'Unable to resolve Item Group Moodle section.'
            );
        }

        $actions =
            formatactions::section($course);

        if ($preservefinalorder
                && (int) $info->section !== $desired) {
            throw new \coding_exception(
                'Mapped root Item Group moved after final '
                . 'order reconciliation.'
            );
        }

        if (!$preservefinalorder
                && (int) $info->section !== $desired) {
            $actions->move_at(
                $info,
                $desired
            );

            rebuild_course_cache(
                $course->id,
                true
            );

            $info = get_fast_modinfo(
                $course->id
            )->get_section_info_by_id(
                $sectionid
            );
        }

        if (!$info
                || (int) $info->section
                    !== $desired) {
            throw new \coding_exception(
                'Item Group section did not reach its desired position.'
            );
        }

        $actions->update(
            $info,
            [
                'name' => (string) (
                    $operation['title'] ?? ''
                ),
            ]
        );

        rebuild_course_cache(
            $course->id,
            true
        );

        $cmids = array_map(
            'intval',
            (array) (
                $operation['item_group_validation']
                    ['member_cmids']
                ?? []
            )
        );

        $this->move_cmids_to_section(
            $course,
            $sectionid,
            $cmids
        );

        $this->save_mapping(
            $sourcecourse,
            (string) (
                $operation['source_ref_id']
                    ?? ''
            ),
            (string) (
                $operation['source_obj_id']
                    ?? ''
            ),
            'section',
            $sectionid,
            $sourceversion
        );

        $result = $operation;

        $result['requested_action'] =
            $requested;

        $result['action'] =
            $performed;

        $result['target_id'] =
            $sectionid;

        $result['moodle_section'] =
            $desired;

        $result['member_cmids_moved'] =
            $cmids;

        return [
            'operation' => $result,
            'root' => array_merge(
                $entry,
                [
                    'planned_action' =>
                        (string) ($entry['action'] ?? ''),
                    'action' => $performed,
                    'target_id' => $sectionid,
                    'runtime_after_section_number' =>
                        $desired,
                ]
            ),
        ];
    }

    /**
     * Create/update an Item Group under an existing source section.
     */
    private function apply_subsection_group(
        \stdClass $course,
        array $operation,
        string $sourcecourse,
        string $sourceversion
    ): array {
        global $DB;

        $requested = (string) (
            $operation['action'] ?? ''
        );

        $validation = is_array(
            $operation['item_group_validation']
                ?? null
        )
            ? $operation['item_group_validation']
            : [];

        /*
         * Parent section is always resolved again by stable ID.
         * Its number may already have changed because root groups were inserted.
         */
        $parentsectionid = (int) (
            $validation['parent_section_id']
                ?? 0
        );

        $parent = $DB->get_record(
            'course_sections',
            [
                'id' => $parentsectionid,
                'course' => (int) $course->id,
            ],
            'id,section,name,component,itemid',
            MUST_EXIST
        );

        if (!empty($parent->component)) {
            throw new \coding_exception(
                'Item Group parent is not a regular Moodle section.'
            );
        }

        $parentnumber =
            (int) $parent->section;

        $desiredparent = (int) (
            $validation[
                'desired_parent_section_number'
            ] ?? 0
        );

        if ($desiredparent > 0
                && $parentnumber
                    !== $desiredparent) {
            throw new \coding_exception(
                'Item Group parent did not reach its desired root position.'
            );
        }

        $title = (string) (
            $operation['title'] ?? ''
        );

        if ($requested === 'CREATE') {
            $created = create_module(
                (object) [
                    'modulename' => 'subsection',
                    'course' => (int) $course->id,
                    'section' => $parentnumber,
                    'visible' => 1,
                    'name' => $title,
                ]
            );

            $cmid =
                (int) $created->coursemodule;

            $instanceid =
                (int) $created->instance;

            $performed = 'CREATED';
        } else {
            $cmid = (int) (
                $operation['target_id']
                    ?? 0
            );

            $cm = get_coursemodule_from_id(
                'subsection',
                $cmid,
                (int) $course->id,
                false,
                MUST_EXIST
            );

            /*
             * cm.section stores course_sections.id, not the visible number.
             */
            if ((int) $cm->section
                    !== $parentsectionid) {
                throw new \coding_exception(
                    'Mapped Item Group subsection belongs to the wrong parent section.'
                );
            }

            [, , , $moduleinfo] =
                get_moduleinfo_data(
                    $cm,
                    $course
                );

            $moduleinfo->name =
                $title;

            update_module(
                $moduleinfo
            );

            $instanceid =
                (int) $cm->instance;

            $performed = 'UPDATED';
        }

        rebuild_course_cache(
            $course->id,
            true
        );

        $delegated = $DB->get_record(
            'course_sections',
            [
                'course' => (int) $course->id,
                'component' => 'mod_subsection',
                'itemid' => $instanceid,
            ],
            'id,course,section,name,component,itemid',
            MUST_EXIST
        );

        $delegatedinfo = get_fast_modinfo(
            $course->id
        )->get_section_info_by_id(
            (int) $delegated->id
        );

        if (!$delegatedinfo) {
            throw new \coding_exception(
                'Unable to resolve delegated Item Group subsection.'
            );
        }

        if ((string) $delegatedinfo->name
                !== $title) {
            formatactions::section(
                $course
            )->update(
                $delegatedinfo,
                ['name' => $title]
            );

            rebuild_course_cache(
                $course->id,
                true
            );
        }

        $cmids = array_map(
            'intval',
            (array) (
                $validation['member_cmids']
                ?? []
            )
        );

        $this->move_cmids_to_section(
            $course,
            (int) $delegated->id,
            $cmids
        );

        $this->save_mapping(
            $sourcecourse,
            (string) (
                $operation['source_ref_id']
                    ?? ''
            ),
            (string) (
                $operation['source_obj_id']
                    ?? ''
            ),
            'subsection',
            $cmid,
            $sourceversion
        );

        $result = $operation;

        $result['requested_action'] =
            $requested;

        $result['action'] =
            $performed;

        $result['target_id'] =
            $cmid;

        $result['instance_id'] =
            $instanceid;

        $result['parent_section_id'] =
            $parentsectionid;

        $result['parent_moodle_section'] =
            $parentnumber;

        $result['delegated_section_id'] =
            (int) $delegated->id;

        $result['delegated_moodle_section'] =
            (int) $delegated->section;

        $result['member_cmids_moved'] =
            $cmids;

        return $result;
    }

    /**
     * Move already existing mapped Moodle activities in source-member order.
     */
    private function move_cmids_to_section(
        \stdClass $course,
        int $sectionid,
        array $cmids
    ): void {
        global $DB;

        $target = $DB->get_record(
            'course_sections',
            [
                'id' => $sectionid,
                'course' => (int) $course->id,
            ],
            '*',
            MUST_EXIST
        );

        foreach ($cmids as $cmid) {
            $cmid = (int) $cmid;

            $cmrecord = $DB->get_record(
                'course_modules',
                [
                    'id' => $cmid,
                    'course' => (int) $course->id,
                ],
                'id',
                MUST_EXIST
            );

            rebuild_course_cache(
                $course->id,
                true
            );

            $cm = get_fast_modinfo(
                $course->id
            )->get_cm(
                (int) $cmrecord->id
            );

            moveto_module(
                $cm,
                $target,
                null
            );

            rebuild_course_cache(
                $course->id,
                true
            );
        }

        $fresh = $DB->get_record(
            'course_sections',
            [
                'id' => $sectionid,
                'course' => (int) $course->id,
            ],
            'id,sequence',
            MUST_EXIST
        );

        $sequence = trim(
            (string) $fresh->sequence
        ) === ''
            ? []
            : array_map(
                'intval',
                explode(
                    ',',
                    (string) $fresh->sequence
                )
            );

        foreach ($cmids as $cmid) {
            if (!in_array(
                (int) $cmid,
                $sequence,
                true
            )) {
                throw new \coding_exception(
                    "CMID {$cmid} was not moved into the expected Item Group section."
                );
            }
        }
    }

    /**
     * Final transaction guard for top-level section IDs/positions.
     */
    private function assert_final_root_layout(
        \stdClass $course,
        array $rootplan,
        array $resultsbyref
    ): void {
        global $DB;

        foreach ($rootplan as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $ref = (string) (
                $entry['source_ref_id'] ?? ''
            );

            $type = (string) (
                $entry['source_type'] ?? ''
            );

            $desired = (int) (
                $entry['desired_section_number']
                    ?? 0
            );

            if ($type === 'itgr') {
                $sectionid = (int) (
                    $resultsbyref[$ref]['target_id']
                    ?? 0
                );
            } else {
                $sectionid = (int) (
                    $entry['target_id'] ?? 0
                );
            }

            if ($sectionid <= 0
                    || $desired <= 0) {
                throw new \coding_exception(
                    'Final Item Group root-layout identity is invalid.'
                );
            }

            $section = $DB->get_record(
                'course_sections',
                [
                    'id' => $sectionid,
                    'course' => (int) $course->id,
                ],
                'id,section,component',
                MUST_EXIST
            );

            if (!empty($section->component)
                    || (int) $section->section
                        !== $desired) {
                throw new \coding_exception(
                    "Final root layout mismatch for source ref {$ref}."
                );
            }
        }
    }

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
            'sourceinstance' =>
                $this->sourceinstance,
            'sourcecourse' =>
                $sourcecourse,
            'sourceref' =>
                $sourceref,
            'targettype' =>
                $targettype,
        ];

        $existing = $DB->get_record(
            'local_iliasmigration_map',
            $conditions
        );

        if (!$existing
                && $this->sourceinstance !== '') {
            $legacy = $conditions;
            $legacy['sourceinstance'] = '';

            $existing = $DB->get_record(
                'local_iliasmigration_map',
                $legacy
            );
        }

        $now = time();

        $record = (object) (
            $conditions + [
                'sourceversion' =>
                    $sourceversion !== ''
                        ? $sourceversion
                        : null,
                'sourceobj' =>
                    $sourceobj !== ''
                        ? $sourceobj
                        : null,
                'targetid' =>
                    $targetid,
                'status' =>
                    'READY',
                'timemodified' =>
                    $now,
            ]
        );

        if ($existing) {
            $record->id =
                (int) $existing->id;

            $record->timecreated =
                (int) $existing->timecreated;

            $DB->update_record(
                'local_iliasmigration_map',
                $record
            );

            return;
        }

        $record->timecreated =
            $now;

        $DB->insert_record(
            'local_iliasmigration_map',
            $record
        );
    }
}

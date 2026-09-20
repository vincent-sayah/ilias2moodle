<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Apply Phase 7.2 ILIAS group migration.
 *
 * Creates or updates the mapped Moodle group and adds only source members that
 * were accepted by the resolver. Membership handling is additive: unrelated
 * Moodle group members are never removed by this phase.
 */
final class phase7_group_executor {
    public function execute(string $groupjson, int $courseid): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/group/lib.php');

        $plan = (new phase7_group_resolver())->resolve(
            $groupjson,
            $courseid
        );

        if (empty($plan['apply_implemented'])
                || empty($plan['ready_for_apply'])) {
            throw new \coding_exception(
                'Phase 7.2 group plan is not ready for apply.'
            );
        }

        $clientid = trim((string) ($plan['source']['client_id'] ?? ''));
        $sourcecourse = trim(
            (string) ($plan['source_course']['object_id'] ?? '')
        );
        $sourceref = trim(
            (string) ($plan['source_group']['ref_id'] ?? '')
        );
        $sourceobj = trim(
            (string) ($plan['source_group']['object_id'] ?? '')
        );

        if ($clientid === '' || $sourcecourse === ''
                || $sourceref === '' || $sourceobj === '') {
            throw new \coding_exception(
                'Phase 7.2 group plan is missing persistent mapping identifiers.'
            );
        }

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,shortname,fullname',
            MUST_EXIST
        );

        $groupaction = (string) ($plan['group_plan']['action'] ?? '');
        $groupname = trim(
            (string) ($plan['group_plan']['name'] ?? '')
        );
        $groupdescription = (string) (
            $plan['group_plan']['description'] ?? ''
        );

        if ($groupname === '') {
            throw new \coding_exception(
                'Phase 7.2 target group name cannot be empty.'
            );
        }

        $originaluser = $USER;
        \core\session\manager::set_user(get_admin());

        try {
            if ($groupaction === 'CREATE') {
                $groupdata = (object) [
                    'courseid' => (int) $course->id,
                    'name' => $groupname,
                    'description' => $groupdescription,
                    'descriptionformat' => FORMAT_PLAIN,
                    'enablemessaging' => 0,
                    'visibility' => GROUPS_VISIBILITY_ALL,
                ];

                $groupid = (int) groups_create_group(
                    $groupdata,
                    false,
                    false
                );

                $groupoperation = 'CREATED';
            } else if ($groupaction === 'UPDATE') {
                $groupid = (int) (
                    $plan['target_group']['id'] ?? 0
                );

                if ($groupid <= 0) {
                    throw new \coding_exception(
                        'Phase 7.2 UPDATE has no mapped Moodle group id.'
                    );
                }

                $groupdata = $DB->get_record(
                    'groups',
                    [
                        'id' => $groupid,
                        'courseid' => (int) $course->id,
                    ],
                    '*',
                    MUST_EXIST
                );

                $groupdata->name = $groupname;
                $groupdata->description = $groupdescription;
                $groupdata->descriptionformat = FORMAT_PLAIN;

                groups_update_group(
                    $groupdata,
                    false,
                    false
                );

                $groupoperation = 'UPDATED';
            } else {
                throw new \coding_exception(
                    'Unsupported Phase 7.2 group action: ' . $groupaction
                );
            }

            if ($groupid <= 0) {
                throw new \coding_exception(
                    'Moodle did not return a valid group id.'
                );
            }

            $this->save_group_mapping(
                $clientid,
                $sourcecourse,
                $sourceref,
                $sourceobj,
                $groupid
            );

            $added = [];
            $kept = [];
            $deferred = [];

            foreach ((array) ($plan['memberships'] ?? []) as $membership) {
                $sourceuserid = trim(
                    (string) ($membership['source_user_id'] ?? '')
                );
                $action = (string) ($membership['action'] ?? '');
                $targetuserid = (int) (
                    $membership['target_user_id'] ?? 0
                );

                if ($action === 'DEFER') {
                    $deferred[$sourceuserid] = [
                        'source_user_id' => $sourceuserid,
                        'source_login' => (string) (
                            $membership['source_login'] ?? ''
                        ),
                        'reason' => (string) (
                            $membership['reason'] ?? ''
                        ),
                    ];
                    continue;
                }

                if ($action === 'KEEP') {
                    if ($targetuserid <= 0) {
                        throw new \coding_exception(
                            'Phase 7.2 KEEP membership has no Moodle user id.'
                        );
                    }

                    $kept[$sourceuserid] = [
                        'source_user_id' => $sourceuserid,
                        'target_user_id' => $targetuserid,
                        'target_group_id' => $groupid,
                        'action' => 'KEPT',
                    ];
                    continue;
                }

                if ($action !== 'ADD') {
                    throw new \coding_exception(
                        'Unsupported Phase 7.2 membership action: ' . $action
                    );
                }

                if ($targetuserid <= 0) {
                    throw new \coding_exception(
                        'Phase 7.2 ADD membership has no Moodle user id.'
                    );
                }

                if (groups_is_member($groupid, $targetuserid)) {
                    $kept[$sourceuserid] = [
                        'source_user_id' => $sourceuserid,
                        'target_user_id' => $targetuserid,
                        'target_group_id' => $groupid,
                        'action' => 'KEPT',
                    ];
                    continue;
                }

                $success = groups_add_member(
                    $groupid,
                    $targetuserid
                );

                if (!$success) {
                    throw new \coding_exception(
                        'Moodle refused Phase 7.2 group membership for user '
                        . $targetuserid
                    );
                }

                $added[$sourceuserid] = [
                    'source_user_id' => $sourceuserid,
                    'target_user_id' => $targetuserid,
                    'target_group_id' => $groupid,
                    'action' => 'ADDED',
                ];
            }

            $group = $DB->get_record(
                'groups',
                ['id' => $groupid],
                'id,courseid,name,description,descriptionformat,idnumber',
                MUST_EXIST
            );

            $postplan = (new phase7_group_resolver())->resolve(
                $groupjson,
                $courseid
            );
        } finally {
            if ($originaluser instanceof \stdClass) {
                \core\session\manager::set_user($originaluser);
            }
        }

        return [
            'phase' => '7.2',
            'mode' => 'apply',
            'writes_performed' => true,
            'source' => $plan['source'],
            'source_course' => $plan['source_course'],
            'source_group' => $plan['source_group'],
            'target_course' => $plan['target_course'],
            'target_group' => [
                'id' => (int) $group->id,
                'courseid' => (int) $group->courseid,
                'name' => (string) $group->name,
                'description' => (string) $group->description,
                'idnumber' => (string) $group->idnumber,
            ],
            'group_operation' => $groupoperation,
            'added_memberships' => $added,
            'kept_memberships' => $kept,
            'deferred_memberships' => $deferred,
            'added_membership_count' => count($added),
            'kept_membership_count' => count($kept),
            'deferred_membership_count' => count($deferred),
            'mapping' => [
                'sourceinstance' => $clientid,
                'sourcecourse' => $sourcecourse,
                'sourceref' => $sourceref,
                'sourceobj' => $sourceobj,
                'targettype' => 'group',
                'targetid' => $groupid,
                'status' => 'READY',
            ],
            'membership_policy' => [
                'mode' => 'ADDITIVE',
                'remove_unlisted_target_members' => false,
                'automatically_enrol_group_only_users' => false,
            ],
            'post_apply' => [
                'group_plan' => $postplan['group_plan'],
                'membership_counts' => $postplan['membership_counts'],
                'ready_for_apply' => $postplan['ready_for_apply'],
            ],
        ];
    }

    private function save_group_mapping(
        string $sourceinstance,
        string $sourcecourse,
        string $sourceref,
        string $sourceobj,
        int $targetid
    ): void {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => 'group',
        ];

        $existing = $DB->get_record(
            'local_iliasmigration_map',
            $conditions
        );

        $now = time();
        $record = (object) ($conditions + [
            'sourceversion' => null,
            'sourceobj' => $sourceobj,
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
            return;
        }

        $record->timecreated = $now;
        $DB->insert_record(
            'local_iliasmigration_map',
            $record
        );
    }
}

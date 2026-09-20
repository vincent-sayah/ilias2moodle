<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only Phase 7.2 resolver for ILIAS groups.
 *
 * The resolver never creates groups, users, enrolments or memberships. It only
 * consumes the read-only ILIAS group JSON and persistent Phase 7.1 mappings.
 */
final class phase7_group_resolver {
    public function resolve(string $groupjson, int $courseid): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/enrollib.php');

        $source = $this->read_source($groupjson);

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,shortname,fullname',
            MUST_EXIST
        );

        $clientid = trim((string) ($source['source']['client_id'] ?? ''));
        $sourcecourse = trim((string) ($source['course']['object_id'] ?? ''));
        $sourceref = trim((string) ($source['group']['ref_id'] ?? ''));
        $sourceobj = trim((string) ($source['group']['object_id'] ?? ''));
        $title = trim((string) ($source['group']['title'] ?? ''));
        $description = (string) ($source['group']['description'] ?? '');

        if ($clientid === '' || $sourcecourse === '' || $sourceref === ''
                || $sourceobj === '' || $title === '') {
            throw new \moodle_exception(
                'Phase 7.2 group source is missing required identifiers or title.'
            );
        }

        $groupmapping = $DB->get_record(
            'local_iliasmigration_map',
            [
                'sourcelms' => 'ILIAS',
                'sourceinstance' => $clientid,
                'sourcecourse' => $sourcecourse,
                'sourceref' => $sourceref,
                'targettype' => 'group',
            ],
            'id,targetid,status'
        );

        $targetgroup = null;
        $groupaction = 'CREATE';
        $groupreason = 'NO_PERSISTENT_GROUP_MAPPING';

        if ($groupmapping && (int) $groupmapping->targetid > 0) {
            $candidate = $DB->get_record(
                'groups',
                ['id' => (int) $groupmapping->targetid],
                'id,courseid,name,description,descriptionformat,idnumber'
            );

            if (!$candidate) {
                $groupaction = 'BLOCKED';
                $groupreason = 'MAPPED_TARGET_GROUP_MISSING';
            } else if ((int) $candidate->courseid !== $courseid) {
                $groupaction = 'BLOCKED';
                $groupreason = 'MAPPED_TARGET_GROUP_WRONG_COURSE';
                $targetgroup = $candidate;
            } else {
                $groupaction = 'UPDATE';
                $groupreason = 'PERSISTENT_GROUP_MAPPING_FOUND';
                $targetgroup = $candidate;
            }
        } else {
            $namecollisions = $DB->get_records(
                'groups',
                [
                    'courseid' => $courseid,
                    'name' => $title,
                ],
                'id ASC',
                'id,courseid,name,idnumber'
            );

            if ($namecollisions) {
                $groupaction = 'BLOCKED';
                $groupreason = 'UNMAPPED_GROUP_NAME_COLLISION';
            }
        }

        $context = \context_course::instance($courseid);
        $enrolled = get_enrolled_users(
            $context,
            '',
            0,
            'u.id,u.username'
        );
        $enrolledids = array_fill_keys(
            array_map('intval', array_keys($enrolled)),
            true
        );

        $memberships = is_array($source['group']['memberships'] ?? null)
            ? $source['group']['memberships']
            : [];
        $sourceusers = is_array($source['users'] ?? null)
            ? $source['users']
            : [];

        $plans = [];
        $counts = [
            'add' => 0,
            'keep' => 0,
            'defer' => 0,
            'blocked' => 0,
        ];

        foreach ($memberships as $membership) {
            if (!is_array($membership)) {
                throw new \moodle_exception(
                    'Phase 7.2 group membership entry must be an object.'
                );
            }

            $sourceuserid = trim((string) ($membership['source_user_id'] ?? ''));
            if ($sourceuserid === '') {
                throw new \moodle_exception(
                    'Phase 7.2 group membership has no source_user_id.'
                );
            }

            $sourceuser = is_array($sourceusers[$sourceuserid] ?? null)
                ? $sourceusers[$sourceuserid]
                : [];

            $entry = [
                'source_user_id' => $sourceuserid,
                'source_login' => (string) ($sourceuser['login'] ?? ''),
                'source_group_roles' => array_values(
                    (array) ($membership['source_group_roles'] ?? [])
                ),
                'source_parent_course_roles' => array_values(
                    (array) ($membership['source_parent_course_roles'] ?? [])
                ),
                'source_parent_course_participant' => !empty(
                    $membership['source_parent_course_participant']
                ),
                'target_user_id' => null,
                'target_username' => null,
                'identity_mapping_status' => 'MISSING',
                'target_course_enrolled' => false,
                'action' => 'DEFER',
                'reason' => 'IDENTITY_MAPPING_MISSING',
            ];

            if (!$entry['source_parent_course_participant']) {
                $entry['reason'] = 'SOURCE_NOT_PARENT_COURSE_PARTICIPANT';
                $plans[] = $entry;
                $counts['defer']++;
                continue;
            }

            $usermapping = $DB->get_record(
                'local_iliasmigration_map',
                [
                    'sourcelms' => 'ILIAS',
                    'sourceinstance' => $clientid,
                    'sourcecourse' => 'GLOBAL',
                    'sourceref' => $sourceuserid,
                    'targettype' => 'user',
                ],
                'id,targetid,status'
            );

            if (!$usermapping || (int) $usermapping->targetid <= 0) {
                $plans[] = $entry;
                $counts['defer']++;
                continue;
            }

            $entry['identity_mapping_status'] = (string) $usermapping->status;
            $entry['target_user_id'] = (int) $usermapping->targetid;

            $targetuser = $DB->get_record(
                'user',
                [
                    'id' => (int) $usermapping->targetid,
                    'deleted' => 0,
                ],
                'id,username,suspended'
            );

            if (!$targetuser) {
                $entry['action'] = 'BLOCKED';
                $entry['reason'] = 'MAPPED_TARGET_USER_MISSING';
                $plans[] = $entry;
                $counts['blocked']++;
                continue;
            }

            $entry['target_username'] = (string) $targetuser->username;
            $entry['target_course_enrolled'] = isset(
                $enrolledids[(int) $targetuser->id]
            );

            if (!$entry['target_course_enrolled']) {
                $entry['reason'] = 'TARGET_USER_NOT_ENROLLED';
                $plans[] = $entry;
                $counts['defer']++;
                continue;
            }

            if ($targetgroup) {
                $already = $DB->record_exists(
                    'groups_members',
                    [
                        'groupid' => (int) $targetgroup->id,
                        'userid' => (int) $targetuser->id,
                    ]
                );

                $entry['action'] = $already ? 'KEEP' : 'ADD';
                $entry['reason'] = $already
                    ? 'TARGET_GROUP_MEMBERSHIP_ALREADY_EXISTS'
                    : 'READY_TO_ADD';
            } else {
                $entry['action'] = 'ADD';
                $entry['reason'] = 'READY_TO_ADD_AFTER_GROUP_CREATE';
            }

            $plans[] = $entry;
            $counts[strtolower($entry['action'])]++;
        }

        $structurallyready = $groupaction !== 'BLOCKED'
            && $counts['blocked'] === 0;

        $alloweddeferreasons = [
            'SOURCE_NOT_PARENT_COURSE_PARTICIPANT',
        ];
        $unsafeDefer = false;

        foreach ($plans as $entry) {
            if (($entry['action'] ?? '') !== 'DEFER') {
                continue;
            }

            if (!in_array(
                (string) ($entry['reason'] ?? ''),
                $alloweddeferreasons,
                true
            )) {
                $unsafeDefer = true;
                break;
            }
        }

        $readyforapply = $structurallyready && !$unsafeDefer;

        return [
            'phase' => '7.2',
            'mode' => 'dry-run',
            'writes_performed' => false,
            'source' => $source['source'],
            'source_course' => $source['course'],
            'source_group' => [
                'object_id' => $sourceobj,
                'ref_id' => $sourceref,
                'title' => $title,
                'description' => $description,
                'owner_source_user_id' => $source['group']['owner_source_user_id'] ?? null,
            ],
            'target_course' => [
                'id' => (int) $course->id,
                'shortname' => (string) $course->shortname,
                'fullname' => (string) $course->fullname,
                'enrolled_user_count' => count($enrolledids),
            ],
            'target_group' => $targetgroup ? [
                'id' => (int) $targetgroup->id,
                'courseid' => (int) $targetgroup->courseid,
                'name' => (string) $targetgroup->name,
                'idnumber' => (string) $targetgroup->idnumber,
            ] : null,
            'group_plan' => [
                'action' => $groupaction,
                'reason' => $groupreason,
                'name' => $title,
                'description' => $description,
            ],
            'membership_counts' => $counts,
            'memberships' => $plans,
            'policy' => [
                'requires_phase71_persistent_user_mapping' => true,
                'requires_target_course_enrolment' => true,
                'automatically_enrol_group_only_users' => false,
                'reuse_unmapped_group_by_name' => false,
                'preserve_ilias_group_admin_role_as_moodle_role' => false,
                'ilias_group_admins_become_group_members' => true,
                'allowed_defer_reasons' => $alloweddeferreasons,
            ],
            'structurally_ready' => $structurallyready,
            'ready_for_apply' => $readyforapply,
            'apply_implemented' => true,
        ];
    }

    private function read_source(string $path): array {
        if (!is_file($path) || !is_readable($path)) {
            throw new \moodle_exception(
                'Phase 7.2 group JSON is missing or unreadable: ' . $path
            );
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \moodle_exception(
                'Invalid Phase 7.2 group JSON: ' . $exception->getMessage()
            );
        }

        if (!is_array($decoded)
                || (string) ($decoded['schema_version'] ?? '') !== '1.0'
                || (string) ($decoded['phase'] ?? '') !== '7.2'
                || (string) ($decoded['source']['lms'] ?? '') !== 'ILIAS'
                || (string) ($decoded['group']['type'] ?? '') !== 'grp'
                || !is_array($decoded['group']['memberships'] ?? null)
                || !is_array($decoded['users'] ?? null)) {
            throw new \moodle_exception(
                'Unsupported Phase 7.2 ILIAS group source document.'
            );
        }

        return $decoded;
    }
}

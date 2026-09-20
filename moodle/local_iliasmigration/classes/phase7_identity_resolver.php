<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only Phase 7.1 identity and enrolment resolver.
 */
final class phase7_identity_resolver {
    private const ROLE_MAP = [
        'admin' => 'editingteacher',
        'tutor' => 'teacher',
        'member' => 'student',
    ];

    public function resolve(string $identityjson, int $courseid): array {
        global $DB;

        $source = $this->read_source($identityjson);

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,shortname,fullname',
            MUST_EXIST
        );

        $targetrecords = $DB->get_records_select(
            'user',
            'deleted = 0 AND id > 1',
            [],
            'id ASC',
            'id,username,email,firstname,lastname,idnumber,auth,suspended'
        );

        $targetusers = [];
        foreach ($targetrecords as $user) {
            $targetusers[] = [
                'id' => (int) $user->id,
                'username' => (string) $user->username,
                'email' => (string) $user->email,
                'firstname' => (string) $user->firstname,
                'lastname' => (string) $user->lastname,
                'idnumber' => (string) $user->idnumber,
                'auth' => (string) $user->auth,
                'suspended' => (int) $user->suspended,
            ];
        }

        $sourceusers = is_array($source['users'] ?? null)
            ? $source['users']
            : [];

        $resolutions = (new phase7_identity_policy())->resolve(
            $sourceusers,
            $targetusers
        );

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

        $rolesbyshortname = [];
        foreach ($DB->get_records('role', null, '', 'id,shortname') as $role) {
            $rolesbyshortname[(string) $role->shortname] = (int) $role->id;
        }

        $membershipplan = [];
        $courseroles = is_array($source['course']['roles'] ?? null)
            ? $source['course']['roles']
            : [];

        foreach ($courseroles as $sourcerole => $sourceids) {
            if ($sourcerole === 'subscriber') {
                foreach ((array) $sourceids as $sourceid) {
                    $membershipplan[] = [
                        'source_user_id' => (string) $sourceid,
                        'source_role' => 'subscriber',
                        'target_role' => null,
                        'target_role_id' => null,
                        'identity_status' => $resolutions[(string) $sourceid]['status'] ?? 'UNRESOLVED',
                        'target_user_id' => $resolutions[(string) $sourceid]['target_user_id'] ?? null,
                        'action' => 'DEFER',
                        'reason' => 'ILIAS_SUBSCRIBER_IS_NOT_ACTIVE_MEMBERSHIP',
                    ];
                }
                continue;
            }

            $targetrole = self::ROLE_MAP[$sourcerole] ?? null;
            foreach ((array) $sourceids as $sourceid) {
                $sourceid = (string) $sourceid;
                $identity = $resolutions[$sourceid] ?? [
                    'status' => 'UNRESOLVED',
                    'target_user_id' => null,
                ];

                $entry = [
                    'source_user_id' => $sourceid,
                    'source_role' => (string) $sourcerole,
                    'target_role' => $targetrole,
                    'target_role_id' => $targetrole !== null
                        ? ($rolesbyshortname[$targetrole] ?? null)
                        : null,
                    'identity_status' => (string) ($identity['status'] ?? 'UNRESOLVED'),
                    'target_user_id' => $identity['target_user_id'] ?? null,
                    'action' => 'DEFER',
                    'reason' => 'IDENTITY_NOT_MATCHED',
                ];

                if ($targetrole === null) {
                    $entry['reason'] = 'SOURCE_ROLE_NOT_MAPPED';
                } else if (empty($entry['target_role_id'])) {
                    $entry['reason'] = 'TARGET_ROLE_NOT_AVAILABLE';
                } else if ($entry['identity_status'] === 'MATCHED') {
                    $targetid = (int) $entry['target_user_id'];
                    $entry['action'] = isset($enrolledids[$targetid])
                        ? 'UPDATE'
                        : 'ENROL';
                    $entry['reason'] = isset($enrolledids[$targetid])
                        ? 'TARGET_USER_ALREADY_ENROLLED'
                        : 'READY_TO_ENROL';
                }

                $membershipplan[] = $entry;
            }
        }

        $counts = [
            'matched' => 0,
            'ambiguous' => 0,
            'unresolved' => 0,
            'not_in_target' => 0,
        ];
        foreach ($resolutions as $resolution) {
            $key = strtolower((string) ($resolution['status'] ?? 'unresolved'));
            if (array_key_exists($key, $counts)) {
                $counts[$key]++;
            }
        }

        $membershipcounts = [
            'enrol' => 0,
            'update' => 0,
            'defer' => 0,
        ];
        foreach ($membershipplan as $entry) {
            $key = strtolower((string) ($entry['action'] ?? 'defer'));
            if (array_key_exists($key, $membershipcounts)) {
                $membershipcounts[$key]++;
            }
        }

        return [
            'phase' => '7.1',
            'mode' => 'dry-run',
            'writes_performed' => false,
            'source' => $source['source'] ?? [],
            'source_course' => $source['course'] ?? [],
            'source_group' => $source['group'] ?? [],
            'target_course' => [
                'id' => (int) $course->id,
                'shortname' => (string) $course->shortname,
                'fullname' => (string) $course->fullname,
                'enrolled_user_count' => count($enrolledids),
            ],
            'policy' => [
                'priority' => ['unique_username', 'unique_email_both_sides'],
                'display_name_match_allowed' => false,
                'automatic_user_creation' => false,
                'subscriber_enrolment' => false,
            ],
            'identity_counts' => $counts,
            'membership_counts' => $membershipcounts,
            'identities' => $resolutions,
            'course_memberships' => $membershipplan,
            'ready_for_apply' => $membershipcounts['defer'] === 0,
            'apply_implemented' => false,
        ];
    }

    private function read_source(string $path): array {
        if (!is_file($path) || !is_readable($path)) {
            throw new \moodle_exception(
                'Phase 7 identity JSON is missing or unreadable: ' . $path
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
                'Invalid Phase 7 identity JSON: ' . $exception->getMessage()
            );
        }

        if (!is_array($decoded)
                || (string) ($decoded['schema_version'] ?? '') !== '1.0'
                || (string) ($decoded['source']['lms'] ?? '') !== 'ILIAS'
                || !is_array($decoded['users'] ?? null)
                || !is_array($decoded['course']['roles'] ?? null)) {
            throw new \moodle_exception(
                'Unsupported Phase 7 identity source document.'
            );
        }

        return $decoded;
    }
}

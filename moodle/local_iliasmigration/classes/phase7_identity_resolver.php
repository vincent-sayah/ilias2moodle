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

    public function resolve(
        string $identityjson,
        int $courseid,
        ?string $overridejson = null
    ): array {
        global $DB, $CFG;

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
        $targetbyid = [];
        $targetbyusername = [];
        $targetbyemail = [];

        foreach ($targetrecords as $user) {
            $item = [
                'id' => (int) $user->id,
                'username' => (string) $user->username,
                'email' => (string) $user->email,
                'firstname' => (string) $user->firstname,
                'lastname' => (string) $user->lastname,
                'idnumber' => (string) $user->idnumber,
                'auth' => (string) $user->auth,
                'suspended' => (int) $user->suspended,
            ];
            $targetusers[] = $item;
            $targetbyid[(int) $user->id] = $item;

            $usernamekey = strtolower(trim((string) $user->username));
            if ($usernamekey !== '') {
                $targetbyusername[$usernamekey][] = (int) $user->id;
            }

            $emailkey = strtolower(trim((string) $user->email));
            if ($emailkey !== '') {
                $targetbyemail[$emailkey][] = (int) $user->id;
            }
        }

        $sourceusers = is_array($source['users'] ?? null)
            ? $source['users']
            : [];

        $resolutions = (new phase7_identity_policy())->resolve(
            $sourceusers,
            $targetusers
        );

        $overrides = $overridejson !== null && trim($overridejson) !== ''
            ? $this->read_overrides($overridejson)
            : [];

        foreach ($overrides as $override) {
            $sourceid = (string) ($override['source_user_id'] ?? '');
            $targetid = (int) ($override['target_user_id'] ?? 0);

            if ($sourceid === '' || !isset($sourceusers[$sourceid])) {
                throw new \moodle_exception(
                    'Phase 7 override references unknown ILIAS user: ' . $sourceid
                );
            }

            if ($targetid <= 0 || !isset($targetbyid[$targetid])) {
                throw new \moodle_exception(
                    'Phase 7 override references unknown Moodle user id: ' . $targetid
                );
            }

            $requiresiteadmin = !empty($override['require_site_admin']);
            if ($requiresiteadmin && !is_siteadmin($targetid)) {
                throw new \moodle_exception(
                    'Phase 7 privileged override target is not a Moodle site administrator: '
                    . $targetid
                );
            }

            $target = $targetbyid[$targetid];
            $sourceuser = $sourceusers[$sourceid];

            $resolutions[$sourceid] = [
                'source_user_id' => $sourceid,
                'source_login' => (string) ($sourceuser['login'] ?? ''),
                'source_email' => (string) ($sourceuser['email'] ?? ''),
                'status' => 'MATCHED',
                'match_key' => 'explicit_override',
                'target_user_id' => $targetid,
                'target_username' => (string) ($target['username'] ?? ''),
                'target_email' => (string) ($target['email'] ?? ''),
                'reason' => (string) (
                    $override['reason']
                    ?? 'EXPLICIT_IDENTITY_MAPPING'
                ),
                'privileged_mapping' => $requiresiteadmin,
                'target_is_site_admin' => is_siteadmin($targetid),
            ];
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

        $rolesbyshortname = [];
        foreach ($DB->get_records('role', null, '', 'id,shortname') as $role) {
            $rolesbyshortname[(string) $role->shortname] = (int) $role->id;
        }

        $courseroles = is_array($source['course']['roles'] ?? null)
            ? $source['course']['roles']
            : [];

        $coursememberids = [];
        foreach ($courseroles as $sourcerole => $sourceids) {
            if ($sourcerole === 'subscriber') {
                continue;
            }
            foreach ((array) $sourceids as $sourceid) {
                $coursememberids[(string) $sourceid] = true;
            }
        }

        $creationcandidates = [];
        foreach (array_keys($coursememberids) as $sourceid) {
            $identity = $resolutions[$sourceid] ?? [];
            if (($identity['status'] ?? '') === 'MATCHED') {
                continue;
            }

            $sourceuser = $sourceusers[$sourceid] ?? [];
            $username = strtolower(trim((string) ($sourceuser['login'] ?? '')));
            $email = strtolower(trim((string) ($sourceuser['email'] ?? '')));

            $usernameconflicts = $username !== ''
                ? ($targetbyusername[$username] ?? [])
                : [];
            $emailconflicts = $email !== ''
                ? ($targetbyemail[$email] ?? [])
                : [];

            $status = 'CREATE_CANDIDATE';
            $reason = 'READY_IF_ACCOUNT_CREATION_POLICY_APPROVED';

            if ($username === '') {
                $status = 'BLOCKED';
                $reason = 'SOURCE_USERNAME_MISSING';
            } else if ($usernameconflicts) {
                $status = 'BLOCKED';
                $reason = 'USERNAME_CONFLICT';
            } else if (
                empty($CFG->allowaccountssameemail)
                && $email !== ''
                && $emailconflicts
            ) {
                $status = 'BLOCKED';
                $reason = 'EMAIL_CONFLICT';
            } else if ($email === '') {
                $status = 'BLOCKED';
                $reason = 'SOURCE_EMAIL_MISSING';
            }

            $creationcandidates[$sourceid] = [
                'source_user_id' => $sourceid,
                'proposed_username' => (string) ($sourceuser['login'] ?? ''),
                'source_email' => (string) ($sourceuser['email'] ?? ''),
                'firstname' => (string) ($sourceuser['firstname'] ?? ''),
                'lastname' => (string) ($sourceuser['lastname'] ?? ''),
                'status' => $status,
                'reason' => $reason,
                'username_conflict_target_ids' => $usernameconflicts,
                'email_conflict_target_ids' => $emailconflicts,
                'allow_same_email' => !empty($CFG->allowaccountssameemail),
            ];
        }

        $membershipplan = [];

        foreach ($courseroles as $sourcerole => $sourceids) {
            if ($sourcerole === 'subscriber') {
                foreach ((array) $sourceids as $sourceid) {
                    $membershipplan[] = [
                        'source_user_id' => (string) $sourceid,
                        'source_role' => 'subscriber',
                        'target_role' => null,
                        'target_role_id' => null,
                        'identity_status' => $resolutions[(string) $sourceid]['status']
                            ?? 'UNRESOLVED',
                        'target_user_id' => $resolutions[(string) $sourceid]['target_user_id']
                            ?? null,
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
                } else if (isset($creationcandidates[$sourceid])) {
                    $candidate = $creationcandidates[$sourceid];
                    $entry['creation_candidate_status'] = $candidate['status'];
                    $entry['creation_candidate_reason'] = $candidate['reason'];
                    $entry['reason'] = $candidate['status'] === 'CREATE_CANDIDATE'
                        ? 'USER_CREATION_REQUIRED_BEFORE_ENROL'
                        : 'USER_CREATION_BLOCKED';
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

        $creationcounts = [
            'create_candidate' => 0,
            'blocked' => 0,
        ];

        foreach ($creationcandidates as $candidate) {
            $key = strtolower((string) ($candidate['status'] ?? 'blocked'));
            if (array_key_exists($key, $creationcounts)) {
                $creationcounts[$key]++;
            }
        }

        require_once($CFG->libdir . '/authlib.php');
        $authplugins = get_enabled_auth_plugins();
        $manualauthready = in_array('manual', $authplugins, true);

        $manualenrol = $DB->get_record(
            'enrol',
            [
                'courseid' => $courseid,
                'enrol' => 'manual',
            ]
        );
        $manualenrolready = $manualenrol
            && (int) $manualenrol->status === ENROL_INSTANCE_ENABLED;

        $studentroleready = !empty($rolesbyshortname['student']);

        $readyforapply = $manualauthready
            && $manualenrolready
            && $studentroleready;

        foreach ($membershipplan as $entry) {
            $action = (string) ($entry['action'] ?? 'DEFER');
            if (in_array($action, ['ENROL', 'UPDATE'], true)) {
                continue;
            }

            if ($action === 'DEFER'
                    && ($entry['creation_candidate_status'] ?? '')
                        === 'CREATE_CANDIDATE') {
                continue;
            }

            $readyforapply = false;
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
                'priority' => [
                    'explicit_override',
                    'unique_username',
                    'unique_email_both_sides',
                ],
                'display_name_match_allowed' => false,
                'automatic_user_creation' => false,
                'subscriber_enrolment' => false,
                'allow_same_email' => !empty($CFG->allowaccountssameemail),
                'account_creation_auth' => 'manual',
                'initial_password' => 'GENERATED_RANDOM_NOT_LOGGED',
                'force_password_change' => true,
            ],
            'target_capabilities' => [
                'manual_auth_enabled' => $manualauthready,
                'manual_enrol_enabled' => (bool) $manualenrolready,
                'manual_enrol_id' => $manualenrol ? (int) $manualenrol->id : null,
                'student_role_available' => $studentroleready,
                'student_role_id' => $studentroleready
                    ? (int) $rolesbyshortname['student']
                    : null,
            ],
            'overrides_applied' => $overrides,
            'identity_counts' => $counts,
            'account_creation_counts' => $creationcounts,
            'membership_counts' => $membershipcounts,
            'identities' => $resolutions,
            'account_creation_candidates' => $creationcandidates,
            'course_memberships' => $membershipplan,
            'ready_for_apply' => $readyforapply,
            'apply_implemented' => true,
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

    private function read_overrides(string $path): array {
        if (!is_file($path) || !is_readable($path)) {
            throw new \moodle_exception(
                'Phase 7 override JSON is missing or unreadable: ' . $path
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
                'Invalid Phase 7 override JSON: ' . $exception->getMessage()
            );
        }

        if (!is_array($decoded)
                || (string) ($decoded['schema_version'] ?? '') !== '1.0'
                || !is_array($decoded['mappings'] ?? null)) {
            throw new \moodle_exception(
                'Unsupported Phase 7 identity override document.'
            );
        }

        $seen = [];
        $mappings = [];

        foreach ($decoded['mappings'] as $mapping) {
            if (!is_array($mapping)) {
                throw new \moodle_exception(
                    'Invalid Phase 7 identity override mapping.'
                );
            }

            $sourceid = trim((string) ($mapping['source_user_id'] ?? ''));
            $targetid = (int) ($mapping['target_user_id'] ?? 0);

            if ($sourceid === '' || $targetid <= 0) {
                throw new \moodle_exception(
                    'Phase 7 identity override requires source_user_id and target_user_id.'
                );
            }

            if (isset($seen[$sourceid])) {
                throw new \moodle_exception(
                    'Duplicate Phase 7 identity override for ILIAS user: ' . $sourceid
                );
            }

            $seen[$sourceid] = true;
            $mappings[] = $mapping;
        }

        return $mappings;
    }
}

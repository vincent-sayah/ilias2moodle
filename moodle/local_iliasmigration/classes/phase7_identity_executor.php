<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Apply Phase 7.1 identities and course memberships.
 *
 * Existing users are reused only when the dry-run resolved them reliably or an
 * explicit override did so. Missing course members are created with manual auth,
 * a generated random password that is never returned by this executor, and a
 * forced password change preference.
 */
final class phase7_identity_executor {
    public function execute(
        string $identityjson,
        int $courseid,
        ?string $overridejson = null
    ): array {
        global $CFG, $DB, $USER;

        require_once($CFG->libdir . '/enrollib.php');
        require_once($CFG->libdir . '/moodlelib.php');
        require_once($CFG->libdir . '/authlib.php');
        require_once($CFG->libdir . '/accesslib.php');
        require_once($CFG->dirroot . '/user/lib.php');

        $plan = (new phase7_identity_resolver())->resolve(
            $identityjson,
            $courseid,
            $overridejson
        );

        if (empty($plan['apply_implemented'])
                || empty($plan['ready_for_apply'])) {
            throw new \coding_exception(
                'Phase 7.1 identity plan is not ready for apply.'
            );
        }

        $source = $this->read_json($identityjson, 'identity');
        $sourceusers = is_array($source['users'] ?? null)
            ? $source['users']
            : [];

        $clientid = trim((string) ($source['source']['client_id'] ?? ''));
        if ($clientid === '') {
            throw new \coding_exception(
                'Phase 7.1 source client_id is required for persistent mappings.'
            );
        }

        $sourcecourse = trim((string) ($source['course']['object_id'] ?? ''));
        if ($sourcecourse === '') {
            throw new \coding_exception(
                'Phase 7.1 source course object_id is required.'
            );
        }

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,shortname,fullname',
            MUST_EXIST
        );

        $manualinstance = $DB->get_record(
            'enrol',
            [
                'courseid' => $courseid,
                'enrol' => 'manual',
            ],
            '*',
            MUST_EXIST
        );

        if ((int) $manualinstance->status !== ENROL_INSTANCE_ENABLED) {
            throw new \coding_exception(
                'Manual enrolment instance is disabled for the target course.'
            );
        }

        $manualplugin = enrol_get_plugin('manual');
        if (!$manualplugin) {
            throw new \coding_exception(
                'Moodle manual enrolment plugin is unavailable.'
            );
        }

        $coursecontext = \context_course::instance($courseid);
        $managedroles = [];
        foreach (['editingteacher', 'teacher', 'student'] as $shortname) {
            $role = $DB->get_record(
                'role',
                ['shortname' => $shortname],
                'id,shortname'
            );
            if ($role) {
                $managedroles[(string) $role->shortname] = (int) $role->id;
            }
        }

        $originaluser = $USER;
        \core\session\manager::set_user(get_admin());

        try {
            $transaction = $DB->start_delegated_transaction();

            try {
                $created = [];
                $reused = [];
                $enrolled = [];
                $updated = [];
                $usermap = [];

                foreach ((array) ($plan['identities'] ?? []) as $sourceid => $identity) {
                    if (($identity['status'] ?? '') !== 'MATCHED') {
                        continue;
                    }

                    $targetid = (int) ($identity['target_user_id'] ?? 0);
                    if ($targetid <= 0) {
                        throw new \coding_exception(
                            'MATCHED Phase 7 identity has no Moodle target user id.'
                        );
                    }

                    $this->assert_target_user($targetid);
                    $usermap[(string) $sourceid] = $targetid;
                    $reused[(string) $sourceid] = $targetid;

                    $this->save_mapping(
                        $clientid,
                        'GLOBAL',
                        (string) $sourceid,
                        (string) $sourceid,
                        'user',
                        $targetid
                    );
                }

                foreach (
                    (array) ($plan['account_creation_candidates'] ?? [])
                    as $sourceid => $candidate
                ) {
                    if (($candidate['status'] ?? '') !== 'CREATE_CANDIDATE') {
                        continue;
                    }

                    $sourceid = (string) $sourceid;
                    $sourceuser = $sourceusers[$sourceid] ?? null;
                    if (!is_array($sourceuser)) {
                        throw new \coding_exception(
                            'Missing source user for Phase 7 account creation.'
                        );
                    }

                    $existingmapping = $this->get_user_mapping(
                        $clientid,
                        $sourceid
                    );

                    if ($existingmapping !== null) {
                        $this->assert_target_user($existingmapping);
                        $usermap[$sourceid] = $existingmapping;
                        $reused[$sourceid] = $existingmapping;
                        continue;
                    }

                    $username = clean_param(
                        (string) ($sourceuser['login'] ?? ''),
                        PARAM_USERNAME
                    );
                    $email = trim((string) ($sourceuser['email'] ?? ''));
                    $firstname = clean_param(
                        (string) ($sourceuser['firstname'] ?? ''),
                        PARAM_NOTAGS
                    );
                    $lastname = clean_param(
                        (string) ($sourceuser['lastname'] ?? ''),
                        PARAM_NOTAGS
                    );

                    if ($username === ''
                            || $email === ''
                            || $firstname === ''
                            || $lastname === '') {
                        throw new \coding_exception(
                            'Phase 7 account creation requires username, email, firstname and lastname.'
                        );
                    }

                    $existing = $DB->get_record(
                        'user',
                        [
                            'username' => $username,
                            'mnethostid' => (int) $CFG->mnet_localhost_id,
                            'deleted' => 0,
                        ],
                        'id,username,email'
                    );

                    if ($existing) {
                        $targetid = (int) $existing->id;
                        $reused[$sourceid] = $targetid;
                    } else {
                        $user = (object) [
                            'auth' => 'manual',
                            'confirmed' => 1,
                            'mnethostid' => (int) $CFG->mnet_localhost_id,
                            'username' => $username,
                            'password' => generate_password(32),
                            'firstname' => $firstname,
                            'lastname' => $lastname,
                            'email' => $email,
                            'emailstop' => 0,
                            'city' => '',
                            'country' => '',
                            'lang' => (string) ($CFG->lang ?? 'en'),
                            'timezone' => '99',
                            'description' => '',
                            'descriptionformat' => FORMAT_HTML,
                        ];

                        if (method_exists(\core\user::class, 'create_user')) {
                            $targetid = (int) \core\user::create_user(
                                $user,
                                true,
                                true
                            );
                        } else {
                            $targetid = (int) user_create_user(
                                $user,
                                true,
                                true
                            );
                        }

                        if ($targetid <= 0) {
                            throw new \coding_exception(
                                'Moodle did not return a valid user id.'
                            );
                        }

                        set_user_preference(
                            'auth_forcepasswordchange',
                            1,
                            $targetid
                        );

                        $created[$sourceid] = [
                            'target_user_id' => $targetid,
                            'username' => $username,
                            'email' => $email,
                            'credential_state' => 'ADMIN_RESET_REQUIRED',
                            'force_password_change' => true,
                        ];
                    }

                    $usermap[$sourceid] = $targetid;

                    $this->save_mapping(
                        $clientid,
                        'GLOBAL',
                        $sourceid,
                        $sourceid,
                        'user',
                        $targetid
                    );
                }

                foreach ((array) ($plan['course_memberships'] ?? []) as $membership) {
                    $sourceid = (string) ($membership['source_user_id'] ?? '');
                    if ($sourceid === '') {
                        throw new \coding_exception(
                            'Phase 7 membership has no source user id.'
                        );
                    }

                    if (($membership['source_role'] ?? '') === 'subscriber') {
                        continue;
                    }

                    $targetid = (int) (
                        $usermap[$sourceid]
                        ?? ($membership['target_user_id'] ?? 0)
                    );
                    if ($targetid <= 0) {
                        throw new \coding_exception(
                            'Phase 7 membership has no resolved Moodle user.'
                        );
                    }

                    $this->assert_target_user($targetid);

                    $before = $DB->get_record(
                        'user_enrolments',
                        [
                            'enrolid' => (int) $manualinstance->id,
                            'userid' => $targetid,
                        ],
                        'id,status'
                    );

                    $targetrole = trim((string) ($membership['target_role'] ?? ''));
                    $targetroleid = (int) ($membership['target_role_id'] ?? 0);

                    if ($targetrole === '' || $targetroleid <= 0) {
                        throw new \coding_exception(
                            'Phase 7 membership has no valid target Moodle role.'
                        );
                    }

                    $roleexists = $DB->record_exists(
                        'role',
                        [
                            'id' => $targetroleid,
                            'shortname' => $targetrole,
                        ]
                    );

                    if (!$roleexists) {
                        throw new \coding_exception(
                            'Phase 7 target Moodle role does not exist or does not match: '
                            . $targetrole
                            . ' / '
                            . $targetroleid
                        );
                    }

                    $mappingkey = 'user:' . $sourceid;
                    $membershipowned = $DB->record_exists(
                        'local_iliasmigration_map',
                        [
                            'sourcelms' => 'ILIAS',
                            'sourceinstance' => $clientid,
                            'sourcecourse' => $sourcecourse,
                            'sourceref' => $mappingkey,
                            'targettype' => 'enrolment',
                        ]
                    );

                    if ($membershipowned) {
                        foreach ($managedroles as $managedroleid) {
                            if ((int) $managedroleid === $targetroleid) {
                                continue;
                            }

                            if ($DB->record_exists(
                                'role_assignments',
                                [
                                    'roleid' => (int) $managedroleid,
                                    'userid' => $targetid,
                                    'contextid' => (int) $coursecontext->id,
                                    'component' => '',
                                    'itemid' => 0,
                                ]
                            )) {
                                role_unassign(
                                    (int) $managedroleid,
                                    $targetid,
                                    (int) $coursecontext->id
                                );
                            }
                        }
                    }

                    $manualplugin->enrol_user(
                        $manualinstance,
                        $targetid,
                        $targetroleid,
                        0,
                        0,
                        ENROL_USER_ACTIVE
                    );

                    $after = $DB->get_record(
                        'user_enrolments',
                        [
                            'enrolid' => (int) $manualinstance->id,
                            'userid' => $targetid,
                        ],
                        'id,status',
                        MUST_EXIST
                    );

                    $this->save_mapping(
                        $clientid,
                        $sourcecourse,
                        $mappingkey,
                        $sourceid,
                        'enrolment',
                        (int) $after->id
                    );

                    $entry = [
                        'source_user_id' => $sourceid,
                        'source_role' => (string) ($membership['source_role'] ?? ''),
                        'target_user_id' => $targetid,
                        'target_role' => $targetrole,
                        'target_role_id' => $targetroleid,
                        'enrolment_id' => (int) $after->id,
                    ];

                    if ($before) {
                        $entry['action'] = 'UPDATED';
                        $updated[$sourceid] = $entry;
                    } else {
                        $entry['action'] = 'ENROLLED';
                        $enrolled[$sourceid] = $entry;
                    }
                }

                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
        } finally {
            if ($originaluser instanceof \stdClass) {
                \core\session\manager::set_user($originaluser);
            }
        }

        $result = $plan;
        $result['mode'] = 'apply';
        $result['writes_performed'] = true;
        $result['apply_implemented'] = true;
        $result['ready_for_apply'] = true;
        $result['created_users'] = $created;
        $result['reused_users'] = $reused;
        $result['enrolled_memberships'] = $enrolled;
        $result['updated_memberships'] = $updated;
        $result['user_map'] = $usermap;
        $result['created_user_count'] = count($created);
        $result['reused_user_count'] = count($reused);
        $result['enrolled_membership_count'] = count($enrolled);
        $result['updated_membership_count'] = count($updated);
        $result['credential_policy'] = [
            'auth' => 'manual',
            'password' => 'GENERATED_RANDOM_NOT_LOGGED',
            'force_password_change' => true,
            'delivery' => 'ADMIN_RESET_REQUIRED',
        ];

        return $result;
    }

    private function read_json(string $path, string $label): array {
        if (!is_file($path) || !is_readable($path)) {
            throw new \coding_exception(
                "Phase 7 {$label} JSON is missing or unreadable."
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
            throw new \coding_exception(
                "Invalid Phase 7 {$label} JSON: " . $exception->getMessage()
            );
        }

        if (!is_array($decoded)) {
            throw new \coding_exception(
                "Phase 7 {$label} JSON must decode to an object."
            );
        }

        return $decoded;
    }

    private function assert_target_user(int $userid): void {
        global $DB;

        $exists = $DB->record_exists(
            'user',
            [
                'id' => $userid,
                'deleted' => 0,
            ]
        );

        if (!$exists) {
            throw new \coding_exception(
                'Phase 7 target user does not exist or is deleted: ' . $userid
            );
        }
    }

    private function get_user_mapping(
        string $sourceinstance,
        string $sourceuserid
    ): ?int {
        global $DB;

        $record = $DB->get_record(
            'local_iliasmigration_map',
            [
                'sourcelms' => 'ILIAS',
                'sourceinstance' => $sourceinstance,
                'sourcecourse' => 'GLOBAL',
                'sourceref' => $sourceuserid,
                'targettype' => 'user',
            ],
            'id,targetid'
        );

        if (!$record || (int) $record->targetid <= 0) {
            return null;
        }

        return (int) $record->targetid;
    }

    private function save_mapping(
        string $sourceinstance,
        string $sourcecourse,
        string $sourceref,
        string $sourceobj,
        string $targettype,
        int $targetid
    ): void {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => $targettype,
        ];

        $existing = $DB->get_record(
            'local_iliasmigration_map',
            $conditions
        );

        $now = time();
        $record = (object) ($conditions + [
            'sourceversion' => null,
            'sourceobj' => $sourceobj !== '' ? $sourceobj : null,
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

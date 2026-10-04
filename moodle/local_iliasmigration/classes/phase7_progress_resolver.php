<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only Phase 7.3 progress/results classification resolver.
 *
 * Consumes the read-only inventories produced on the ILIAS side and joins
 * them with persistent Moodle mappings. It never writes completion, grades,
 * attempts or mappings.
 */
final class phase7_progress_resolver {
    public function resolve(
        string $progressjson,
        string $testjson,
        string $scorm719json,
        string $scorm720json,
        string $exercisejson,
        int $courseid
    ): array {
        return $this->resolve_many(
            $progressjson,
            [$testjson],
            [$scorm719json, $scorm720json],
            [$exercisejson],
            $courseid
        );
    }

    public function resolve_many(
        string $progressjson,
        array $testjsons,
        array $scormjsons,
        array $exercisejsons,
        int $courseid
    ): array {
        global $DB;

        $progress = $this->read_json($progressjson, 'progress');

        $tests = [];
        foreach ($testjsons as $path) {
            $path = trim((string) $path);
            if ($path !== '') {
                $tests[] = $this->read_json($path, 'test');
            }
        }

        $scorms = [];
        foreach ($scormjsons as $path) {
            $path = trim((string) $path);
            if ($path !== '') {
                $scorms[] = $this->read_json($path, 'scorm');
            }
        }

        $exercises = [];
        foreach ($exercisejsons as $path) {
            $path = trim((string) $path);
            if ($path !== '') {
                $exercises[] = $this->read_json($path, 'exercise');
            }
        }

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,shortname,fullname',
            MUST_EXIST
        );

        $clientid = trim((string) ($progress['source']['client_id'] ?? ''));
        $sourcecourse = trim((string) ($progress['course']['object_id'] ?? ''));
        $sourcecourseref = trim((string) ($progress['course']['ref_id'] ?? ''));

        if ($clientid === '' || $sourcecourse === ''
                || $sourcecourseref === '') {
            throw new \moodle_exception(
                'Phase 7.3 progress inventory is missing client/course identifiers.'
            );
        }

        $sourceusers = is_array($progress['users'] ?? null)
            ? $progress['users']
            : [];

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

        $usermappings = [];
        foreach ($sourceusers as $sourceuserid => $sourceuser) {
            $sourceuserid = (string) $sourceuserid;

            $mapping = $DB->get_record(
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

            $targetuser = null;
            if ($mapping && (int) $mapping->targetid > 0) {
                $targetuser = $DB->get_record(
                    'user',
                    [
                        'id' => (int) $mapping->targetid,
                        'deleted' => 0,
                    ],
                    'id,username,suspended'
                );
            }

            $usermappings[$sourceuserid] = [
                'source_user_id' => $sourceuserid,
                'source_login' => (string) ($sourceuser['login'] ?? ''),
                'mapping_found' => (bool) $mapping,
                'mapping_status' => $mapping
                    ? (string) $mapping->status
                    : 'MISSING',
                'target_user_id' => $mapping
                    ? (int) $mapping->targetid
                    : null,
                'target_username' => $targetuser
                    ? (string) $targetuser->username
                    : null,
                'target_user_exists' => (bool) $targetuser,
                'target_course_enrolled' => $targetuser
                    ? isset($enrolledids[(int) $targetuser->id])
                    : false,
            ];
        }

        $objectmappings = [];
        $classifications = [];

        foreach ((array) ($progress['objects'] ?? []) as $object) {
            if (!is_array($object) || empty($object['lp_active'])) {
                continue;
            }

            $sourceobj = trim((string) ($object['object_id'] ?? ''));
            $sourceref = trim((string) ($object['ref_id'] ?? ''));
            $type = trim((string) ($object['type'] ?? ''));
            $title = (string) ($object['title'] ?? '');
            $mode = (string) ($object['lp_mode_name'] ?? '');

            $mappingresolution = $this->resolve_object_mappings(
                $clientid,
                $sourcecourse,
                $sourcecourseref,
                $sourceref,
                $sourceobj,
                $type === 'exc'
            );
            $maps = $mappingresolution['mappings'];

            $maprows = [];
            foreach ($maps as $map) {
                $maprows[] = [
                    'mapping_id' => (int) $map->id,
                    'sourceobj' => (string) $map->sourceobj,
                    'targettype' => (string) $map->targettype,
                    'targetid' => $map->targetid !== null
                        ? (int) $map->targetid
                        : null,
                    'status' => (string) $map->status,
                ];
            }

            $objectkey = $sourceobj . '/' . $sourceref;
            $objectmappings[$objectkey] = [
                'source_object_id' => $sourceobj,
                'source_ref_id' => $sourceref,
                'type' => $type,
                'title' => $title,
                'lp_mode' => $mode,
                'mapping_count' => count($maprows),
                'mapping_resolution' => (string) $mappingresolution['resolution'],
                'mapping_sourceinstance' => $mappingresolution['sourceinstance'],
                'mapping_sourcecourse' => $mappingresolution['sourcecourse'],
                'mapping_ambiguous' => (bool) $mappingresolution['ambiguous'],
                'mapping_candidate_count' => (int) $mappingresolution['candidate_count'],
                'mappings' => $maprows,
            ];

            foreach ((array) ($object['records'] ?? []) as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $status = (string) ($record['status_name'] ?? 'unknown');
                $sourceuserid = (string) ($record['source_user_id'] ?? '');

                $classification = 'UNSUPPORTED';
                $reason = 'LP_STATUS_SEMANTICS_NOT_VALIDATED';

                if ($status === 'not_attempted') {
                    $classification = 'NO_DATA';
                    $reason = 'NOT_ATTEMPTED_REQUIRES_NO_MOODLE_WRITE';
                } else if (
                    $type === 'crs'
                    && $status === 'in_progress'
                    && $mode === 'LP_MODE_MANUAL_BY_TUTOR'
                ) {
                    $classification = 'HISTORY_ONLY';
                    $reason = 'MANUAL_TUTOR_COURSE_IN_PROGRESS_HAS_NO_SAFE_MOODLE_EQUIVALENT';
                } else if ($status === 'completed' || $status === 'failed') {
                    $classification = count($maprows) > 0
                        ? 'PARTIAL'
                        : 'UNSUPPORTED';
                    $reason = count($maprows) > 0
                        ? 'TARGET_MAPPING_EXISTS_BUT_COMPLETION_SEMANTICS_REQUIRE_VALIDATION'
                        : 'NO_TARGET_OBJECT_MAPPING';
                }

                $classifications[] = [
                    'source_kind' => 'learning_progress',
                    'source_object_id' => $sourceobj,
                    'source_ref_id' => $sourceref,
                    'source_type' => $type,
                    'source_title' => $title,
                    'source_user_id' => $sourceuserid,
                    'source_login' => (string) ($record['source_login'] ?? ''),
                    'source_status' => $status,
                    'percentage' => $record['percentage'] ?? null,
                    'status_changed' => $record['status_changed'] ?? null,
                    'classification' => $classification,
                    'reason' => $reason,
                    'user_mapping' => $usermappings[$sourceuserid] ?? null,
                    'object_mapping_count' => count($maprows),
                ];
            }
        }

        $detailed = [];

        foreach ($tests as $test) {
            $detailed[] = $this->classify_test($test);
        }

        foreach ($scorms as $scorm) {
            $detailed[] = $this->classify_scorm($scorm);
        }

        foreach ($exercises as $exercise) {
            $detailed[] = $this->classify_exercise($exercise);
        }

        foreach ($detailed as $item) {
            $ref = (string) ($item['source_ref_id'] ?? '');
            $detailsourceobj = (string) ($item['source_object_id'] ?? '');
            $allowchildprefix =
                ($item['source_kind'] ?? '') === 'exercise_results';

            $mappingresolution = $this->resolve_object_mappings(
                $clientid,
                $sourcecourse,
                $sourcecourseref,
                $ref,
                $detailsourceobj,
                $allowchildprefix
            );
            $maps = $mappingresolution['mappings'];

            $item['object_mapping_count'] = count($maps);
            $item['object_mapping_resolution'] = (string) $mappingresolution['resolution'];
            $item['object_mapping_sourceinstance'] = $mappingresolution['sourceinstance'];
            $item['object_mapping_sourcecourse'] = $mappingresolution['sourcecourse'];
            $item['object_mapping_ambiguous'] = (bool) $mappingresolution['ambiguous'];
            $item['object_mapping_candidate_count'] = (int) $mappingresolution['candidate_count'];
            $item['object_mappings'] = array_values(array_map(
                static function($map): array {
                    return [
                        'mapping_id' => (int) $map->id,
                        'sourceobj' => (string) $map->sourceobj,
                        'targettype' => (string) $map->targettype,
                        'targetid' => $map->targetid !== null
                            ? (int) $map->targetid
                            : null,
                        'status' => (string) $map->status,
                    ];
                },
                $maps
            ));

            $classifications[] = $item;
        }

        $counts = [
            'MIGRATE' => 0,
            'PARTIAL' => 0,
            'HISTORY_ONLY' => 0,
            'UNSUPPORTED' => 0,
            'NO_DATA' => 0,
        ];

        foreach ($classifications as $entry) {
            $key = (string) ($entry['classification'] ?? 'UNSUPPORTED');
            if (!array_key_exists($key, $counts)) {
                $counts['UNSUPPORTED']++;
            } else {
                $counts[$key]++;
            }
        }

        $identityaudit = [
            'total' => count($usermappings),
            'mapped' => 0,
            'target_exists' => 0,
            'enrolled' => 0,
            'issues' => 0,
        ];

        foreach ($usermappings as $entry) {
            if (!empty($entry['mapping_found'])) {
                $identityaudit['mapped']++;
            }
            if (!empty($entry['target_user_exists'])) {
                $identityaudit['target_exists']++;
            }
            if (!empty($entry['target_course_enrolled'])) {
                $identityaudit['enrolled']++;
            }
            if (empty($entry['mapping_found'])
                    || empty($entry['target_user_exists'])
                    || empty($entry['target_course_enrolled'])) {
                $identityaudit['issues']++;
            }
        }

        return [
            'phase' => '7.3',
            'mode' => 'dry-run',
            'writes_performed' => false,
            'source' => [
                'lms' => 'ILIAS',
                'client_id' => $clientid,
                'course_object_id' => $sourcecourse,
                'course_ref_id' => (string) ($progress['course']['ref_id'] ?? ''),
            ],
            'target_course' => [
                'id' => (int) $course->id,
                'shortname' => (string) $course->shortname,
                'fullname' => (string) $course->fullname,
                'enrolled_user_count' => count($enrolledids),
            ],
            'policy' => [
                'not_attempted' => 'NO_DATA',
                'manual_tutor_course_in_progress' => 'HISTORY_ONLY',
                'completed_or_failed_without_validated_semantics' => 'PARTIAL_OR_UNSUPPORTED',
                'detailed_result_without_source_data' => 'NO_DATA',
                'automatic_apply' => false,
            ],
            'identity_audit' => $identityaudit,
            'user_mappings' => $usermappings,
            'object_mappings' => $objectmappings,
            'classification_counts' => $counts,
            'classifications' => $classifications,
            'ready_for_apply' => false,
            'apply_implemented' => false,
            'apply_reason' => 'POC_HAS_NO_MIGRATABLE_PHASE73_DATA',
        ];
    }

    /**
     * Resolve object mappings across the identifiers used by historical phases.
     *
     * Exact client/course matches are preferred. Cross-instance fallbacks are
     * accepted only when all candidates belong to one sourceinstance/sourcecourse
     * pair. Exercise child mappings may additionally resolve ref:assignment:*
     * entries when explicitly enabled.
     */
    private function resolve_object_mappings(
        string $clientid,
        string $sourcecourse,
        string $sourcecourseref,
        string $sourceref,
        string $sourceobj = '',
        bool $allowchildprefix = false
    ): array {
        global $DB;

        $fields =
            'id,sourceinstance,sourcecourse,sourceobj,targettype,targetid,status';

        $exact = $DB->get_records(
            'local_iliasmigration_map',
            [
                'sourcelms' => 'ILIAS',
                'sourceinstance' => $clientid,
                'sourcecourse' => $sourcecourse,
                'sourceref' => $sourceref,
            ],
            'id ASC',
            $fields
        );

        if ($exact) {
            return [
                'mappings' => $exact,
                'resolution' => 'EXACT_CLIENT_AND_COURSE_OBJECT_ID',
                'sourceinstance' => $clientid,
                'sourcecourse' => $sourcecourse,
                'ambiguous' => false,
                'candidate_count' => count($exact),
            ];
        }

        $exactref = $DB->get_records(
            'local_iliasmigration_map',
            [
                'sourcelms' => 'ILIAS',
                'sourceinstance' => $clientid,
                'sourcecourse' => $sourcecourseref,
                'sourceref' => $sourceref,
            ],
            'id ASC',
            $fields
        );

        if ($exactref) {
            return [
                'mappings' => $exactref,
                'resolution' => 'EXACT_CLIENT_AND_COURSE_REF_ID',
                'sourceinstance' => $clientid,
                'sourcecourse' => $sourcecourseref,
                'ambiguous' => false,
                'candidate_count' => count($exactref),
            ];
        }

        $candidates = $DB->get_records_select(
            'local_iliasmigration_map',
            'sourcelms = ? AND sourceref = ?'
                . ' AND (sourcecourse = ? OR sourcecourse = ?)',
            [
                'ILIAS',
                $sourceref,
                $sourcecourse,
                $sourcecourseref,
            ],
            'id ASC',
            $fields
        );

        if (!$candidates
                && $allowchildprefix
                && $sourceobj !== '') {
            $childlike = $DB->sql_like_escape($sourceref . ':') . '%';

            $childcandidates = $DB->get_records_select(
                'local_iliasmigration_map',
                'sourcelms = ? AND sourceobj = ? AND '
                    . $DB->sql_like('sourceref', '?')
                    . ' AND (sourcecourse = ? OR sourcecourse = ?)',
                [
                    'ILIAS',
                    $sourceobj,
                    $childlike,
                    $sourcecourse,
                    $sourcecourseref,
                ],
                'id ASC',
                $fields
            );

            if ($childcandidates) {
                $childgroups = [];

                foreach ($childcandidates as $row) {
                    $key = (string) $row->sourceinstance
                        . "\n"
                        . (string) $row->sourcecourse;
                    $childgroups[$key][] = $row;
                }

                if (count($childgroups) !== 1) {
                    return [
                        'mappings' => [],
                        'resolution' => 'AMBIGUOUS_CHILD_PREFIX_MAPPING',
                        'sourceinstance' => null,
                        'sourcecourse' => null,
                        'ambiguous' => true,
                        'candidate_count' => count($childcandidates),
                    ];
                }

                $resolvedchildren = reset($childgroups);
                $firstchild = reset($resolvedchildren);

                return [
                    'mappings' => $resolvedchildren,
                    'resolution' => 'UNIQUE_CHILD_PREFIX_MAPPING',
                    'sourceinstance' => (string) $firstchild->sourceinstance,
                    'sourcecourse' => (string) $firstchild->sourcecourse,
                    'ambiguous' => false,
                    'candidate_count' => count($resolvedchildren),
                ];
            }
        }

        if (!$candidates) {
            return [
                'mappings' => [],
                'resolution' => 'MISSING',
                'sourceinstance' => null,
                'sourcecourse' => null,
                'ambiguous' => false,
                'candidate_count' => 0,
            ];
        }

        $groups = [];
        foreach ($candidates as $row) {
            $key = (string) $row->sourceinstance
                . "\n"
                . (string) $row->sourcecourse;
            $groups[$key][] = $row;
        }

        if (count($groups) !== 1) {
            return [
                'mappings' => [],
                'resolution' => 'AMBIGUOUS_FALLBACK_MAPPING',
                'sourceinstance' => null,
                'sourcecourse' => null,
                'ambiguous' => true,
                'candidate_count' => count($candidates),
            ];
        }

        $resolved = reset($groups);
        $first = reset($resolved);

        return [
            'mappings' => $resolved,
            'resolution' => 'UNIQUE_FALLBACK_MAPPING',
            'sourceinstance' => (string) $first->sourceinstance,
            'sourcecourse' => (string) $first->sourcecourse,
            'ambiguous' => false,
            'candidate_count' => count($resolved),
        ];
    }

    private function classify_test(array $source): array {
        $counts = (array) ($source['counts'] ?? []);
        $hasdata = (int) ($counts['test_participants_with_active_id'] ?? 0) > 0
            || (int) ($counts['scored_participants'] ?? 0) > 0;

        return [
            'source_kind' => 'test_results',
            'source_object_id' => (string) ($source['test']['object_id'] ?? ''),
            'source_ref_id' => (string) ($source['test']['ref_id'] ?? ''),
            'source_type' => 'tst',
            'source_title' => (string) ($source['test']['title'] ?? ''),
            'classification' => $hasdata ? 'PARTIAL' : 'NO_DATA',
            'reason' => $hasdata
                ? 'TEST_RESULTS_PRESENT_REQUIRE_ATTEMPT_SEMANTICS_VALIDATION'
                : 'NO_TEST_ATTEMPTS_OR_SCORES',
            'source_counts' => $counts,
        ];
    }

    private function classify_scorm(array $source): array {
        $counts = (array) ($source['counts'] ?? []);
        $hasdata = (int) ($counts['tracked_users_in_course'] ?? 0) > 0
            || (int) ($counts['users_with_sco_data'] ?? 0) > 0
            || (int) ($counts['total_attempts'] ?? 0) > 0
            || (int) ($counts['sco_tracking_records'] ?? 0) > 0;

        return [
            'source_kind' => 'scorm_results',
            'source_object_id' => (string) ($source['scorm']['object_id'] ?? ''),
            'source_ref_id' => (string) ($source['scorm']['ref_id'] ?? ''),
            'source_type' => 'sahs',
            'source_title' => (string) ($source['scorm']['title'] ?? ''),
            'source_subtype' => (string) ($source['scorm']['subtype'] ?? ''),
            'classification' => $hasdata ? 'PARTIAL' : 'NO_DATA',
            'reason' => $hasdata
                ? 'SCORM_TRACKING_PRESENT_REQUIRES_RUNTIME_SEMANTICS_VALIDATION'
                : 'NO_SCORM_TRACKING_ATTEMPTS_SCO_DATA_OR_SCORES',
            'source_counts' => $counts,
        ];
    }

    private function classify_exercise(array $source): array {
        $counts = (array) ($source['counts'] ?? []);
        $hasdata = (int) ($counts['status_rows'] ?? 0) > 0
            || (int) ($counts['submitted_records'] ?? 0) > 0
            || (int) ($counts['passed'] ?? 0) > 0
            || (int) ($counts['failed'] ?? 0) > 0
            || (int) ($counts['marks'] ?? 0) > 0
            || (int) ($counts['comments'] ?? 0) > 0;

        return [
            'source_kind' => 'exercise_results',
            'source_object_id' => (string) ($source['exercise']['object_id'] ?? ''),
            'source_ref_id' => (string) ($source['exercise']['ref_id'] ?? ''),
            'source_type' => 'exc',
            'source_title' => (string) ($source['exercise']['title'] ?? ''),
            'classification' => $hasdata ? 'PARTIAL' : 'NO_DATA',
            'reason' => $hasdata
                ? 'EXERCISE_SUBMISSION_OR_GRADING_DATA_PRESENT_REQUIRES_MAPPING_VALIDATION'
                : 'NO_EXERCISE_SUBMISSIONS_GRADES_MARKS_OR_COMMENTS',
            'source_counts' => $counts,
        ];
    }

    private function read_json(string $path, string $kind): array {
        if (!is_file($path) || !is_readable($path)) {
            throw new \moodle_exception(
                'Phase 7.3 ' . $kind . ' JSON is missing or unreadable: ' . $path
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
                'Invalid Phase 7.3 ' . $kind . ' JSON: ' . $exception->getMessage()
            );
        }

        if (!is_array($decoded)
                || (string) ($decoded['schema_version'] ?? '') !== '1.0'
                || (string) ($decoded['phase'] ?? '') !== '7.3'
                || (string) ($decoded['source']['lms'] ?? '') !== 'ILIAS') {
            throw new \moodle_exception(
                'Unsupported Phase 7.3 ' . $kind . ' source document.'
            );
        }

        return $decoded;
    }
}
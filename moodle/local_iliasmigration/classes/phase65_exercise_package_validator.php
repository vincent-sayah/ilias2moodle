<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only validator/planner for ILIAS Exercise -> Moodle Assignment.
 *
 * The Exercise object is a logical container. Each ILIAS exc_assignment is
 * planned independently as one Moodle mod_assign candidate.
 */
final class phase65_exercise_package_validator {
    private string $packageroot;
    private string $sourceinstance = '';
    private string $sourcecourse = '';
    private int $targetcourseid = 0;

    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        if ($root === false || !is_dir($root)) {
            throw new \coding_exception('Unable to resolve the migration package directory.');
        }
        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
    }

    public function validate(array $plan): array {
        global $CFG, $DB;

        $assignmodule = $DB->get_record('modules', ['name' => 'assign'], 'id,name,visible');
        $assignavailable = $assignmodule && (int) $assignmodule->visible === 1;

        $submissionplugins = \core_component::get_plugin_list('assignsubmission');
        $fileplugin = isset($submissionplugins['file']);
        $onlinetextplugin = isset($submissionplugins['onlinetext']);

        $plan['moodle']['assign_available'] = $assignavailable;
        $plan['moodle']['assignsubmission_file_available'] = $fileplugin;
        $plan['moodle']['assignsubmission_onlinetext_available'] = $onlinetextplugin;

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $this->sourcecourse = (string) ($plan['course']['source_id'] ?? '');
        $this->targetcourseid = (int) ($plan['operations'][0]['target_id'] ?? 0);

        $checked = 0;
        $blocked = 0;
        $unitcount = 0;
        $unitcreates = 0;
        $unitupdates = 0;
        $unitblocked = 0;
        $phase7dependencies = 0;
        $instructionfiles = 0;

        foreach ($plan['operations'] as &$operation) {
            if ((string) ($operation['kind'] ?? '') !== 'exercise') {
                continue;
            }

            $checked++;
            $operation['phase'] = '6.5.4';
            $operation['moodle_module'] = 'assign';
            $operation['migration_structure_path'] =
                'exercises/' . (string) ($operation['source_ref_id'] ?? '') . '/structure.json';

            if (!$assignavailable) {
                $this->block(
                    $operation,
                    'ASSIGN_MODULE_DISABLED',
                    'Moodle mod_assign is missing or disabled.'
                );
                $blocked++;
                continue;
            }

            $parent = $this->validate_parent_target($operation);
            if (empty($parent['ready'])) {
                $this->block(
                    $operation,
                    'EXERCISE_PARENT_MAPPING_INVALID',
                    (string) ($parent['message'] ?? 'Exercise parent mapping is invalid.')
                );
                $blocked++;
                continue;
            }
            $operation['exercise_parent_validation'] = $parent;

            $summary = $this->validate_exercise(
                $operation,
                $fileplugin,
                $onlinetextplugin
            );
            $unitcount += (int) ($summary['units'] ?? 0);
            $unitcreates += (int) ($summary['creates'] ?? 0);
            $unitupdates += (int) ($summary['updates'] ?? 0);
            $unitblocked += (int) ($summary['blocked'] ?? 0);
            $phase7dependencies += (int) ($summary['phase7_dependencies'] ?? 0);
            $instructionfiles += (int) ($summary['instruction_files'] ?? 0);

            if (($operation['action'] ?? '') === 'BLOCKED') {
                $blocked++;
            }
        }
        unset($operation);

        if ($checked === 0) {
            $plan['warnings'][] = [
                'code' => 'NO_EXERCISE_FOUND',
                'message' => 'No ILIAS Exercise operation was found in this package.',
            ];
        }
        if (!$assignavailable) {
            $plan['warnings'][] = [
                'code' => 'ASSIGN_MODULE_DISABLED',
                'message' => 'Moodle mod_assign is missing or disabled.',
            ];
        }
        if (!$fileplugin) {
            $plan['warnings'][] = [
                'code' => 'ASSIGN_FILE_PLUGIN_UNAVAILABLE',
                'message' => 'assignsubmission_file is unavailable.',
            ];
        }
        if (!$onlinetextplugin) {
            $plan['warnings'][] = [
                'code' => 'ASSIGN_ONLINETEXT_PLUGIN_UNAVAILABLE',
                'message' => 'assignsubmission_onlinetext is unavailable.',
            ];
        }

        $ready = $checked > 0 && $blocked === 0 && $assignavailable;
        $plan['phase65_exercise_package'] = [
            'root' => $this->packageroot,
            'checked_exercises' => $checked,
            'blocked_exercises' => $blocked,
            'unit_count' => $unitcount,
            'unit_create_count' => $unitcreates,
            'unit_update_count' => $unitupdates,
            'unit_blocked_count' => $unitblocked,
            'phase7_dependency_count' => $phase7dependencies,
            'instruction_file_count' => $instructionfiles,
            'assign_available' => $assignavailable,
            'assignsubmission_file_available' => $fileplugin,
            'assignsubmission_onlinetext_available' => $onlinetextplugin,
            'user_data_policy' => 'DEFER_TO_PHASE_7',
            'multi_unit_strategy' => 'ONE_MOD_ASSIGN_PER_UNIT_IN_PARENT_SECTION',
            'ready' => $ready,
            'apply_implemented' => true,
            'apply_ready' => $ready && $unitblocked === 0,
        ];

        if ($phase7dependencies > 0) {
            $plan['warnings'][] = [
                'code' => 'EXERCISE_PHASE7_GROUP_DEPENDENCY',
                'message' => 'Team Assignment structure can be created in Phase 6.5.4, but actual team memberships remain deferred to Phase 7.',
            ];
        }

        return $plan;
    }

    private function validate_exercise(
        array &$operation,
        bool $fileplugin,
        bool $onlinetextplugin
    ): array {
        $structurefile = $this->resolve_relative_file(
            (string) ($operation['migration_structure_path'] ?? '')
        );
        if ($structurefile === null) {
            $this->block(
                $operation,
                'EXERCISE_STRUCTURE_MISSING',
                'Normalized Exercise structure.json is missing.'
            );
            return $this->empty_summary();
        }

        $raw = file_get_contents($structurefile);
        if ($raw === false) {
            $this->block($operation, 'EXERCISE_STRUCTURE_UNREADABLE', 'Cannot read structure.json.');
            return $this->empty_summary();
        }
        try {
            $structure = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->block(
                $operation,
                'EXERCISE_STRUCTURE_INVALID_JSON',
                $exception->getMessage()
            );
            return $this->empty_summary();
        }
        if (!is_array($structure)
                || (string) ($structure['schema_version'] ?? '') !== '1.0') {
            $this->block(
                $operation,
                'EXERCISE_SCHEMA_UNSUPPORTED',
                'Exercise structure must use schema_version 1.0.'
            );
            return $this->empty_summary();
        }

        $source = is_array($structure['source'] ?? null) ? $structure['source'] : [];
        if ((string) ($source['lms'] ?? '') !== 'ILIAS'
                || (string) ($source['ref_id'] ?? '') !== (string) ($operation['source_ref_id'] ?? '')) {
            $this->block(
                $operation,
                'EXERCISE_SOURCE_MISMATCH',
                'Exercise source identity does not match the planned object.'
            );
            return $this->empty_summary();
        }

        $policy = is_array($structure['user_data_policy'] ?? null)
            ? $structure['user_data_policy']
            : [];
        foreach ([
            'submissions_migrated',
            'grades_migrated',
            'tutor_feedback_migrated',
            'peer_feedback_migrated',
            'team_memberships_migrated',
        ] as $field) {
            if (!array_key_exists($field, $policy) || $policy[$field] !== false) {
                $this->block(
                    $operation,
                    'EXERCISE_USER_DATA_POLICY_INVALID',
                    'Phase 6.5.4 must explicitly defer user submissions/grades/feedback/teams to Phase 7.'
                );
                return $this->empty_summary();
            }
        }

        $assignments = is_array($structure['assignments'] ?? null)
            ? $structure['assignments']
            : [];
        if (!$assignments) {
            $this->block($operation, 'EXERCISE_EMPTY', 'Exercise contains no assignments.');
            return $this->empty_summary();
        }

        $seen = [];
        $units = [];
        $creates = 0;
        $updates = 0;
        $blocked = 0;
        $phase7 = 0;
        $instructionfiles = 0;

        foreach ($assignments as $assignment) {
            if (!is_array($assignment)) {
                continue;
            }
            $assignmentid = trim((string) ($assignment['source_id'] ?? ''));
            $title = trim((string) ($assignment['title'] ?? ''));
            if ($assignmentid === '' || $title === '' || isset($seen[$assignmentid])) {
                $this->block(
                    $operation,
                    'EXERCISE_ASSIGNMENT_IDENTITY_INVALID',
                    'Exercise assignment ids/titles must be present and ids unique.'
                );
                return $this->empty_summary();
            }
            $seen[$assignmentid] = true;

            $type = is_array($assignment['type'] ?? null) ? $assignment['type'] : [];
            $typekey = (string) ($type['key'] ?? '');
            $support = (string) ($type['migration_support'] ?? 'unsupported');
            $usesfiles = !empty($type['uses_files']);
            $usesonlinetext = !empty($type['uses_online_text']);
            $usesteams = !empty($type['uses_teams']);

            $unitstatus = 'READY';
            $reasons = [];
            if ($support === 'manual_strategy_required' || $support === 'unsupported') {
                $unitstatus = 'BLOCKED';
                $reasons[] = 'ASSIGNMENT_TYPE_REQUIRES_MANUAL_STRATEGY';
            }
            if ($usesfiles && !$fileplugin) {
                $unitstatus = 'BLOCKED';
                $reasons[] = 'ASSIGN_FILE_PLUGIN_UNAVAILABLE';
            }
            if ($usesonlinetext && !$onlinetextplugin) {
                $unitstatus = 'BLOCKED';
                $reasons[] = 'ASSIGN_ONLINETEXT_PLUGIN_UNAVAILABLE';
            }
            if (!empty($assignment['peer_review']['enabled'])) {
                $unitstatus = 'BLOCKED';
                $reasons[] = 'PEER_REVIEW_NOT_MAPPED_TO_MOD_ASSIGN';
            }
            if ((int) ($assignment['deadline_mode'] ?? 0) !== 0) {
                $unitstatus = 'BLOCKED';
                $reasons[] = 'NON_ABSOLUTE_DEADLINE_REQUIRES_POLICY';
            }
            if (!empty($assignment['reminders'])) {
                $unitstatus = 'BLOCKED';
                $reasons[] = 'ILIAS_REMINDERS_REQUIRE_POLICY';
            }
            if ($usesteams) {
                $phase7++;
                if ($unitstatus === 'READY') {
                    $unitstatus = 'READY_WITH_PHASE7_DEPENDENCY';
                }
                $reasons[] = 'TEAM_MEMBERSHIP_DEFERRED_TO_PHASE7';
            }

            $files = is_array($assignment['instruction_files'] ?? null)
                ? $assignment['instruction_files']
                : [];
            foreach ($files as $file) {
                if (!is_array($file)) {
                    continue;
                }
                $instructionfiles++;
                $path = (string) ($file['migration_path'] ?? '');
                if ($path === '' || $this->resolve_relative_file($path) === null) {
                    $unitstatus = 'BLOCKED';
                    $reasons[] = 'INSTRUCTION_FILE_MISSING';
                }
            }

            $mappingref = (string) ($operation['source_ref_id'] ?? '')
                . ':assignment:' . $assignmentid;
            $mapping = $this->resolve_assign_action($mappingref);
            if (str_starts_with((string) $mapping['action'], 'ERROR_')) {
                $unitstatus = 'BLOCKED';
                $reasons[] = (string) $mapping['action'];
            }

            if ($mapping['action'] === 'CREATE') {
                $creates++;
            } else if ($mapping['action'] === 'UPDATE') {
                $updates++;
            }
            if ($unitstatus === 'BLOCKED') {
                $blocked++;
            }

            $units[] = [
                'source_id' => $assignmentid,
                'title' => $title,
                'mapping_ref' => $mappingref,
                'action' => $mapping['action'],
                'target_id' => $mapping['target_id'],
                'status' => $unitstatus,
                'reasons' => array_values(array_unique($reasons)),
                'type_id' => (int) ($assignment['type_id'] ?? 0),
                'type' => $typekey,
                'submission_file' => $usesfiles,
                'submission_onlinetext' => $usesonlinetext,
                'team_submission' => $usesteams,
                'start_time_utc' => (string) ($assignment['start_time_utc'] ?? ''),
                'deadline_utc' => (string) ($assignment['deadline_utc'] ?? ''),
                'extended_deadline_utc' => (string) ($assignment['extended_deadline_utc'] ?? ''),
                'mandatory' => !empty($assignment['mandatory']),
                'max_files' => (int) ($assignment['max_files'] ?? 0),
                'instruction_file_count' => count($files),
            ];
        }

        $operation['action'] = $blocked > 0 ? 'BLOCKED' : 'PLAN';
        $operation['exercise_validation'] = [
            'status' => $blocked > 0 ? 'BLOCKED' : 'OK',
            'code' => $blocked > 0 ? 'EXERCISE_UNITS_REQUIRE_POLICY' : 'EXERCISE_READY_FOR_POC',
            'assignment_count' => count($assignments),
            'unit_create_count' => $creates,
            'unit_update_count' => $updates,
            'unit_blocked_count' => $blocked,
            'phase7_dependency_count' => $phase7,
            'instruction_file_count' => $instructionfiles,
            'multi_unit' => count($assignments) > 1,
            'container_strategy' => 'ONE_MOD_ASSIGN_PER_UNIT_IN_PARENT_SECTION',
            'units' => $units,
        ];

        return [
            'units' => count($assignments),
            'creates' => $creates,
            'updates' => $updates,
            'blocked' => $blocked,
            'phase7_dependencies' => $phase7,
            'instruction_files' => $instructionfiles,
        ];
    }

    private function resolve_assign_action(string $mappingref): array {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $this->sourcecourse,
            'sourceref' => $mappingref,
            'targettype' => 'assign',
        ];
        $mapping = $DB->get_record('local_iliasmigration_map', $conditions);
        if (!$mapping && $this->sourceinstance !== '') {
            $conditions['sourceinstance'] = '';
            $mapping = $DB->get_record('local_iliasmigration_map', $conditions);
        }
        if (!$mapping) {
            return ['action' => 'CREATE', 'target_id' => null];
        }

        $cmid = (int) ($mapping->targetid ?? 0);
        $record = $cmid > 0
            ? $DB->get_record_sql(
                'SELECT cm.id, cm.course, m.name AS modulename
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module
                  WHERE cm.id = ?',
                [$cmid]
            )
            : false;
        if (!$record) {
            return ['action' => 'ERROR_STALE_MAPPING', 'target_id' => $cmid ?: null];
        }
        if ((string) $record->modulename !== 'assign'
                || (int) $record->course !== $this->targetcourseid) {
            return ['action' => 'ERROR_MAPPING_TYPE', 'target_id' => $cmid];
        }
        return ['action' => 'UPDATE', 'target_id' => $cmid];
    }

    private function validate_parent_target(array $operation): array {
        global $DB;

        $parentref = trim((string) ($operation['parent_source_ref_id'] ?? ''));
        if ($parentref === '') {
            return [
                'ready' => true,
                'parent_ref' => '',
                'targettype' => 'course_section_zero',
                'section_number' => 0,
            ];
        }

        foreach (['section', 'subsection'] as $targettype) {
            $conditions = [
                'sourcelms' => 'ILIAS',
                'sourceinstance' => $this->sourceinstance,
                'sourcecourse' => $this->sourcecourse,
                'sourceref' => $parentref,
                'targettype' => $targettype,
            ];
            $mapping = $DB->get_record('local_iliasmigration_map', $conditions);
            if (!$mapping && $this->sourceinstance !== '') {
                $conditions['sourceinstance'] = '';
                $mapping = $DB->get_record('local_iliasmigration_map', $conditions);
            }
            if (!$mapping) {
                continue;
            }

            $targetid = (int) ($mapping->targetid ?? 0);
            if ($targettype === 'section') {
                $section = $DB->get_record(
                    'course_sections',
                    ['id' => $targetid, 'course' => $this->targetcourseid],
                    'id,section'
                );
                if ($section) {
                    return [
                        'ready' => true,
                        'parent_ref' => $parentref,
                        'targettype' => 'section',
                        'targetid' => $targetid,
                        'section_number' => (int) $section->section,
                    ];
                }
                continue;
            }

            $cm = $DB->get_record_sql(
                'SELECT cm.id, cm.instance, cm.course, m.name AS modulename
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module
                  WHERE cm.id = ?',
                [$targetid]
            );
            if (!$cm
                    || (int) $cm->course !== $this->targetcourseid
                    || (string) $cm->modulename !== 'subsection') {
                continue;
            }
            $delegated = $DB->get_record(
                'course_sections',
                [
                    'course' => $this->targetcourseid,
                    'component' => 'mod_subsection',
                    'itemid' => (int) $cm->instance,
                ],
                'id,section'
            );
            if ($delegated) {
                return [
                    'ready' => true,
                    'parent_ref' => $parentref,
                    'targettype' => 'subsection',
                    'targetid' => $targetid,
                    'section_number' => (int) $delegated->section,
                ];
            }
        }

        return [
            'ready' => false,
            'parent_ref' => $parentref,
            'message' => "No valid Moodle section/subsection mapping exists for Exercise parent {$parentref}.",
        ];
    }

    private function resolve_relative_file(string $relative): ?string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../')) {
            return null;
        }
        $candidate = realpath(
            $this->packageroot . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative)
        );
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }
        if (!str_starts_with($candidate, $this->packageroot . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $candidate;
    }

    private function block(array &$operation, string $code, string $message): void {
        $operation['action'] = 'BLOCKED';
        $operation['reason'] = $code;
        $operation['exercise_validation'] = [
            'status' => 'BLOCKED',
            'code' => $code,
            'message' => $message,
        ];
    }

    private function empty_summary(): array {
        return [
            'units' => 0,
            'creates' => 0,
            'updates' => 0,
            'blocked' => 0,
            'phase7_dependencies' => 0,
            'instruction_files' => 0,
        ];
    }
}

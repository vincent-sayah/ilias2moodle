<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the Phase 6 Question Bank + Quiz preview.
 *
 * This planner is strictly read-only. It extends the Phase 5 plan so every
 * earlier migration phase must already resolve to stable Moodle objects before
 * questions or quizzes can later be written.
 */
final class phase6_plan_builder {
    /** @var int Moodle target course category id. */
    private int $categoryid;

    /** Neutral question type -> Moodle qtype. */
    private const QTYPE_MAP = [
        'single_choice' => 'multichoice',
        'multiple_choice' => 'multichoice',
        'numeric' => 'numerical',
        'matching' => 'match',
        'essay' => 'essay',
        'short_answer' => 'shortanswer',
        'cloze' => 'multianswer',
        'ordering' => 'ordering',
    ];

    /**
     * @param int $categoryid Moodle target category id.
     */
    public function __construct(int $categoryid) {
        $this->categoryid = $categoryid;
    }

    /**
     * Build a read-only Phase 6 plan.
     *
     * @param array $document Validated migration document.
     * @return array Import plan.
     */
    public function build(array $document): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/questionlib.php');

        $plan = (new phase5_plan_builder($this->categoryid))->build($document);
        $plan['phase'] = 6;

        $quizmodule = $DB->get_record('modules', ['name' => 'quiz'], 'id,name,visible');
        $qbankmodule = $DB->get_record('modules', ['name' => 'qbank'], 'id,name,visible');
        $quizavailable = $quizmodule && (int) $quizmodule->visible === 1;
        $qbankavailable = $qbankmodule && (int) $qbankmodule->visible === 1;
        $quizlocallibavailable = is_readable($CFG->dirroot . '/mod/quiz/locallib.php');
        $xmlformatavailable = is_readable($CFG->dirroot . '/question/format/xml/format.php');
        $qbankhelperavailable = class_exists('\\core_question\\local\\bank\\question_bank_helper');

        $qtypes = [];
        $missingqtypes = [];
        foreach (array_values(array_unique(self::QTYPE_MAP)) as $qtype) {
            $usable = \question_bank::is_qtype_usable($qtype);
            $qtypes[$qtype] = $usable;
            if (!$usable) {
                $missingqtypes[] = $qtype;
            }
        }

        $plan['moodle']['quiz_available'] = $quizavailable;
        $plan['moodle']['qbank_available'] = $qbankavailable;
        $plan['moodle']['quiz_locallib_available'] = $quizlocallibavailable;
        $plan['moodle']['question_xml_format_available'] = $xmlformatavailable;
        $plan['moodle']['question_bank_helper_available'] = $qbankhelperavailable;
        $plan['moodle']['question_types'] = $qtypes;

        $sourcecourseid = (string) ($document['course']['source_id'] ?? '');
        $sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $targetcourseid = (int) ($plan['operations'][0]['target_id'] ?? 0);
        $metadataindex = [];
        $this->index_items($document['course']['items'] ?? [], $metadataindex);

        $pending = [];
        foreach ($plan['operations'] as &$operation) {
            $kind = (string) ($operation['kind'] ?? '');
            $action = (string) ($operation['action'] ?? '');
            $sourceref = (string) ($operation['source_ref_id'] ?? '');

            if ($kind === 'question_pool') {
                $metadata = $metadataindex[$sourceref] ?? [];
                $mapping = $this->resolve_activity_action(
                    $sourceinstance,
                    $sourcecourseid,
                    $sourceref,
                    $targetcourseid,
                    'qbank'
                );

                $operation['action'] = $qbankavailable
                    ? $mapping['action']
                    : 'BLOCKED';
                $operation['target_id'] = $mapping['target_id'];
                $operation['moodle_module'] = 'qbank';
                $operation['question_export_files'] = array_values(array_filter(
                    (array) ($metadata['migration_question_export_files'] ?? []),
                    static fn($path): bool => is_string($path) && $path !== ''
                ));
                $operation['exported_question_file_count'] = count(
                    $operation['question_export_files']
                );
                $operation['content_policy'] = $operation['exported_question_file_count'] > 0
                    ? 'EXPORTED_CONTENT_AVAILABLE'
                    : 'CONTAINER_ONLY';
                if (!empty($mapping['legacy_mapping'])) {
                    $operation['legacy_sourceinstance_mapping'] = true;
                }
                if (!$qbankavailable) {
                    $operation['reason'] = 'QBANK_MODULE_DISABLED';
                }
                continue;
            }

            if ($kind === 'test') {
                $metadata = $metadataindex[$sourceref] ?? [];
                $mapping = $this->resolve_activity_action(
                    $sourceinstance,
                    $sourcecourseid,
                    $sourceref,
                    $targetcourseid,
                    'quiz'
                );

                $operation['action'] = ($quizavailable && !$missingqtypes)
                    ? $mapping['action']
                    : 'BLOCKED';
                $operation['target_id'] = $mapping['target_id'];
                $operation['moodle_module'] = 'quiz';
                $operation['migration_questions_path'] = (string) (
                    $metadata['migration_questions_path'] ?? ''
                );
                $operation['migration_quiz_path'] = (string) (
                    $metadata['migration_quiz_path'] ?? ''
                );
                $operation['normalized_question_count'] = (int) (
                    $metadata['normalized_question_count'] ?? 0
                );
                $operation['normalized_unsupported_count'] = (int) (
                    $metadata['normalized_unsupported_count'] ?? 0
                );
                $operation['normalized_total_max_score'] = (float) (
                    $metadata['normalized_total_max_score'] ?? 0.0
                );
                $operation['question_storage_policy'] = 'QUIZ_PRIVATE_QUESTION_BANK';
                $operation['qtype_map'] = self::QTYPE_MAP;
                if (!empty($mapping['legacy_mapping'])) {
                    $operation['legacy_sourceinstance_mapping'] = true;
                }
                if (!$quizavailable) {
                    $operation['reason'] = 'QUIZ_MODULE_DISABLED';
                } else if ($missingqtypes) {
                    $operation['reason'] = 'QUESTION_TYPES_UNAVAILABLE';
                    $operation['missing_qtypes'] = $missingqtypes;
                }
                continue;
            }

            // Phase 6 may only proceed once Phases 2-5 are already represented
            // by stable Moodle objects from the same source export.
            if (in_array(
                $kind,
                [
                    'course',
                    'section',
                    'subsection',
                    'file',
                    'url',
                    'html_module',
                    'scorm',
                    'learning_module',
                ],
                true
            ) && $action !== 'UPDATE') {
                $pending[] = [
                    'kind' => $kind,
                    'source_ref_id' => $sourceref,
                    'action' => $action,
                    'target_id' => $operation['target_id'] ?? null,
                ];
            }
        }
        unset($operation);

        if (!$quizavailable) {
            $plan['warnings'][] = [
                'code' => 'QUIZ_MODULE_DISABLED',
                'message' => 'Moodle mod_quiz is missing or disabled; Phase 6 cannot be applied.',
            ];
        }
        if (!$qbankavailable) {
            $plan['warnings'][] = [
                'code' => 'QBANK_MODULE_DISABLED',
                'message' => 'Moodle mod_qbank is missing or disabled; ILIAS question pools cannot be represented as Moodle shared question banks.',
            ];
        }
        if (!$quizlocallibavailable) {
            $plan['warnings'][] = [
                'code' => 'QUIZ_LOCALLIB_UNAVAILABLE',
                'message' => 'Moodle mod_quiz locallib.php is unavailable; future quiz question slot creation cannot run.',
            ];
        }
        if (!$xmlformatavailable) {
            $plan['warnings'][] = [
                'code' => 'QUESTION_XML_FORMAT_UNAVAILABLE',
                'message' => 'Moodle core XML question import format is unavailable.',
            ];
        }
        if (!$qbankhelperavailable) {
            $plan['warnings'][] = [
                'code' => 'QUESTION_BANK_HELPER_UNAVAILABLE',
                'message' => 'Moodle 5.0 question bank helper API is unavailable.',
            ];
        }
        if ($missingqtypes) {
            $plan['warnings'][] = [
                'code' => 'QUESTION_TYPES_UNAVAILABLE',
                'qtypes' => $missingqtypes,
                'message' => 'At least one Moodle question type required by the normalized ILIAS test is unavailable.',
            ];
        }
        if ($pending) {
            $plan['warnings'][] = [
                'code' => 'PHASE5_PREREQUISITES_PENDING',
                'source_ref_ids' => array_values(array_map(
                    static fn(array $item): string => (string) $item['source_ref_id'],
                    $pending
                )),
                'message' => 'This export contains Phase 2/3/4/5 changes that must be synchronized before Question Bank + Quiz.',
            ];
        }

        $ready = $quizavailable
            && $qbankavailable
            && $quizlocallibavailable
            && $xmlformatavailable
            && $qbankhelperavailable
            && !$missingqtypes
            && count($pending) === 0;

        $plan['phase6_prerequisites'] = [
            'pending_operations' => $pending,
            'pending_count' => count($pending),
            'quiz_available' => $quizavailable,
            'qbank_available' => $qbankavailable,
            'quiz_locallib_available' => $quizlocallibavailable,
            'question_xml_format_available' => $xmlformatavailable,
            'question_bank_helper_available' => $qbankhelperavailable,
            'missing_qtypes' => $missingqtypes,
            'ready' => $ready,
        ];

        return $plan;
    }

    /**
     * Index neutral item metadata by source ref_id.
     */
    private function index_items(array $items, array &$index): void {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sourceid = (string) ($item['source_id'] ?? '');
            if ($sourceid !== '') {
                $index[$sourceid] = is_array($item['metadata'] ?? null)
                    ? $item['metadata']
                    : [];
            }
            $this->index_items(is_array($item['items'] ?? null) ? $item['items'] : [], $index);
        }
    }

    /**
     * Resolve Moodle activity CREATE/UPDATE state from the persistent mapping table.
     */
    private function resolve_activity_action(
        string $sourceinstance,
        string $sourcecourse,
        string $sourceref,
        int $targetcourseid,
        string $modulename
    ): array {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => $modulename,
        ];
        $mapping = $DB->get_record('local_iliasmigration_map', $conditions);
        $legacy = false;

        if (!$mapping && $sourceinstance !== '') {
            $legacyconditions = $conditions;
            $legacyconditions['sourceinstance'] = '';
            $mapping = $DB->get_record('local_iliasmigration_map', $legacyconditions);
            $legacy = (bool) $mapping;
        }

        if (!$mapping) {
            return [
                'action' => 'CREATE',
                'target_id' => null,
                'legacy_mapping' => false,
            ];
        }

        $cmid = (int) ($mapping->targetid ?? 0);
        if ($cmid <= 0) {
            return [
                'action' => 'ERROR_STALE_MAPPING',
                'target_id' => null,
                'legacy_mapping' => $legacy,
            ];
        }

        $record = $DB->get_record_sql(
            'SELECT cm.id, cm.course, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = ?',
            [$cmid]
        );
        if (!$record) {
            return [
                'action' => 'ERROR_STALE_MAPPING',
                'target_id' => $cmid,
                'legacy_mapping' => $legacy,
            ];
        }
        if ((string) $record->modulename !== $modulename
                || ($targetcourseid > 0 && (int) $record->course !== $targetcourseid)) {
            return [
                'action' => 'ERROR_MAPPING_TYPE',
                'target_id' => $cmid,
                'legacy_mapping' => $legacy,
            ];
        }

        return [
            'action' => 'UPDATE',
            'target_id' => $cmid,
            'legacy_mapping' => $legacy,
        ];
    }
}

<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates normalized ILIAS test packages without Moodle writes.
 */
final class phase6_package_validator {
    /** @var string Canonical migration package root. */
    private string $packageroot;

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
     * @param string $migrationjson Absolute path to migration.json.
     */
    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        if ($root === false || !is_dir($root)) {
            throw new \coding_exception('Unable to resolve the migration package directory.');
        }
        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
    }

    /**
     * Validate all Phase 6 operations and annotate the dry-run plan.
     *
     * @param array $plan Phase 6 plan.
     * @return array Annotated plan.
     */
    public function validate(array $plan): array {
        $checkedtests = 0;
        $blockedtests = 0;
        $questionpools = 0;
        $containeronlypools = 0;
        $scoringreviews = 0;

        foreach ($plan['operations'] as &$operation) {
            $kind = (string) ($operation['kind'] ?? '');

            if ($kind === 'question_pool') {
                $questionpools++;
                $count = (int) ($operation['exported_question_file_count'] ?? 0);
                $operation['question_pool_validation'] = [
                    'status' => 'OK',
                    'exported_question_file_count' => $count,
                    'content_policy' => (string) ($operation['content_policy'] ?? ''),
                ];
                if ($count === 0) {
                    $containeronlypools++;
                    $plan['warnings'][] = [
                        'code' => 'QUESTION_POOL_CONTENT_NOT_EXPORTED',
                        'source_ref_id' => (string) ($operation['source_ref_id'] ?? ''),
                        'message' => 'The ILIAS question pool is exported as a container only. No question content will be invented or copied into the Moodle shared question bank.',
                    ];
                }
                continue;
            }

            if ($kind !== 'test') {
                continue;
            }

            $action = (string) ($operation['action'] ?? '');
            if ($action === 'BLOCKED') {
                $blockedtests++;
                continue;
            }
            if (!in_array($action, ['CREATE', 'UPDATE'], true)) {
                continue;
            }

            $checkedtests++;
            $result = $this->validate_test($operation, $plan['warnings']);
            $scoringreviews += (int) ($result['scoring_review_count'] ?? 0);
            if (($operation['action'] ?? '') === 'BLOCKED') {
                $blockedtests++;
            }
        }
        unset($operation);

        if ($checkedtests === 0) {
            $plan['warnings'][] = [
                'code' => 'NO_TEST_FOUND',
                'message' => 'No ILIAS test CREATE/UPDATE operation was found in this migration package.',
            ];
        }

        $phase3ready = !isset($plan['phase3_package']) || !empty($plan['phase3_package']['ready']);
        $phase4ready = !isset($plan['phase4_package']) || !empty($plan['phase4_package']['ready']);
        $phase5ready = !isset($plan['phase5_package']) || !empty($plan['phase5_package']['ready']);
        $phase6prerequisites = !empty($plan['phase6_prerequisites']['ready']);
        $packagechecksready = $checkedtests > 0 && $blockedtests === 0;
        $prerequisitesready = $phase3ready && $phase4ready && $phase5ready && $phase6prerequisites;

        $plan['phase6_package'] = [
            'root' => $this->packageroot,
            'checked_tests' => $checkedtests,
            'blocked_tests' => $blockedtests,
            'question_pools' => $questionpools,
            'container_only_question_pools' => $containeronlypools,
            'scoring_review_count' => $scoringreviews,
            'package_checks_ready' => $packagechecksready,
            'prerequisites_ready' => $prerequisitesready,
            'ready' => $packagechecksready && $prerequisitesready,
            'apply_implemented' => false,
            'apply_ready' => false,
        ];

        if ($packagechecksready && $prerequisitesready) {
            $plan['warnings'][] = [
                'code' => 'PHASE6_APPLY_NOT_IMPLEMENTED',
                'message' => 'The Phase 6 package is valid for dry-run, but Question Bank / Quiz writes are intentionally not implemented yet.',
            ];
        }

        return $plan;
    }

    /**
     * Validate one normalized test package.
     *
     * @return array Validation summary.
     */
    private function validate_test(array &$operation, array &$warnings): array {
        $questionspath = (string) ($operation['migration_questions_path'] ?? '');
        $quizpath = (string) ($operation['migration_quiz_path'] ?? '');
        $questionsfile = $this->resolve_relative_file($questionspath);
        $quizfile = $this->resolve_relative_file($quizpath);

        if ($questionsfile === null || $quizfile === null) {
            $this->block(
                $operation,
                'PHASE6_NORMALIZED_FILES_MISSING',
                'questions.json and quiz.json must both exist inside the migration package.'
            );
            return ['scoring_review_count' => 0];
        }

        $questions = $this->read_json($questionsfile, 'questions.json', $operation);
        if ($questions === null) {
            return ['scoring_review_count' => 0];
        }
        $quiz = $this->read_json($quizfile, 'quiz.json', $operation);
        if ($quiz === null) {
            return ['scoring_review_count' => 0];
        }

        if (($questions['schema_version'] ?? null) !== '1.0'
                || ($quiz['schema_version'] ?? null) !== '1.0') {
            $this->block(
                $operation,
                'PHASE6_SCHEMA_UNSUPPORTED',
                'questions.json and quiz.json must use schema_version 1.0.'
            );
            return ['scoring_review_count' => 0];
        }

        $expectedref = (string) ($operation['source_ref_id'] ?? '');
        $questionssource = is_array($questions['source'] ?? null) ? $questions['source'] : [];
        $quizsource = is_array($quiz['source'] ?? null) ? $quiz['source'] : [];
        if ((string) ($questionssource['lms'] ?? '') !== 'ILIAS'
                || (string) ($quizsource['lms'] ?? '') !== 'ILIAS'
                || (string) ($questionssource['test_ref_id'] ?? '') !== $expectedref
                || (string) ($quizsource['test_ref_id'] ?? '') !== $expectedref) {
            $this->block(
                $operation,
                'PHASE6_SOURCE_MISMATCH',
                'Normalized question source identity does not match the planned ILIAS test.'
            );
            return ['scoring_review_count' => 0];
        }

        $questionlist = $questions['questions'] ?? null;
        $questionorder = $quiz['question_order'] ?? null;
        $unresolved = $quiz['unresolved_question_refs'] ?? null;
        if (!is_array($questionlist) || !is_array($questionorder) || !is_array($unresolved)) {
            $this->block(
                $operation,
                'PHASE6_DOCUMENT_INCOMPLETE',
                'Normalized documents must contain questions, question_order and unresolved_question_refs arrays.'
            );
            return ['scoring_review_count' => 0];
        }

        $questioncount = (int) ($questions['question_count'] ?? -1);
        $orderedcount = (int) ($quiz['ordered_question_count'] ?? -1);
        if ($questioncount <= 0
                || $questioncount !== count($questionlist)
                || $orderedcount !== count($questionorder)
                || $orderedcount !== $questioncount) {
            $this->block(
                $operation,
                'PHASE6_QUESTION_COUNT_MISMATCH',
                'Question counts in questions.json and quiz.json are inconsistent.'
            );
            return ['scoring_review_count' => 0];
        }

        if ($unresolved) {
            $this->block(
                $operation,
                'PHASE6_UNRESOLVED_QREF',
                'quiz.json still contains unresolved ILIAS question references.'
            );
            return ['scoring_review_count' => 0];
        }

        $byident = [];
        $typecounts = [];
        $scoringreviews = 0;
        foreach ($questionlist as $question) {
            if (!is_array($question)) {
                $this->block(
                    $operation,
                    'PHASE6_QUESTION_INVALID',
                    'Every normalized question must be an object.'
                );
                return ['scoring_review_count' => $scoringreviews];
            }

            $ident = (string) ($question['source_ident'] ?? '');
            $type = (string) ($question['type'] ?? '');
            if ($ident === '' || isset($byident[$ident])) {
                $this->block(
                    $operation,
                    'PHASE6_QUESTION_IDENT_INVALID',
                    'Question source_ident values must be present and unique.'
                );
                return ['scoring_review_count' => $scoringreviews];
            }
            if (!isset(self::QTYPE_MAP[$type])) {
                $this->block(
                    $operation,
                    'PHASE6_QUESTION_TYPE_UNSUPPORTED',
                    'Unsupported normalized question type: ' . $type
                );
                return ['scoring_review_count' => $scoringreviews];
            }

            $byident[$ident] = $question;
            $typecounts[$type] = ($typecounts[$type] ?? 0) + 1;

            if ($type === 'matching' && $this->matching_has_unequal_pair_weights($question)) {
                $scoringreviews++;
                $warnings[] = [
                    'code' => 'MATCHING_WEIGHT_SEMANTICS_REVIEW',
                    'source_ref_id' => $expectedref,
                    'question_source_ident' => $ident,
                    'message' => 'ILIAS assigns different point weights to Matching pairs. Moodle Matching supports partial grading, but exact 4/2/5 weighting must be verified before apply.',
                ];
            }
            if ($type === 'ordering') {
                $scoringreviews++;
                $warnings[] = [
                    'code' => 'ORDERING_GRADING_POLICY_REVIEW',
                    'source_ref_id' => $expectedref,
                    'question_source_ident' => $ident,
                    'message' => 'Moodle Ordering provides several grading strategies. The strategy matching the ILIAS per-position scoring must be selected before apply.',
                ];
            }
        }

        $preview = [];
        $orderscore = 0.0;
        foreach ($questionorder as $entry) {
            if (!is_array($entry)) {
                $this->block(
                    $operation,
                    'PHASE6_QUIZ_ORDER_INVALID',
                    'Every quiz question_order entry must be an object.'
                );
                return ['scoring_review_count' => $scoringreviews];
            }
            $ident = (string) ($entry['source_ident'] ?? '');
            if (!isset($byident[$ident])) {
                $this->block(
                    $operation,
                    'PHASE6_QUIZ_ORDER_REFERENCE_INVALID',
                    'quiz.json references a question not present in questions.json: ' . $ident
                );
                return ['scoring_review_count' => $scoringreviews];
            }

            $question = $byident[$ident];
            $type = (string) $question['type'];
            $maxscore = (float) ($entry['max_score'] ?? 0.0);
            $orderscore += $maxscore;
            $preview[] = [
                'position' => (int) ($entry['position'] ?? 0),
                'source_ident' => $ident,
                'external_id' => (string) ($question['external_id'] ?? ''),
                'title' => (string) ($entry['title'] ?? $question['title'] ?? ''),
                'neutral_type' => $type,
                'moodle_qtype' => self::QTYPE_MAP[$type],
                'max_score' => $maxscore,
            ];
        }

        $totalscore = (float) ($quiz['total_max_score'] ?? 0.0);
        if (abs($orderscore - $totalscore) > 0.000001) {
            $this->block(
                $operation,
                'PHASE6_TOTAL_SCORE_MISMATCH',
                'quiz.json total_max_score does not equal the ordered question score sum.'
            );
            return ['scoring_review_count' => $scoringreviews];
        }

        $declaredcount = (int) ($operation['normalized_question_count'] ?? 0);
        $declareduunsupported = (int) ($operation['normalized_unsupported_count'] ?? 0);
        $declaredscore = (float) ($operation['normalized_total_max_score'] ?? 0.0);
        if ($declaredcount !== $questioncount
                || $declareduunsupported !== 0
                || abs($declaredscore - $totalscore) > 0.000001) {
            $warnings[] = [
                'code' => 'PHASE6_DECLARED_METADATA_MISMATCH',
                'source_ref_id' => $expectedref,
                'message' => 'migration.json normalized Phase 6 counters differ from the validated JSON documents.',
            ];
        }

        $operation['quiz_preview'] = [
            'title' => (string) ($quiz['title'] ?? $operation['title'] ?? ''),
            'question_storage_policy' => 'QUIZ_PRIVATE_QUESTION_BANK',
            'question_count' => $questioncount,
            'total_max_score' => $totalscore,
            'questions' => $preview,
        ];
        $operation['phase6_validation'] = [
            'status' => 'OK',
            'questions_path' => $questionspath,
            'quiz_path' => $quizpath,
            'questions_sha256' => hash_file('sha256', $questionsfile) ?: null,
            'quiz_sha256' => hash_file('sha256', $quizfile) ?: null,
            'question_count' => $questioncount,
            'ordered_question_count' => $orderedcount,
            'type_counts' => $typecounts,
            'unsupported_count' => (int) ($questions['unsupported_count'] ?? 0),
            'unresolved_question_refs' => $unresolved,
            'total_max_score' => $totalscore,
            'scoring_review_count' => $scoringreviews,
        ];

        return ['scoring_review_count' => $scoringreviews];
    }

    /**
     * Read one bounded JSON file.
     */
    private function read_json(string $path, string $label, array &$operation): ?array {
        $size = filesize($path);
        if ($size === false || $size <= 0 || $size > 64 * 1024 * 1024) {
            $this->block(
                $operation,
                'PHASE6_JSON_SIZE_INVALID',
                $label . ' is empty, unreadable or unexpectedly large.'
            );
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->block($operation, 'PHASE6_JSON_READ_FAILED', 'Unable to read ' . $label . '.');
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->block(
                $operation,
                'PHASE6_JSON_INVALID',
                'Invalid ' . $label . ': ' . $exception->getMessage()
            );
            return null;
        }

        if (!is_array($data)) {
            $this->block($operation, 'PHASE6_JSON_INVALID', $label . ' must contain a JSON object.');
            return null;
        }
        return $data;
    }

    /**
     * Return true when a Matching question has non-uniform pair weights.
     */
    private function matching_has_unequal_pair_weights(array $question): bool {
        $weights = [];
        foreach (($question['pairs'] ?? []) as $pair) {
            if (is_array($pair)) {
                $weights[] = round((float) ($pair['points'] ?? 0.0), 9);
            }
        }
        return count(array_unique($weights, SORT_REGULAR)) > 1;
    }

    /**
     * Resolve a package-relative file without allowing traversal.
     */
    private function resolve_relative_file(string $relative): ?string {
        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }
        $normalized = str_replace('\\', '/', $relative);
        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized)) {
            return null;
        }
        $parts = array_values(array_filter(explode('/', $normalized), static fn($part): bool => $part !== ''));
        if (!$parts || in_array('..', $parts, true)) {
            return null;
        }

        $candidate = $this->packageroot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            return null;
        }
        $prefix = $this->packageroot . DIRECTORY_SEPARATOR;
        if (!str_starts_with($resolved, $prefix)) {
            return null;
        }
        return $resolved;
    }

    /**
     * Mark an operation as blocked.
     */
    private function block(array &$operation, string $code, string $message): void {
        $operation['action'] = 'BLOCKED';
        $operation['reason'] = $code;
        $operation['phase6_validation'] = [
            'status' => 'BLOCKED',
            'code' => $code,
            'message' => $message,
        ];
    }
}

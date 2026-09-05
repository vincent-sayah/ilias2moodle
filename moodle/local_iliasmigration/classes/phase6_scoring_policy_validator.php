<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves Phase 6 scoring-policy differences into explicit Moodle apply policies.
 */
final class phase6_scoring_policy_validator {
    /** @var string Canonical migration package root. */
    private string $packageroot;

    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        if ($root === false || !is_dir($root)) {
            throw new \coding_exception('Unable to resolve the migration package directory.');
        }
        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
    }

    /**
     * Annotate exact score-preserving transforms and enable apply when supported.
     */
    public function validate(array $plan): array {
        $addedreviews = 0;
        $multichoicereviews = 0;
        $transformedquestions = 0;

        foreach ($plan['operations'] as &$operation) {
            if (($operation['kind'] ?? '') !== 'test'
                    || ($operation['phase6_validation']['status'] ?? '') !== 'OK') {
                continue;
            }

            $questionsfile = $this->resolve_relative_file(
                (string) ($operation['migration_questions_path'] ?? '')
            );
            if ($questionsfile === null) {
                continue;
            }
            $questions = $this->read_json($questionsfile);
            if ($questions === null || !is_array($questions['questions'] ?? null)) {
                continue;
            }

            $testref = (string) ($operation['source_ref_id'] ?? '');
            $operationreviews = 0;
            $operationtransforms = 0;

            foreach ($questions['questions'] as $question) {
                if (!is_array($question)) {
                    continue;
                }
                $type = (string) ($question['type'] ?? '');
                $ident = (string) ($question['source_ident'] ?? '');

                if ($type === 'matching' && $this->matching_has_unequal_pair_weights($question)) {
                    $this->annotate_preview(
                        $operation,
                        $ident,
                        'WEIGHTED_MATCHING_TO_CLOZE',
                        'multianswer',
                        'ILIAS_UNEQUAL_MATCHING_PAIR_WEIGHTS'
                    );
                    $operationtransforms++;
                    $transformedquestions++;
                    continue;
                }

                if ($type === 'ordering') {
                    $this->annotate_preview(
                        $operation,
                        $ident,
                        'NATIVE_ORDERING_ABSOLUTE_POSITION',
                        'ordering',
                        'ILIAS_EQUAL_ABSOLUTE_POSITION_POINTS'
                    );
                    continue;
                }

                if ($type !== 'multiple_choice') {
                    continue;
                }
                $affectedanswers = $this->answers_with_unselected_score($question);
                if (!$affectedanswers) {
                    continue;
                }

                $operationreviews++;
                $addedreviews++;
                $multichoicereviews++;
                $operationtransforms++;
                $transformedquestions++;

                $plan['warnings'][] = [
                    'code' => 'MULTICHOICE_UNSELECTED_SCORING_REVIEW',
                    'source_ref_id' => $testref,
                    'question_source_ident' => $ident,
                    'question_title' => (string) ($question['title'] ?? ''),
                    'affected_answer_count' => count($affectedanswers),
                    'affected_answers' => $affectedanswers,
                    'apply_policy' => 'MULTICHOICE_BINARY_DECISIONS_TO_CLOZE',
                    'message' => 'ILIAS awards points when some options are left unselected. Apply preserves the score by converting this one ILIAS question into one Moodle Cloze question containing one explicit selected/not-selected decision per scored option.',
                ];

                $this->annotate_preview(
                    $operation,
                    $ident,
                    'MULTICHOICE_BINARY_DECISIONS_TO_CLOZE',
                    'multianswer',
                    'ILIAS_UNSELECTED_OPTION_POINTS'
                );
            }

            if ($operationreviews > 0) {
                $operation['phase6_validation']['multiple_choice_unselected_scoring_review_count'] = $operationreviews;
                $operation['phase6_validation']['scoring_review_count'] =
                    (int) ($operation['phase6_validation']['scoring_review_count'] ?? 0)
                    + $operationreviews;
            }
            $operation['phase6_validation']['score_preserving_transform_count'] = $operationtransforms;
            $operation['phase6_validation']['scoring_policy_ready'] = true;
        }
        unset($operation);

        if (isset($plan['phase6_package'])) {
            if ($addedreviews > 0) {
                $plan['phase6_package']['scoring_review_count'] =
                    (int) ($plan['phase6_package']['scoring_review_count'] ?? 0)
                    + $addedreviews;
            }
            $plan['phase6_package']['multiple_choice_unselected_scoring_review_count'] = $multichoicereviews;
            $plan['phase6_package']['score_preserving_transform_count'] = $transformedquestions;
            $plan['phase6_package']['scoring_policy_ready'] = true;
            $plan['phase6_package']['apply_implemented'] = true;
            $plan['phase6_package']['apply_ready'] = !empty($plan['phase6_package']['ready']);
        }

        // The old structural validator warning is replaced by explicit policies.
        $plan['warnings'] = array_values(array_filter(
            $plan['warnings'],
            static fn(array $warning): bool => ($warning['code'] ?? '') !== 'PHASE6_APPLY_NOT_IMPLEMENTED'
        ));
        if (!empty($plan['phase6_package']['apply_ready'])) {
            $plan['warnings'][] = [
                'code' => 'PHASE6_SCORE_PRESERVING_TRANSFORMS_ENABLED',
                'transformed_question_count' => $transformedquestions,
                'message' => 'Phase 6 apply is enabled. Unequal-weight Matching and Multiple Choice with unselected-option credit are converted to one weighted Moodle Cloze question per ILIAS question; Ordering uses native ABSOLUTE_POSITION grading.',
            ];
            if ($multichoicereviews > 0) {
                $plan['warnings'][] = [
                    'code' => 'PHASE6_BINARY_DECISION_INTERACTION_CHANGE',
                    'question_count' => $multichoicereviews,
                    'message' => 'For score fidelity, transformed ILIAS Multiple Choice questions require an explicit selected/not-selected choice for each option. Leaving a Moodle subquestion unanswered scores 0 rather than implicitly counting as not selected.',
                ];
            }
        }

        return $plan;
    }

    private function answers_with_unselected_score(array $question): array {
        $affected = [];
        foreach (($question['answers'] ?? []) as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $score = (float) ($answer['score_if_not_selected'] ?? 0.0);
            if (abs($score) <= 0.000000001) {
                continue;
            }
            $affected[] = [
                'ident' => (string) ($answer['ident'] ?? ''),
                'text' => (string) ($answer['text'] ?? ''),
                'score_if_not_selected' => $score,
                'score_if_selected' => (float) ($answer['score_if_selected'] ?? 0.0),
            ];
        }
        return $affected;
    }

    private function matching_has_unequal_pair_weights(array $question): bool {
        $weights = [];
        foreach (($question['pairs'] ?? []) as $pair) {
            if (is_array($pair)) {
                $weights[] = round((float) ($pair['points'] ?? 0.0), 9);
            }
        }
        return count(array_unique($weights, SORT_REGULAR)) > 1;
    }

    private function annotate_preview(
        array &$operation,
        string $ident,
        string $policy,
        string $effectiveqtype,
        string $reason
    ): void {
        if (!is_array($operation['quiz_preview']['questions'] ?? null)) {
            return;
        }
        foreach ($operation['quiz_preview']['questions'] as &$preview) {
            if ((string) ($preview['source_ident'] ?? '') !== $ident) {
                continue;
            }
            $preview['scoring_policy'] = $policy;
            $preview['scoring_reason'] = $reason;
            $preview['effective_moodle_qtype'] = $effectiveqtype;
            break;
        }
        unset($preview);
    }

    private function read_json(string $path): ?array {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

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
        return str_starts_with($resolved, $prefix) ? $resolved : null;
    }
}

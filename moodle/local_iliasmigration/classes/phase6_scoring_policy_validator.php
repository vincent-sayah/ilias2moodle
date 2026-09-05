<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Adds Phase 6 scoring-policy diagnostics that depend on the normalized question payload.
 *
 * This validator is read-only. It runs after phase6_package_validator, whose structural
 * validation guarantees that questions.json is present and internally consistent.
 */
final class phase6_scoring_policy_validator {
    /** @var string Canonical migration package root. */
    private string $packageroot;

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
     * Add score-semantics reviews without changing Phase 6 package readiness.
     *
     * @param array $plan Validated Phase 6 plan.
     * @return array Annotated plan.
     */
    public function validate(array $plan): array {
        $addedreviews = 0;

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

            foreach ($questions['questions'] as $question) {
                if (!is_array($question) || ($question['type'] ?? '') !== 'multiple_choice') {
                    continue;
                }

                $affectedanswers = $this->answers_with_unselected_score($question);
                if (!$affectedanswers) {
                    continue;
                }

                $ident = (string) ($question['source_ident'] ?? '');
                $operationreviews++;
                $addedreviews++;

                $plan['warnings'][] = [
                    'code' => 'MULTICHOICE_UNSELECTED_SCORING_REVIEW',
                    'source_ref_id' => $testref,
                    'question_source_ident' => $ident,
                    'question_title' => (string) ($question['title'] ?? ''),
                    'affected_answer_count' => count($affectedanswers),
                    'affected_answers' => $affectedanswers,
                    'message' => 'ILIAS awards points when some Multiple Choice options are left unselected. Native Moodle multichoice cannot represent that rule directly; an explicit score-preserving transform is required before apply.',
                ];

                $this->annotate_preview($operation, $ident);
            }

            if ($operationreviews > 0) {
                $operation['phase6_validation']['multiple_choice_unselected_scoring_review_count'] = $operationreviews;
                $operation['phase6_validation']['scoring_review_count'] =
                    (int) ($operation['phase6_validation']['scoring_review_count'] ?? 0)
                    + $operationreviews;
            }
        }
        unset($operation);

        if ($addedreviews > 0 && isset($plan['phase6_package'])) {
            $plan['phase6_package']['multiple_choice_unselected_scoring_review_count'] = $addedreviews;
            $plan['phase6_package']['scoring_review_count'] =
                (int) ($plan['phase6_package']['scoring_review_count'] ?? 0)
                + $addedreviews;
        }

        return $plan;
    }

    /**
     * Return answers for which ILIAS grants non-zero credit when not selected.
     */
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

    /**
     * Mark the quiz preview entry as requiring an exact score-preserving transform.
     */
    private function annotate_preview(array &$operation, string $ident): void {
        if (!is_array($operation['quiz_preview']['questions'] ?? null)) {
            return;
        }

        foreach ($operation['quiz_preview']['questions'] as &$preview) {
            if ((string) ($preview['source_ident'] ?? '') !== $ident) {
                continue;
            }
            $preview['scoring_policy'] = 'SCORE_PRESERVING_TRANSFORM_REQUIRED';
            $preview['scoring_reason'] = 'ILIAS_UNSELECTED_OPTION_POINTS';
            break;
        }
        unset($preview);
    }

    /**
     * Read one already-validated JSON document.
     */
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
        return str_starts_with($resolved, $prefix) ? $resolved : null;
    }
}

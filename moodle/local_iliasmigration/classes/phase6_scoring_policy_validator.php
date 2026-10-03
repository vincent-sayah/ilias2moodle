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
     * Annotate exact score-preserving transforms, generate the exact Moodle XML
     * in memory, and enable apply only when that XML passes a read-only preflight.
     */
    public function validate(array $plan): array {
        $addedreviews = 0;
        $multichoicereviews = 0;
        $nonstandardfractionreviews = 0;
        $transformedquestions = 0;
        $preflightblocked = 0;

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

                if ($type === 'kprim') {
                    $this->annotate_preview(
                        $operation,
                        $ident,
                        'KPRIM_COMBINATIONS_TO_MULTICHOICE',
                        'multichoice',
                        'ILIAS_KPRIM_COMBINATION_SCORING'
                    );
                    $operationtransforms++;
                    $transformedquestions++;
                    $plan['warnings'][] = [
                        'code' => 'KPRIM_INTERACTION_CHANGE',
                        'source_ref_id' => $testref,
                        'question_source_ident' => $ident,
                        'question_title' => (string) ($question['title'] ?? ''),
                        'combination_count' => count((array) ($question['combinations'] ?? [])),
                        'apply_policy' => 'KPRIM_COMBINATIONS_TO_MULTICHOICE',
                        'message' => 'ILIAS Kprim binary decisions are converted to one single-choice Moodle question containing every response combination. This preserves the exact QTI score table while changing the interaction.',
                    ];
                    continue;
                }

                if ($type !== 'multiple_choice') {
                    continue;
                }
                $affectedanswers = $this->answers_with_unselected_score($question);
                if ($affectedanswers) {
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
                    continue;
                }

                $nonstandardfractions =
                    $this->nonstandard_selected_fraction_details($question);
                if (!$nonstandardfractions) {
                    continue;
                }

                if (!$this->can_preserve_multiple_choice_as_multiresponse($question)) {
                    $plan['warnings'][] = [
                        'code' => 'MULTICHOICE_NONSTANDARD_FRACTIONS_UNSAFE',
                        'source_ref_id' => $testref,
                        'question_source_ident' => $ident,
                        'question_title' => (string) ($question['title'] ?? ''),
                        'fractions' => $nonstandardfractions,
                        'message' => 'ILIAS uses answer fractions rejected by Moodle XML import, and their positive score total cannot be preserved exactly by the Cloze MULTIRESPONSE transform.',
                    ];
                    continue;
                }

                $operationreviews++;
                $addedreviews++;
                $nonstandardfractionreviews++;
                $operationtransforms++;
                $transformedquestions++;

                $plan['warnings'][] = [
                    'code' => 'MULTICHOICE_NONSTANDARD_FRACTIONS_TRANSFORM',
                    'source_ref_id' => $testref,
                    'question_source_ident' => $ident,
                    'question_title' => (string) ($question['title'] ?? ''),
                    'fractions' => $nonstandardfractions,
                    'apply_policy' => 'MULTICHOICE_NONSTANDARD_FRACTIONS_TO_CLOZE',
                    'message' => 'ILIAS uses exact Multiple Choice fractions that Moodle XML rejects in native multichoice. Apply preserves them by converting the question to one Cloze MULTIRESPONSE interaction.',
                ];

                $this->annotate_preview(
                    $operation,
                    $ident,
                    'MULTICHOICE_NONSTANDARD_FRACTIONS_TO_CLOZE',
                    'multianswer',
                    'ILIAS_NONSTANDARD_MOODLE_FRACTIONS'
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

            try {
                $built = (new phase6_moodle_xml_builder())->build($questions, $testref);
                $xml = (string) ($built['xml'] ?? '');
                $descriptors = is_array($built['questions'] ?? null) ? $built['questions'] : [];
                $parsed = $this->parse_xml_without_network($xml);
                $expectedcount = (int) ($questions['question_count'] ?? count($questions['questions']));
                if ($parsed === null || count($parsed->question) !== $expectedcount || count($descriptors) !== $expectedcount) {
                    throw new \coding_exception('Generated Moodle XML top-level question count differs from questions.json.');
                }

                $qtypes = [];
                $transforms = [];
                foreach ($descriptors as $index => $descriptor) {
                    $qtype = (string) ($descriptor['effective_qtype'] ?? '');
                    $qtypes[$qtype] = ($qtypes[$qtype] ?? 0) + 1;

                    $xmlquestion = $parsed->question[$index] ?? null;
                    if ($xmlquestion === null) {
                        throw new \coding_exception(
                            'Generated Moodle question is missing from the XML preflight document.'
                        );
                    }

                    if ($qtype === 'multianswer') {
                        $this->assert_core_multianswer_accepts(
                            (string) $xmlquestion->questiontext->text,
                            (string) ($descriptor['source_ident'] ?? '')
                        );
                    } else if ($qtype === 'multichoice') {
                        $this->assert_core_multichoice_fractions_accept(
                            $xmlquestion,
                            (string) ($descriptor['source_ident'] ?? '')
                        );
                    }

                    if (($descriptor['transform'] ?? 'NATIVE') !== 'NATIVE') {
                        $transforms[] = [
                            'source_ident' => (string) ($descriptor['source_ident'] ?? ''),
                            'policy' => (string) ($descriptor['transform'] ?? ''),
                            'effective_moodle_qtype' => $qtype,
                            'max_score' => (float) ($descriptor['max_score'] ?? 0.0),
                        ];
                    }
                }
                $operation['phase6_validation']['moodle_xml_preflight'] = [
                    'status' => 'OK',
                    'sha256' => hash('sha256', $xml),
                    'bytes' => strlen($xml),
                    'question_count' => count($descriptors),
                    'effective_qtype_counts' => $qtypes,
                    'score_preserving_transforms' => $transforms,
                ];
            } catch (\Throwable $exception) {
                $preflightblocked++;
                $operation['action'] = 'BLOCKED';
                $operation['reason'] = 'PHASE6_MOODLE_XML_PREFLIGHT_FAILED';
                $operation['phase6_validation']['moodle_xml_preflight'] = [
                    'status' => 'BLOCKED',
                    'code' => 'PHASE6_MOODLE_XML_PREFLIGHT_FAILED',
                    'message' => $exception->getMessage(),
                ];
                $plan['warnings'][] = [
                    'code' => 'PHASE6_MOODLE_XML_PREFLIGHT_FAILED',
                    'source_ref_id' => $testref,
                    'message' => $exception->getMessage(),
                ];
            }
        }
        unset($operation);

        if (isset($plan['phase6_package'])) {
            if ($addedreviews > 0) {
                $plan['phase6_package']['scoring_review_count'] =
                    (int) ($plan['phase6_package']['scoring_review_count'] ?? 0)
                    + $addedreviews;
            }
            $plan['phase6_package']['multiple_choice_unselected_scoring_review_count'] = $multichoicereviews;
            $plan['phase6_package']['multiple_choice_nonstandard_fraction_review_count'] =
                $nonstandardfractionreviews;
            $plan['phase6_package']['score_preserving_transform_count'] = $transformedquestions;
            $plan['phase6_package']['moodle_xml_preflight_blocked_tests'] = $preflightblocked;
            $plan['phase6_package']['moodle_xml_preflight_ready'] = $preflightblocked === 0;
            $plan['phase6_package']['scoring_policy_ready'] = $preflightblocked === 0;
            $plan['phase6_package']['apply_implemented'] = true;
            if ($preflightblocked > 0) {
                $plan['phase6_package']['blocked_tests'] =
                    (int) ($plan['phase6_package']['blocked_tests'] ?? 0) + $preflightblocked;
                $plan['phase6_package']['ready'] = false;
            }
            $plan['phase6_package']['apply_ready'] =
                !empty($plan['phase6_package']['ready']) && $preflightblocked === 0;
        }

        // The old structural-validator warning is replaced by explicit policies.
        $plan['warnings'] = array_values(array_filter(
            $plan['warnings'],
            static fn(array $warning): bool => ($warning['code'] ?? '') !== 'PHASE6_APPLY_NOT_IMPLEMENTED'
        ));
        if (!empty($plan['phase6_package']['apply_ready'])) {
            $plan['warnings'][] = [
                'code' => 'PHASE6_SCORE_PRESERVING_TRANSFORMS_ENABLED',
                'transformed_question_count' => $transformedquestions,
                'message' => 'Phase 6 apply is enabled. Unequal-weight Matching, Multiple Choice with unselected-option credit, and Multiple Choice with non-standard Moodle fractions use score-preserving Cloze transforms; Kprim uses an exact response-combination single-choice transform, and Ordering uses native ABSOLUTE_POSITION grading.',
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

    /**
     * Check generated native multichoice answer fractions with the same strict
     * Moodle grade-option matcher used by qformat_xml during import.
     */
    private function assert_core_multichoice_fractions_accept(
        \SimpleXMLElement $question,
        string $sourceident
    ): void {
        global $CFG;

        if (!function_exists('match_grade_options')) {
            require_once($CFG->libdir . '/questionlib.php');
        }

        $options = \question_bank::fraction_options_full();
        foreach ($question->answer as $answer) {
            $percentage = (float) ($answer['fraction'] ?? 0.0);
            $fraction = $percentage / 100.0;
            if (match_grade_options($options, $fraction, 'error') === false) {
                throw new \coding_exception(
                    'Generated Moodle multichoice uses a fraction rejected by '
                    . 'Moodle XML import for '
                    . $sourceident
                    . ': '
                    . $this->format_fraction($fraction)
                );
            }
        }
    }

    /**
     * Run generated Cloze text through Moodle core's own embedded-answer parser.
     * This is read-only and catches syntax accepted by XML but rejected by
     * qtype_multianswer during the real import.
     */
    private function assert_core_multianswer_accepts(
        string $questiontext,
        string $sourceident
    ): void {
        global $CFG;

        require_once(
            $CFG->dirroot . '/question/type/multianswer/questiontype.php'
        );

        $parsed = qtype_multianswer_extract_question([
            'text' => $questiontext,
            'format' => FORMAT_HTML,
            'itemid' => '',
        ]);
        $errors = qtype_multianswer_validate_question($parsed);

        if ($errors) {
            throw new \coding_exception(
                'Generated Moodle Cloze failed the Moodle core parser for '
                . $sourceident
                . ': '
                . implode(' ', array_values($errors))
            );
        }

        if (empty($parsed->options->questions)) {
            throw new \coding_exception(
                'Generated Moodle Cloze contains no core-recognized embedded answer for '
                . $sourceident
                . '.'
            );
        }
    }

    /** Parse generated XML without network access or entity substitution. */
    private function parse_xml_without_network(string $xml): ?\SimpleXMLElement {
        if ($xml === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $parsed = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            if ($parsed === false) {
                $errors = libxml_get_errors();
                $message = $errors ? trim((string) $errors[0]->message) : 'unknown XML parse error';
                throw new \coding_exception('Generated Moodle XML is invalid: ' . $message);
            }
            if ($parsed->getName() !== 'quiz') {
                throw new \coding_exception('Generated Moodle XML root element must be <quiz>.');
            }
            return $parsed;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array<int,array{ident:string,fraction:float,score:float}> */
    private function nonstandard_selected_fraction_details(array $question): array {
        $maxscore = (float) ($question['max_score'] ?? 0.0);
        if ($maxscore <= 0.0) {
            return [];
        }

        $affected = [];
        foreach (($question['answers'] ?? []) as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $score = (float) ($answer['score_if_selected'] ?? 0.0);
            $fraction = $score / $maxscore;
            if ($this->is_moodle_standard_fraction($fraction)) {
                continue;
            }
            $affected[] = [
                'ident' => (string) ($answer['ident'] ?? ''),
                'fraction' => $fraction,
                'score' => $score,
            ];
        }
        return $affected;
    }

    private function can_preserve_multiple_choice_as_multiresponse(array $question): bool {
        $maxscore = (float) ($question['max_score'] ?? 0.0);
        if ($maxscore <= 0.0) {
            return false;
        }

        $positive = 0.0;
        $count = 0;
        foreach (($question['answers'] ?? []) as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $count++;
            if (abs((float) ($answer['score_if_not_selected'] ?? 0.0)) > 0.000000001) {
                return false;
            }
            $score = (float) ($answer['score_if_selected'] ?? 0.0);
            if ($score > 0.0) {
                $positive += $score;
            }
        }

        return $count >= 2 && abs($positive - $maxscore) <= 0.000001;
    }

    private function is_moodle_standard_fraction(float $fraction): bool {
        global $CFG;

        if (!function_exists('match_grade_options')) {
            require_once($CFG->libdir . '/questionlib.php');
        }

        return match_grade_options(
            \question_bank::fraction_options_full(),
            $fraction,
            'error'
        ) !== false;
    }

    private function format_fraction(float $fraction): string {
        return rtrim(rtrim(number_format($fraction, 10, '.', ''), '0'), '.');
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
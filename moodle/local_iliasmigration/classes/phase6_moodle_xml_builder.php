<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Converts the validated neutral Phase 6 question model to Moodle XML.
 *
 * The generated XML is consumed by Moodle's core qformat_xml importer. The
 * builder deliberately transforms ILIAS scoring semantics that Moodle's native
 * visual qtype cannot represent exactly into one Moodle Cloze question, keeping
 * one Moodle quiz slot per ILIAS question and preserving the maximum mark.
 */
final class phase6_moodle_xml_builder {
    /**
     * Build one Moodle XML document and an ordered import descriptor list.
     *
     * @param array $questions Validated questions.json document.
     * @param string $testref ILIAS test ref_id.
     * @return array{xml:string,questions:array}
     */
    public function build(array $questions, string $testref): array {
        $items = is_array($questions['questions'] ?? null) ? $questions['questions'] : [];
        if (!$items) {
            throw new \coding_exception('Phase 6 Moodle XML generation requires at least one question.');
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n";
        $descriptors = [];

        foreach ($items as $question) {
            if (!is_array($question)) {
                throw new \coding_exception('Invalid neutral question during Moodle XML generation.');
            }
            $descriptor = $this->descriptor($question, $testref);
            $xml .= $this->render_question($question, $descriptor);
            $descriptors[] = $descriptor;
        }

        $xml .= "</quiz>\n";
        return ['xml' => $xml, 'questions' => $descriptors];
    }

    /** Build stable source identity and effective Moodle qtype metadata. */
    private function descriptor(array $question, string $testref): array {
        $ident = trim((string) ($question['source_ident'] ?? ''));
        $external = trim((string) ($question['external_id'] ?? ''));
        $type = (string) ($question['type'] ?? '');
        $title = trim((string) ($question['title'] ?? ''));
        $maxscore = (float) ($question['max_score'] ?? 0.0);
        if ($ident === '' || $title === '' || $maxscore <= 0.0) {
            throw new \coding_exception('Neutral Phase 6 question identity/title/max score is incomplete.');
        }

        $transform = 'NATIVE';
        $effectiveqtype = match ($type) {
            'single_choice', 'multiple_choice' => 'multichoice',
            'numeric' => 'numerical',
            'essay' => 'essay',
            'short_answer' => 'shortanswer',
            'cloze' => 'multianswer',
            'ordering' => 'ordering',
            'matching' => 'match',
            default => throw new \coding_exception('Unsupported Phase 6 neutral question type: ' . $type),
        };

        if ($type === 'matching' && $this->matching_has_unequal_weights($question)) {
            $effectiveqtype = 'multianswer';
            $transform = 'WEIGHTED_MATCHING_TO_CLOZE';
        }
        if ($type === 'multiple_choice' && $this->has_unselected_scoring($question)) {
            $effectiveqtype = 'multianswer';
            $transform = 'MULTICHOICE_BINARY_DECISIONS_TO_CLOZE';
        }

        $fingerprintpayload = json_encode(
            $question,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($fingerprintpayload === false) {
            throw new \coding_exception('Unable to fingerprint a Phase 6 neutral question.');
        }
        $fingerprint = substr(hash('sha256', $fingerprintpayload), 0, 16);
        $stable = $external !== '' ? $external : $ident;
        $stable = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stable) ?: 'question';
        $idnumber = substr('i2m-' . $testref . '-' . $stable . '-' . $fingerprint, 0, 100);

        return [
            'source_ident' => $ident,
            'external_id' => $external,
            'mapping_ref' => $testref . ':' . ($external !== '' ? $external : $ident),
            'title' => $title,
            'neutral_type' => $type,
            'effective_qtype' => $effectiveqtype,
            'transform' => $transform,
            'max_score' => $maxscore,
            'fingerprint' => $fingerprint,
            'idnumber' => $idnumber,
        ];
    }

    /** Render one question according to its neutral type and scoring policy. */
    private function render_question(array $question, array $descriptor): string {
        $type = (string) $question['type'];
        if ($descriptor['transform'] === 'WEIGHTED_MATCHING_TO_CLOZE') {
            return $this->render_weighted_matching_cloze($question, $descriptor);
        }
        if ($descriptor['transform'] === 'MULTICHOICE_BINARY_DECISIONS_TO_CLOZE') {
            return $this->render_binary_multichoice_cloze($question, $descriptor);
        }

        return match ($type) {
            'single_choice' => $this->render_multichoice($question, $descriptor, true),
            'multiple_choice' => $this->render_multichoice($question, $descriptor, false),
            'numeric' => $this->render_numerical($question, $descriptor),
            'essay' => $this->render_essay($question, $descriptor),
            'short_answer' => $this->render_shortanswer($question, $descriptor),
            'cloze' => $this->render_native_cloze($question, $descriptor),
            'ordering' => $this->render_ordering($question, $descriptor),
            'matching' => $this->render_matching($question, $descriptor),
            default => throw new \coding_exception('Unsupported Phase 6 Moodle XML rendering type: ' . $type),
        };
    }

    /** Common question XML header. */
    private function header(string $qtype, array $descriptor, string $questiontext, bool $defaultgrade = true): string {
        $xml = "  <question type=\"" . $this->xml($qtype) . "\">\n";
        $xml .= "    <name><text>" . $this->xml((string) $descriptor['title']) . "</text></name>\n";
        $xml .= "    <questiontext format=\"html\"><text><![CDATA["
            . $this->cdata($questiontext) . "]]></text></questiontext>\n";
        $xml .= "    <generalfeedback format=\"html\"><text></text></generalfeedback>\n";
        if ($defaultgrade) {
            $xml .= "    <defaultgrade>" . $this->number((float) $descriptor['max_score']) . "</defaultgrade>\n";
        }
        $xml .= "    <penalty>0</penalty>\n";
        $xml .= "    <hidden>0</hidden>\n";
        $xml .= "    <idnumber>" . $this->xml((string) $descriptor['idnumber']) . "</idnumber>\n";
        return $xml;
    }

    /** Native single/multiple choice when ILIAS does not score unselected options. */
    private function render_multichoice(array $question, array $descriptor, bool $single): string {
        $maxscore = (float) $descriptor['max_score'];
        $xml = $this->header('multichoice', $descriptor, (string) ($question['question_text'] ?? ''));
        $xml .= '    <single>' . ($single ? 'true' : 'false') . "</single>\n";
        $xml .= '    <shuffleanswers>' . (!empty($question['shuffle']) ? 'true' : 'false') . "</shuffleanswers>\n";
        $xml .= "    <answernumbering>abc</answernumbering>\n";
        $xml .= "    <showstandardinstruction>0</showstandardinstruction>\n";

        foreach (($question['answers'] ?? []) as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $score = (float) ($answer['score_if_selected'] ?? 0.0);
            $fraction = $maxscore > 0.0 ? 100.0 * $score / $maxscore : 0.0;
            $xml .= '    <answer fraction="' . $this->number($fraction) . '" format="html">' . "\n";
            $xml .= '      <text><![CDATA[' . $this->cdata((string) ($answer['text'] ?? '')) . "]]></text>\n";
            $xml .= "      <feedback format=\"html\"><text></text></feedback>\n";
            $xml .= "    </answer>\n";
        }
        return $xml . "  </question>\n";
    }

    /** Native numerical. ILIAS [lower, upper] becomes midpoint +/- tolerance. */
    private function render_numerical(array $question, array $descriptor): string {
        $lower = $question['lower_bound'] ?? null;
        $upper = $question['upper_bound'] ?? null;
        if (!is_numeric($lower) || !is_numeric($upper) || (float) $upper < (float) $lower) {
            throw new \coding_exception('Phase 6 numerical question has invalid bounds.');
        }
        $answer = ((float) $lower + (float) $upper) / 2.0;
        $tolerance = ((float) $upper - (float) $lower) / 2.0;
        $xml = $this->header('numerical', $descriptor, (string) ($question['question_text'] ?? ''));
        $xml .= "    <answer fraction=\"100\" format=\"moodle_auto_format\">\n";
        $xml .= '      <text>' . $this->xml($this->number($answer)) . "</text>\n";
        $xml .= '      <tolerance>' . $this->number($tolerance) . "</tolerance>\n";
        $xml .= "      <feedback format=\"html\"><text></text></feedback>\n";
        $xml .= "    </answer>\n";
        $xml .= "    <unitgradingtype>0</unitgradingtype>\n";
        $xml .= "    <unitpenalty>0.1</unitpenalty>\n";
        $xml .= "    <showunits>3</showunits>\n";
        $xml .= "    <unitsleft>0</unitsleft>\n";
        return $xml . "  </question>\n";
    }

    /** Native manually graded Essay. */
    private function render_essay(array $question, array $descriptor): string {
        $xml = $this->header('essay', $descriptor, (string) ($question['question_text'] ?? ''));
        $xml .= "    <responseformat>editor</responseformat>\n";
        $xml .= "    <responserequired>1</responserequired>\n";
        $xml .= "    <responsefieldlines>15</responsefieldlines>\n";
        $xml .= "    <minwordlimit></minwordlimit>\n";
        $xml .= "    <maxwordlimit></maxwordlimit>\n";
        $xml .= "    <attachments>0</attachments>\n";
        $xml .= "    <attachmentsrequired>0</attachmentsrequired>\n";
        $xml .= "    <maxbytes>0</maxbytes>\n";
        $xml .= "    <filetypeslist></filetypeslist>\n";
        $xml .= "    <graderinfo format=\"html\"><text></text></graderinfo>\n";
        $xml .= "    <responsetemplate format=\"html\"><text></text></responsetemplate>\n";
        return $xml . "  </question>\n";
    }

    /** Native short answer. */
    private function render_shortanswer(array $question, array $descriptor): string {
        $maxscore = (float) $descriptor['max_score'];
        $xml = $this->header('shortanswer', $descriptor, (string) ($question['question_text'] ?? ''));
        $xml .= '    <usecase>' . (!empty($question['case_sensitive']) ? '1' : '0') . "</usecase>\n";
        foreach (($question['accepted_answers'] ?? []) as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $score = (float) ($answer['points'] ?? 0.0);
            $fraction = $maxscore > 0.0 ? 100.0 * $score / $maxscore : 0.0;
            $xml .= '    <answer fraction="' . $this->number($fraction) . '" format="moodle_auto_format">' . "\n";
            $xml .= '      <text>' . $this->xml((string) ($answer['text'] ?? '')) . "</text>\n";
            $xml .= "      <feedback format=\"html\"><text></text></feedback>\n";
            $xml .= "    </answer>\n";
        }
        return $xml . "  </question>\n";
    }

    /** Native ILIAS Cloze to Moodle embedded-answer syntax. */
    private function render_native_cloze(array $question, array $descriptor): string {
        $fragments = [];
        foreach (($question['text_fragments'] ?? []) as $fragment) {
            if (is_array($fragment)) {
                $fragments[] = (string) ($fragment['text'] ?? '');
            }
        }
        $questiontext = (string) ($question['question_text'] ?? '');
        if ($fragments && trim($fragments[0]) === trim($questiontext)) {
            array_shift($fragments);
        }

        $body = $questiontext;
        $gaps = is_array($question['gaps'] ?? null) ? $question['gaps'] : [];
        foreach ($gaps as $index => $gap) {
            if (!is_array($gap)) {
                continue;
            }
            $body .= $fragments[$index] ?? '';
            $body .= $this->cloze_shortanswer($gap, !empty($question['case_sensitive']));
        }
        $body .= implode('', array_slice($fragments, count($gaps)));

        $xml = $this->header('cloze', $descriptor, $body, false);
        return $xml . "  </question>\n";
    }

    /** Native Moodle Ordering with exact absolute-position partial grading. */
    private function render_ordering(array $question, array $descriptor): string {
        $order = is_array($question['correct_order'] ?? null) ? $question['correct_order'] : [];
        if (count($order) < 2) {
            throw new \coding_exception('Phase 6 ordering question requires at least two ordered items.');
        }
        usort($order, static fn(array $a, array $b): int => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));

        $xml = $this->header('ordering', $descriptor, (string) ($question['question_text'] ?? ''));
        $xml .= "    <layouttype>VERTICAL</layouttype>\n";
        $xml .= "    <selecttype>ALL</selecttype>\n";
        $xml .= '    <selectcount>' . count($order) . "</selectcount>\n";
        $xml .= "    <gradingtype>ABSOLUTE_POSITION</gradingtype>\n";
        $xml .= "    <showgrading>SHOW</showgrading>\n";
        $xml .= "    <numberingstyle>none</numberingstyle>\n";
        // Moodle Ordering import creates default hint options when shownumcorrect is absent.
        // Make it explicit so question_hints.hintformat is never inferred as NULL.
        $xml .= "    <shownumcorrect>1</shownumcorrect>\n";
        $xml .= "    <correctfeedback format=\"html\"><text></text></correctfeedback>\n";
        $xml .= "    <partiallycorrectfeedback format=\"html\"><text></text></partiallycorrectfeedback>\n";
        $xml .= "    <incorrectfeedback format=\"html\"><text></text></incorrectfeedback>\n";
        foreach ($order as $index => $entry) {
            $xml .= '    <answer fraction="' . ($index + 1) . '" format="html">' . "\n";
            $xml .= '      <text><![CDATA[' . $this->cdata((string) ($entry['text'] ?? '')) . "]]></text>\n";
            $xml .= "      <feedback format=\"html\"><text></text></feedback>\n";
            $xml .= "    </answer>\n";
        }
        return $xml . "  </question>\n";
    }

    /** Native equal-weight Matching, retained for future POCs. */
    private function render_matching(array $question, array $descriptor): string {
        $xml = $this->header('matching', $descriptor, (string) ($question['question_text'] ?? ''));
        $xml .= "    <shuffleanswers>true</shuffleanswers>\n";
        foreach (($question['pairs'] ?? []) as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $xml .= "    <subquestion format=\"html\">\n";
            $xml .= '      <text><![CDATA[' . $this->cdata((string) ($pair['source_text'] ?? '')) . "]]></text>\n";
            $xml .= '      <answer><text>' . $this->xml((string) ($pair['target_text'] ?? '')) . "</text></answer>\n";
            $xml .= "    </subquestion>\n";
        }
        return $xml . "  </question>\n";
    }

    /** Weighted ILIAS Matching -> one Moodle Cloze with weighted dropdowns. */
    private function render_weighted_matching_cloze(array $question, array $descriptor): string {
        $pairs = is_array($question['pairs'] ?? null) ? $question['pairs'] : [];
        $targets = [];
        foreach ($pairs as $pair) {
            if (is_array($pair)) {
                $target = (string) ($pair['target_text'] ?? '');
                if ($target !== '' && !in_array($target, $targets, true)) {
                    $targets[] = $target;
                }
            }
        }
        if (!$pairs || count($targets) < 2) {
            throw new \coding_exception('Weighted Matching transform requires pairs and at least two target choices.');
        }

        $body = (string) ($question['question_text'] ?? '');
        $body .= '<table class="ilias2moodle-weighted-matching">';
        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $weight = (float) ($pair['points'] ?? 0.0);
            $source = (string) ($pair['source_text'] ?? '');
            $correct = (string) ($pair['target_text'] ?? '');
            if ($weight <= 0.0 || $source === '' || $correct === '') {
                throw new \coding_exception('Weighted Matching pair is incomplete.');
            }
            $body .= '<tr><td>' . s($source) . '</td><td>'
                . $this->cloze_choice($weight, $correct, $targets) . '</td></tr>';
        }
        $body .= '</table>';

        $xml = $this->header('cloze', $descriptor, $body, false);
        return $xml . "  </question>\n";
    }

    /** ILIAS MCMR with credit for unselected options -> explicit binary Cloze decisions. */
    private function render_binary_multichoice_cloze(array $question, array $descriptor): string {
        $body = (string) ($question['question_text'] ?? '');
        $body .= '<ol class="ilias2moodle-binary-multichoice">';
        $rendered = 0;
        foreach (($question['answers'] ?? []) as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $selected = (float) ($answer['score_if_selected'] ?? 0.0);
            $unselected = (float) ($answer['score_if_not_selected'] ?? 0.0);
            $weight = max($selected, $unselected);
            if ($weight <= 0.0) {
                continue;
            }
            $rendered++;
            $body .= '<li>' . s((string) ($answer['text'] ?? '')) . ' : '
                . $this->cloze_binary_decision($weight, $selected, $unselected)
                . '</li>';
        }
        $body .= '</ol>';
        $body .= '<p><em>Migration ILIAS : chaque proposition doit être explicitement marquée '
            . '« sélectionner » ou « ne pas sélectionner » afin de conserver le barème source.</em></p>';
        if ($rendered === 0) {
            throw new \coding_exception('Binary Multiple Choice transform produced no scored decisions.');
        }

        $xml = $this->header('cloze', $descriptor, $body, false);
        return $xml . "  </question>\n";
    }

    /** One embedded short-answer field. */
    private function cloze_shortanswer(array $gap, bool $casesensitive): string {
        $weight = (float) ($gap['max_score'] ?? 0.0);
        $accepted = is_array($gap['accepted_answers'] ?? null) ? $gap['accepted_answers'] : [];
        if ($weight <= 0.0 || !$accepted) {
            throw new \coding_exception('Cloze gap has no accepted answer or positive score.');
        }
        $type = $casesensitive ? 'SHORTANSWER_C' : 'SHORTANSWER';
        $parts = [];
        foreach ($accepted as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $score = (float) ($answer['points'] ?? 0.0);
            $fraction = $weight > 0.0 ? 100.0 * $score / $weight : 0.0;
            $text = $this->cloze_escape((string) ($answer['text'] ?? ''));
            if (abs($fraction - 100.0) < 0.000001) {
                $parts[] = '=' . $text;
            } else {
                $parts[] = '%' . $this->number($fraction) . '%' . $text;
            }
        }
        return '{' . $this->number($weight) . ':' . $type . ':' . implode('~', $parts) . '}';
    }

    /** One weighted dropdown for Matching. */
    private function cloze_choice(float $weight, string $correct, array $options): string {
        $parts = [];
        foreach ($options as $option) {
            $escaped = $this->cloze_escape((string) $option);
            $parts[] = ((string) $option === $correct ? '=' : '') . $escaped;
        }
        return '{' . $this->number($weight) . ':MULTICHOICE:' . implode('~', $parts) . '}';
    }

    /** One explicit selected/unselected decision with exact ILIAS fractions. */
    private function cloze_binary_decision(float $weight, float $selected, float $unselected): string {
        $states = [
            ['label' => 'Ne pas sélectionner', 'score' => $unselected],
            ['label' => 'Sélectionner', 'score' => $selected],
        ];
        $parts = [];
        foreach ($states as $state) {
            $fraction = 100.0 * (float) $state['score'] / $weight;
            $label = $this->cloze_escape((string) $state['label']);
            if (abs($fraction - 100.0) < 0.000001) {
                $parts[] = '=' . $label;
            } else if (abs($fraction) < 0.000001) {
                $parts[] = $label;
            } else {
                $parts[] = '%' . $this->number($fraction) . '%' . $label;
            }
        }
        return '{' . $this->number($weight) . ':MULTICHOICE:' . implode('~', $parts) . '}';
    }

    /** True when Matching pair weights are not all identical. */
    private function matching_has_unequal_weights(array $question): bool {
        $weights = [];
        foreach (($question['pairs'] ?? []) as $pair) {
            if (is_array($pair)) {
                $weights[] = round((float) ($pair['points'] ?? 0.0), 9);
            }
        }
        return count(array_unique($weights, SORT_REGULAR)) > 1;
    }

    /** True when at least one choice receives credit when not selected. */
    private function has_unselected_scoring(array $question): bool {
        foreach (($question['answers'] ?? []) as $answer) {
            if (is_array($answer) && abs((float) ($answer['score_if_not_selected'] ?? 0.0)) > 0.000000001) {
                return true;
            }
        }
        return false;
    }

    /** Escape XML text nodes/attributes. */
    private function xml(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Protect CDATA terminators. */
    private function cdata(string $value): string {
        return str_replace(']]>', ']]]]><![CDATA[>', $value);
    }

    /** Escape Moodle Cloze syntax metacharacters. */
    private function cloze_escape(string $value): string {
        return strtr($value, [
            '\\' => '\\\\',
            '~' => '\\~',
            '=' => '\\=',
            '#' => '\\#',
            '{' => '\\{',
            '}' => '\\}',
            ':' => '\\:',
        ]);
    }

    /** Stable non-scientific decimal representation. */
    private function number(float $value): string {
        if (abs($value - round($value)) < 0.000000001) {
            return (string) (int) round($value);
        }
        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
    }
}

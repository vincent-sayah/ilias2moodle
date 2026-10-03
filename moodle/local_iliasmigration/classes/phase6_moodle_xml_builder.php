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
        $mappingrefs = [];

        foreach ($items as $question) {
            if (!is_array($question)) {
                throw new \coding_exception('Invalid neutral question during Moodle XML generation.');
            }
            $descriptor = $this->descriptor($question, $testref);
            $mappingref = (string) $descriptor['mapping_ref'];
            if (isset($mappingrefs[$mappingref])) {
                throw new \coding_exception(
                    'Duplicate Phase 6 persistent question mapping identity: ' . $mappingref
                );
            }
            $mappingrefs[$mappingref] = true;

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
            'kprim' => 'multichoice',
            default => throw new \coding_exception('Unsupported Phase 6 neutral question type: ' . $type),
        };

        if ($type === 'matching' && $this->matching_has_media($question)) {
            $effectiveqtype = 'multianswer';
            $transform = 'IMAGE_MATCHING_TO_CLOZE';
        } else if ($type === 'matching' && $this->matching_has_unequal_weights($question)) {
            $effectiveqtype = 'multianswer';
            $transform = 'WEIGHTED_MATCHING_TO_CLOZE';
        }
        if ($type === 'multiple_choice' && $this->has_unselected_scoring($question)) {
            $effectiveqtype = 'multianswer';
            $transform = 'MULTICHOICE_BINARY_DECISIONS_TO_CLOZE';
        } else if (
            $type === 'multiple_choice'
            && $this->has_nonstandard_selected_fractions($question)
        ) {
            if (!$this->can_preserve_multiple_choice_as_multiresponse($question)) {
                throw new \coding_exception(
                    'ILIAS Multiple Choice uses non-standard Moodle fractions '
                    . 'that cannot be preserved exactly as one Cloze MULTIRESPONSE question.'
                );
            }
            $effectiveqtype = 'multianswer';
            $transform = 'MULTICHOICE_NONSTANDARD_FRACTIONS_TO_CLOZE';
        }
        if ($type === 'kprim') {
            $effectiveqtype = 'multichoice';
            $transform = 'KPRIM_COMBINATIONS_TO_MULTICHOICE';
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

        $mappingref = $testref . ':' . $ident;
        if (strlen($mappingref) > 64) {
            throw new \coding_exception(
                'Phase 6 persistent question mapping identity exceeds the plugin schema limit.'
            );
        }

        return [
            'source_ident' => $ident,
            'external_id' => $external,
            // source_ident is the container-local ILIAS question identity.
            // external_id is not safe as a mapping key: real ILIAS exports can
            // reuse it for distinct questions. Keep external_id as metadata and
            // use source_ident for persistent one-to-one mappings.
            'mapping_ref' => $mappingref,
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
        if ($descriptor['transform'] === 'IMAGE_MATCHING_TO_CLOZE') {
            return $this->render_image_matching_cloze($question, $descriptor);
        }
        if ($descriptor['transform'] === 'WEIGHTED_MATCHING_TO_CLOZE') {
            return $this->render_weighted_matching_cloze($question, $descriptor);
        }
        if ($descriptor['transform'] === 'MULTICHOICE_BINARY_DECISIONS_TO_CLOZE') {
            return $this->render_binary_multichoice_cloze($question, $descriptor);
        }
        if ($descriptor['transform'] === 'MULTICHOICE_NONSTANDARD_FRACTIONS_TO_CLOZE') {
            return $this->render_fractional_multichoice_cloze($question, $descriptor);
        }
        if ($descriptor['transform'] === 'KPRIM_COMBINATIONS_TO_MULTICHOICE') {
            return $this->render_kprim_combinations_multichoice($question, $descriptor);
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
    private function header(
        string $qtype,
        array $descriptor,
        string $questiontext,
        bool $defaultgrade = true,
        array $questionfiles = []
    ): string {
        $xml = "  <question type=\"" . $this->xml($qtype) . "\">\n";
        $xml .= "    <name><text>" . $this->xml((string) $descriptor['title']) . "</text></name>\n";
        $xml .= "    <questiontext format=\"html\"><text><![CDATA["
            . $this->cdata($questiontext) . "]]></text>\n";
        foreach ($questionfiles as $file) {
            if (!is_array($file)) {
                continue;
            }
            $name = trim((string) ($file['name'] ?? ''));
            $data = preg_replace('/\\s+/', '', (string) ($file['data'] ?? ''));
            if ($name === '' || $data === '' || base64_decode($data, true) === false) {
                throw new \coding_exception(
                    'Phase 6 embedded question file is invalid.'
                );
            }
            $xml .= '      <file name="' . $this->xml($name)
                . '" path="/" encoding="base64">'
                . $data
                . "</file>\n";
        }
        $xml .= "    </questiontext>\n";
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

        $weights = [];
        foreach ($gaps as $gap) {
            if (is_array($gap)) {
                $weights[] = (float) ($gap['max_score'] ?? 0.0);
            }
        }
        $norms = $this->cloze_integer_norms($weights);

        $renderedgap = 0;
        foreach ($gaps as $index => $gap) {
            if (!is_array($gap)) {
                continue;
            }
            $body .= $fragments[$index] ?? '';
            $norm = (int) ($norms[$renderedgap] ?? 0);
            $renderedgap++;

            if (($gap['input_type'] ?? 'text') === 'numeric') {
                $body .= $this->cloze_numerical($gap, $norm);
            } else {
                $body .= $this->cloze_shortanswer(
                    $gap,
                    !empty($question['case_sensitive']),
                    $norm
                );
            }
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

    /**
     * ILIAS Matching containing embedded images -> Moodle Cloze.
     *
     * Image sources are displayed directly beside a textual dropdown.
     * When targets are images, the images are rendered in an A/B/C legend and
     * the dropdown uses those stable tokens because HTML <option> elements
     * cannot reliably display embedded images.
     */
    private function render_image_matching_cloze(
        array $question,
        array $descriptor
    ): string {
        $pairs = array_values(array_filter(
            (array) ($question['pairs'] ?? []),
            'is_array'
        ));
        $labels = [];
        foreach (($question['labels'] ?? []) as $label) {
            if (!is_array($label)) {
                continue;
            }
            $ident = (string) ($label['ident'] ?? '');
            if ($ident !== '') {
                $labels[$ident] = $label;
            }
        }

        if (!$pairs || !$labels) {
            throw new \coding_exception(
                'Image Matching transform requires pairs and response labels.'
            );
        }

        $weights = array_map(
            static fn(array $pair): float => (float) ($pair['points'] ?? 0.0),
            $pairs
        );
        $norms = $this->cloze_integer_norms($weights);

        $files = [];
        $targetidents = [];
        $targethasmedia = false;
        foreach ($pairs as $pair) {
            $targetident = (string) ($pair['target_ident'] ?? '');
            if ($targetident === '' || !isset($labels[$targetident])) {
                throw new \coding_exception(
                    'Image Matching target identity is missing from response labels.'
                );
            }
            if (!in_array($targetident, $targetidents, true)) {
                $targetidents[] = $targetident;
            }
            if ($this->label_has_media($labels[$targetident])) {
                $targethasmedia = true;
            }
        }

        $options = [];
        $correctbytarget = [];
        $legend = '';

        if ($targethasmedia) {
            $legend .= '<ol class="ilias2moodle-image-matching-legend" type="A">';
            foreach ($targetidents as $index => $targetident) {
                $token = $this->alpha_token($index);
                $target = $labels[$targetident];
                $display = $this->matching_label_display(
                    $target,
                    (string) $descriptor['source_ident'],
                    $files
                );
                if ($display === '') {
                    throw new \coding_exception(
                        'Image Matching target has neither text nor renderable media.'
                    );
                }
                $options[] = $token;
                $correctbytarget[$targetident] = $token;
                $legend .= '<li><strong>' . s($token) . '</strong> — '
                    . $display . '</li>';
            }
            $legend .= '</ol>';
        } else {
            foreach ($targetidents as $targetident) {
                $target = $labels[$targetident];
                $text = trim((string) ($target['text'] ?? ''));
                if ($text === '') {
                    throw new \coding_exception(
                        'Image Matching textual target is empty.'
                    );
                }
                if (!in_array($text, $options, true)) {
                    $options[] = $text;
                }
                $correctbytarget[$targetident] = $text;
            }
        }

        if (count($options) < 2) {
            throw new \coding_exception(
                'Image Matching transform requires at least two target choices.'
            );
        }

        $body = (string) ($question['question_text'] ?? '');
        $body .= $legend;
        $body .= '<table class="ilias2moodle-image-matching">';

        foreach ($pairs as $index => $pair) {
            $sourceident = (string) ($pair['source_ident'] ?? '');
            $targetident = (string) ($pair['target_ident'] ?? '');
            if ($sourceident === '' || !isset($labels[$sourceident])) {
                throw new \coding_exception(
                    'Image Matching source identity is missing from response labels.'
                );
            }

            $source = $this->matching_label_display(
                $labels[$sourceident],
                (string) $descriptor['source_ident'],
                $files
            );
            $correct = (string) ($correctbytarget[$targetident] ?? '');
            $weight = (float) ($pair['points'] ?? 0.0);
            $norm = (int) ($norms[$index] ?? 0);

            if ($source === '' || $correct === '' || $weight <= 0.0) {
                throw new \coding_exception(
                    'Image Matching pair is incomplete after media normalization.'
                );
            }

            $body .= '<tr><td>' . $source . '</td><td>'
                . $this->cloze_choice($weight, $correct, $options, $norm)
                . '</td></tr>';
        }

        $body .= '</table>';

        $xml = $this->header(
            'cloze',
            $descriptor,
            $body,
            false,
            array_values($files)
        );
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

        $weights = [];
        foreach ($pairs as $pair) {
            if (is_array($pair)) {
                $weights[] = (float) ($pair['points'] ?? 0.0);
            }
        }
        $norms = $this->cloze_integer_norms($weights);

        $body = (string) ($question['question_text'] ?? '');
        $body .= '<table class="ilias2moodle-weighted-matching">';
        $renderedpair = 0;
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
            $norm = (int) ($norms[$renderedpair] ?? 0);
            $renderedpair++;
            $body .= '<tr><td>' . s($source) . '</td><td>'
                . $this->cloze_choice($weight, $correct, $targets, $norm)
                . '</td></tr>';
        }
        $body .= '</table>';

        $xml = $this->header('cloze', $descriptor, $body, false);
        return $xml . "  </question>\n";
    }

    /** ILIAS MCMR with credit for unselected options -> explicit binary Cloze decisions. */
    private function render_binary_multichoice_cloze(array $question, array $descriptor): string {
        $decisions = [];
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
            $decisions[] = [
                'text' => (string) ($answer['text'] ?? ''),
                'selected' => $selected,
                'unselected' => $unselected,
                'weight' => $weight,
            ];
        }

        if (!$decisions) {
            throw new \coding_exception('Binary Multiple Choice transform produced no scored decisions.');
        }

        $norms = $this->cloze_integer_norms(array_column($decisions, 'weight'));

        $body = (string) ($question['question_text'] ?? '');
        $body .= '<ol class="ilias2moodle-binary-multichoice">';
        foreach ($decisions as $index => $decision) {
            $body .= '<li>' . s((string) $decision['text']) . ' : '
                . $this->cloze_binary_decision(
                    (float) $decision['weight'],
                    (float) $decision['selected'],
                    (float) $decision['unselected'],
                    (int) ($norms[$index] ?? 0)
                )
                . '</li>';
        }
        $body .= '</ol>';
        $body .= '<p><em>Migration ILIAS : chaque proposition doit être explicitement marquée '
            . '« sélectionner » ou « ne pas sélectionner » afin de conserver le barème source.</em></p>';

        $xml = $this->header('cloze', $descriptor, $body, false);
        return $xml . "  </question>\n";
    }

    /**
     * ILIAS MCMR with exact non-standard fractions -> one Moodle Cloze
     * MULTIRESPONSE subquestion. This avoids qformat_xml's fixed fraction
     * whitelist while preserving each selected-answer fraction exactly.
     */
    private function render_fractional_multichoice_cloze(
        array $question,
        array $descriptor
    ): string {
        $maxscore = (float) ($descriptor['max_score'] ?? 0.0);
        $answers = array_values(array_filter(
            (array) ($question['answers'] ?? []),
            'is_array'
        ));

        if ($maxscore <= 0.0 || count($answers) < 2) {
            throw new \coding_exception(
                'Fractional Multiple Choice transform requires at least two answers and a positive score.'
            );
        }

        $parts = [];
        $positive = 0.0;
        foreach ($answers as $answer) {
            $score = (float) ($answer['score_if_selected'] ?? 0.0);
            $unselected = (float) ($answer['score_if_not_selected'] ?? 0.0);
            if (abs($unselected) > 0.000000001) {
                throw new \coding_exception(
                    'Fractional Multiple Choice transform does not accept unselected-option scoring.'
                );
            }

            $fraction = 100.0 * $score / $maxscore;
            if ($fraction > 100.000001 || $fraction < -100.000001) {
                throw new \coding_exception(
                    'Fractional Multiple Choice answer fraction is outside Moodle Cloze limits.'
                );
            }
            if ($score > 0.0) {
                $positive += $score;
            }

            $label = $this->cloze_escape((string) ($answer['text'] ?? ''));
            if (abs($fraction - 100.0) < 0.000001) {
                $prefix = '=';
            } else if (abs($fraction) < 0.000001) {
                $prefix = '';
            } else {
                $prefix = '%' . $this->number($fraction) . '%';
            }
            $parts[] = $prefix . $label;
        }

        if (abs($positive - $maxscore) > 0.000001) {
            throw new \coding_exception(
                'Fractional Multiple Choice positive scores do not sum to the ILIAS maximum score.'
            );
        }

        $body = (string) ($question['question_text'] ?? '');
        $body .= '<div class="ilias2moodle-fractional-multichoice">';
        $body .= '{1:MULTIRESPONSE:' . implode('~', $parts) . '}';
        $body .= '</div>';

        $xml = $this->header('cloze', $descriptor, $body, false);
        return $xml . "  </question>\n";
    }

    /**
     * ILIAS Kprim -> one Moodle single-choice question enumerating all binary
     * response combinations. This preserves the complete QTI score table,
     * including all-or-nothing or partial-credit Kprim policies.
     */
    private function render_kprim_combinations_multichoice(
        array $question,
        array $descriptor
    ): string {
        $answers = array_values(array_filter(
            (array) ($question['answers'] ?? []),
            'is_array'
        ));
        $combinations = array_values(array_filter(
            (array) ($question['combinations'] ?? []),
            'is_array'
        ));
        $maxscore = (float) ($descriptor['max_score'] ?? 0.0);

        if (!$answers || !$combinations || $maxscore <= 0.0) {
            throw new \coding_exception(
                'Kprim transform requires answer statements, response combinations and a positive score.'
            );
        }

        $body = (string) ($question['question_text'] ?? '');
        $body .= '<ol class="ilias2moodle-kprim-statements">';
        foreach ($answers as $answer) {
            $text = trim((string) ($answer['text'] ?? ''));
            if ($text === '') {
                throw new \coding_exception(
                    'Kprim transform found an empty statement.'
                );
            }
            $body .= '<li>' . s($text) . '</li>';
        }
        $body .= '</ol>';
        $body .= '<p><em>Migration ILIAS Kprim : chaque réponse code les états des propositions '
            . 'dans leur ordre d’affichage (1/0).</em></p>';

        $xml = $this->header(
            'multichoice',
            $descriptor,
            $body
        );
        $xml .= "    <single>true</single>\n";
        $xml .= "    <shuffleanswers>false</shuffleanswers>\n";
        $xml .= "    <answernumbering>none</answernumbering>\n";
        $xml .= "    <showstandardinstruction>0</showstandardinstruction>\n";

        foreach ($combinations as $combination) {
            $states = array_values(array_filter(
                (array) ($combination['states'] ?? []),
                'is_array'
            ));
            if (count($states) !== count($answers)) {
                throw new \coding_exception(
                    'Kprim response combination does not match the statement count.'
                );
            }

            $labels = [];
            foreach ($states as $index => $state) {
                $labels[] = ($index + 1) . '='
                    . (!empty($state['selected']) ? '1' : '0');
            }

            $score = (float) ($combination['score'] ?? 0.0);
            $fraction = 100.0 * $score / $maxscore;
            if ($fraction > 100.000001 || $fraction < -100.000001) {
                throw new \coding_exception(
                    'Kprim response combination fraction is outside Moodle multichoice limits.'
                );
            }

            $xml .= '    <answer fraction="' . $this->number($fraction)
                . '" format="html">' . "\n";
            $xml .= '      <text><![CDATA['
                . $this->cdata(implode(' ; ', $labels))
                . "]]></text>\n";
            $xml .= "      <feedback format=\"html\"><text></text></feedback>\n";
            $xml .= "    </answer>\n";
        }

        return $xml . "  </question>\n";
    }

    /** One embedded short-answer field. */
    private function cloze_shortanswer(
        array $gap,
        bool $casesensitive,
        int $norm
    ): string {
        $weight = (float) ($gap['max_score'] ?? 0.0);
        $accepted = is_array($gap['accepted_answers'] ?? null)
            ? $gap['accepted_answers']
            : [];
        if ($weight <= 0.0 || !$accepted || $norm <= 0) {
            throw new \coding_exception(
                'Cloze gap has no accepted answer, positive score or integer norm.'
            );
        }
        $type = $casesensitive ? 'SHORTANSWER_C' : 'SHORTANSWER';
        $parts = [];
        foreach ($accepted as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $score = (float) ($answer['points'] ?? 0.0);
            $fraction = 100.0 * $score / $weight;
            $text = $this->cloze_escape((string) ($answer['text'] ?? ''));
            if (abs($fraction - 100.0) < 0.000001) {
                $parts[] = '=' . $text;
            } else {
                $parts[] = '%' . $this->number($fraction) . '%' . $text;
            }
        }
        return '{' . $norm . ':' . $type . ':' . implode('~', $parts) . '}';
    }

    /** One exact numerical embedded-answer field. */
    private function cloze_numerical(array $gap, int $norm): string {
        $weight = (float) ($gap['max_score'] ?? 0.0);
        $accepted = is_array($gap['accepted_answers'] ?? null)
            ? $gap['accepted_answers']
            : [];
        if ($weight <= 0.0 || !$accepted || $norm <= 0) {
            throw new \coding_exception(
                'Numeric Cloze gap has no accepted answer, positive score or integer norm.'
            );
        }

        $parts = [];
        foreach ($accepted as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            if (($answer['comparison'] ?? '') !== 'varequal') {
                throw new \coding_exception(
                    'Numeric Cloze currently requires exact ILIAS varequal scoring.'
                );
            }

            $raw = trim((string) ($answer['text'] ?? ''));
            if ($raw === '' || !is_numeric($raw)) {
                throw new \coding_exception(
                    'Numeric Cloze accepted answer must be numeric.'
                );
            }

            $score = (float) ($answer['points'] ?? 0.0);
            $fraction = 100.0 * $score / $weight;
            $value = $this->number((float) $raw);
            $prefix = abs($fraction - 100.0) < 0.000001
                ? '='
                : '%' . $this->number($fraction) . '%';
            $parts[] = $prefix . $value . ':0';
        }

        if (!$parts) {
            throw new \coding_exception(
                'Numeric Cloze transform produced no accepted answer.'
            );
        }

        return '{'
            . $norm
            . ':NUMERICAL:'
            . implode('~', $parts)
            . '}';
    }

    /** One weighted dropdown for Matching. */
    private function cloze_choice(
        float $weight,
        string $correct,
        array $options,
        int $norm
    ): string {
        if ($weight <= 0.0 || $norm <= 0) {
            throw new \coding_exception('Matching Cloze norm/weight must be positive.');
        }
        $parts = [];
        foreach ($options as $option) {
            $escaped = $this->cloze_escape((string) $option);
            $parts[] = ((string) $option === $correct ? '=' : '') . $escaped;
        }
        return '{' . $norm . ':MULTICHOICE:' . implode('~', $parts) . '}';
    }

    /** One explicit selected/unselected decision with exact ILIAS fractions. */
    private function cloze_binary_decision(
        float $weight,
        float $selected,
        float $unselected,
        int $norm
    ): string {
        if ($weight <= 0.0 || $norm <= 0) {
            throw new \coding_exception('Binary Cloze norm/weight must be positive.');
        }

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
        return '{' . $norm . ':MULTICHOICE:' . implode('~', $parts) . '}';
    }

    /**
     * Convert positive source weights to the smallest equivalent integer
     * Moodle Cloze norms. Moodle core only accepts digits before the first
     * colon of an embedded answer.
     *
     * @param float[] $weights
     * @return int[]
     */
    private function cloze_integer_norms(array $weights): array {
        if (!$weights) {
            return [];
        }

        $precision = 0;
        foreach ($weights as $weight) {
            $weight = (float) $weight;
            if ($weight <= 0.0) {
                throw new \coding_exception('Cloze source weights must be positive.');
            }

            $rendered = $this->number($weight);
            $point = strpos($rendered, '.');
            if ($point !== false) {
                $precision = max(
                    $precision,
                    strlen(rtrim(substr($rendered, $point + 1), '0'))
                );
            }
        }

        if ($precision > 4) {
            throw new \coding_exception(
                'Cloze source weights require more than four decimal places; exact integer normalization is refused.'
            );
        }

        $scale = 10 ** $precision;
        $integers = [];
        foreach ($weights as $weight) {
            $scaled = (float) $weight * $scale;
            $rounded = (int) round($scaled);
            if (abs($scaled - $rounded) > 0.0000001 || $rounded <= 0) {
                throw new \coding_exception(
                    'Cloze source weights cannot be represented exactly as bounded integer norms.'
                );
            }
            $integers[] = $rounded;
        }

        $gcd = array_shift($integers);
        foreach ($integers as $value) {
            $gcd = $this->integer_gcd($gcd, $value);
        }

        $normalized = [];
        foreach ($weights as $weight) {
            $normalized[] = (int) (round((float) $weight * $scale) / $gcd);
        }

        if (array_sum($normalized) > 10000) {
            throw new \coding_exception(
                'Normalized Moodle Cloze weights are unexpectedly large; exact conversion is refused.'
            );
        }

        return $normalized;
    }

    /** Greatest common divisor for positive integers. */
    private function integer_gcd(int $a, int $b): int {
        $a = abs($a);
        $b = abs($b);
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return max(1, $a);
    }

    /** True when a Matching question contains media in any response label. */
    private function matching_has_media(array $question): bool {
        foreach (($question['labels'] ?? []) as $label) {
            if (is_array($label) && $this->label_has_media($label)) {
                return true;
            }
        }
        return false;
    }

    private function label_has_media(array $label): bool {
        foreach (($label['media'] ?? []) as $media) {
            if (is_array($media) && ($media['kind'] ?? '') === 'image') {
                return true;
            }
        }
        return false;
    }

    /**
     * Render one response label and register embedded files for qformat_xml.
     *
     * @param array<string,array{name:string,data:string}> $files
     */
    private function matching_label_display(
        array $label,
        string $questionident,
        array &$files
    ): string {
        $parts = [];
        $text = trim((string) ($label['text'] ?? ''));
        if ($text !== '') {
            $parts[] = s($text);
        }

        $labelident = (string) ($label['ident'] ?? 'label');
        foreach (($label['media'] ?? []) as $index => $media) {
            if (!is_array($media) || ($media['kind'] ?? '') !== 'image') {
                continue;
            }
            if (($media['encoding'] ?? '') !== 'base64' || empty($media['valid_base64'])) {
                throw new \coding_exception(
                    'ILIAS Matching image is not a validated embedded base64 asset.'
                );
            }

            $data = preg_replace('/\\s+/', '', (string) ($media['data'] ?? ''));
            if ($data === '' || base64_decode($data, true) === false) {
                throw new \coding_exception(
                    'ILIAS Matching image base64 payload is invalid.'
                );
            }

            $original = basename((string) ($media['filename'] ?? 'image.bin'));
            $safeoriginal = preg_replace('/[^A-Za-z0-9._-]+/', '_', $original)
                ?: 'image.bin';
            $prefix = preg_replace(
                '/[^A-Za-z0-9_-]+/',
                '_',
                $questionident . '-' . $labelident . '-' . ($index + 1)
            ) ?: 'image';
            $name = substr($prefix . '-' . $safeoriginal, 0, 180);

            if (isset($files[$name]) && $files[$name]['data'] !== $data) {
                throw new \coding_exception(
                    'ILIAS Matching generated two different assets with the same Moodle filename.'
                );
            }
            $files[$name] = [
                'name' => $name,
                'data' => $data,
            ];

            $alt = $text !== '' ? $text : ('ILIAS image ' . $labelident);
            $parts[] = '<img src="@@PLUGINFILE@@/' . s($name)
                . '" alt="' . s($alt)
                . '" style="max-width:180px;height:auto" />';
        }

        return implode(' ', $parts);
    }

    /** A, B, ..., Z, AA, AB ... stable labels for image target legends. */
    private function alpha_token(int $index): string {
        $value = $index + 1;
        $token = '';
        while ($value > 0) {
            $value--;
            $token = chr(65 + ($value % 26)) . $token;
            $value = intdiv($value, 26);
        }
        return $token;
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

    /**
     * True when at least one selected-answer fraction would be rejected by
     * Moodle XML import with matchgrades=error.
     */
    private function has_nonstandard_selected_fractions(array $question): bool {
        $maxscore = (float) ($question['max_score'] ?? 0.0);
        if ($maxscore <= 0.0) {
            return false;
        }

        foreach (($question['answers'] ?? []) as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $fraction = (float) ($answer['score_if_selected'] ?? 0.0) / $maxscore;
            if (!$this->is_moodle_standard_fraction($fraction)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The Cloze MULTIRESPONSE parser normalizes positive fractions to 1.0.
     * Exact preservation is therefore safe only when ILIAS positive selected
     * scores already sum to the question maximum and unselected scoring is 0.
     */
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

    /** Match Moodle core's strict XML-import fraction policy. */
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
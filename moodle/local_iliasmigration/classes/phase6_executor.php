<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Executes Phase 6 Question Bank + Quiz migration using Moodle APIs/importers.
 */
final class phase6_executor {
    /** @var string Absolute migration.json path. */
    private string $migrationjson;

    /** @var string Canonical package root. */
    private string $packageroot;

    /** @var string ILIAS source instance identity. */
    private string $sourceinstance = '';

    public function __construct(string $migrationjson) {
        $file = realpath($migrationjson);
        $root = realpath(dirname($migrationjson));
        if ($file === false || !is_file($file) || $root === false || !is_dir($root)) {
            throw new \coding_exception('Unable to resolve Phase 6 migration.json/package root.');
        }
        $this->migrationjson = $file;
        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
    }

    /**
     * Apply Phase 6 after a fresh dry-run-equivalent validation.
     */
    public function execute(array $document, int $categoryid): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        require_once($CFG->libdir . '/questionlib.php');

        $plan = (new phase6_plan_builder($categoryid))->build($document);
        $plan = (new phase3_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase4_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase5_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase6_package_validator($this->migrationjson))->validate($plan);
        $plan = (new phase6_scoring_policy_validator($this->migrationjson))->validate($plan);
        $this->assert_applyable($plan);

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourseid = (string) ($document['course']['source_id'] ?? '');
        $sourceversion = (string) ($document['source']['version'] ?? '');
        $courseoperation = $plan['operations'][0] ?? null;
        if (!is_array($courseoperation) || ($courseoperation['kind'] ?? '') !== 'course') {
            throw new \coding_exception('Phase 6 execution plan does not contain the target Moodle course.');
        }
        $courseid = (int) ($courseoperation['target_id'] ?? 0);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        $originaluser = $USER;
        \core\session\manager::set_user(get_admin());
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $results = [];
                foreach ($plan['operations'] as $operation) {
                    $kind = (string) ($operation['kind'] ?? '');
                    if ($kind === 'question_pool') {
                        $sectionnumber = $this->resolve_parent_section_number(
                            $course,
                            (string) ($operation['parent_source_ref_id'] ?? ''),
                            $sourcecourseid
                        );
                        $results[] = $this->apply_question_pool(
                            $course,
                            $operation,
                            $sourcecourseid,
                            $sourceversion,
                            $sectionnumber
                        );
                        continue;
                    }
                    if ($kind === 'test') {
                        $sectionnumber = $this->resolve_parent_section_number(
                            $course,
                            (string) ($operation['parent_source_ref_id'] ?? ''),
                            $sourcecourseid
                        );
                        $results[] = $this->apply_test(
                            $course,
                            $operation,
                            $sourcecourseid,
                            $sourceversion,
                            $sectionnumber
                        );
                        continue;
                    }
                    $results[] = $operation;
                }

                rebuild_course_cache($course->id, true);
                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
        } finally {
            if ($originaluser instanceof \stdClass) {
                \core\session\manager::set_user($originaluser);
            }
        }

        $plan['mode'] = 'apply';
        $plan['writes_performed'] = true;
        $plan['operations'] = $results;
        $plan['phase6_package']['apply_implemented'] = true;
        $plan['phase6_package']['apply_ready'] = true;
        $plan['phase6_package']['applied'] = true;
        $plan['warnings'] = array_values(array_filter(
            $plan['warnings'],
            static fn(array $warning): bool => ($warning['code'] ?? '') !== 'PHASE6_APPLY_NOT_IMPLEMENTED'
        ));
        $plan['warnings'][] = [
            'code' => 'COURSE_REMAINS_HIDDEN',
            'message' => 'The migrated Moodle course remains hidden after Phase 6 validation.',
        ];
        return $plan;
    }

    /** Refuse apply unless every previous phase/package is synchronized. */
    private function assert_applyable(array $plan): void {
        if (empty($plan['phase6_package']['ready'])
                || empty($plan['phase6_prerequisites']['ready'])
                || !empty($plan['phase6_package']['blocked_tests'])) {
            throw new \coding_exception('Phase 6 dry-run/package validation is not ready; apply is refused.');
        }
        foreach ($plan['operations'] as $operation) {
            $kind = (string) ($operation['kind'] ?? '');
            $action = (string) ($operation['action'] ?? '');
            if (in_array($kind, ['question_pool', 'test'], true)) {
                if (!in_array($action, ['CREATE', 'UPDATE'], true)) {
                    throw new \coding_exception("Cannot apply Phase 6 {$kind}: planned action is {$action}.");
                }
                continue;
            }
            if (in_array($kind, ['course', 'section', 'subsection', 'file', 'url', 'html_module', 'scorm', 'learning_module'], true)
                    && $action !== 'UPDATE') {
                throw new \coding_exception(
                    "Phase 6 requires earlier object {$kind} to be synchronized; planned action is {$action}."
                );
            }
        }
    }

    /** Create/update the empty shared qbank representing the ILIAS pool container. */
    private function apply_question_pool(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion,
        int $sectionnumber
    ): array {
        $requested = (string) $operation['action'];
        if ((int) ($operation['exported_question_file_count'] ?? 0) !== 0) {
            throw new \coding_exception(
                'Phase 6 apply currently expects the validated POC question pool to be container-only.'
            );
        }

        $description = (string) ($operation['description'] ?? '');
        $moduledata = (object) [
            'modulename' => 'qbank',
            'course' => (int) $course->id,
            'section' => $sectionnumber,
            'visible' => 1,
            'name' => (string) $operation['title'],
            'introeditor' => $this->build_intro_editor($description),
            'intro' => $description,
            'introformat' => FORMAT_HTML,
        ];

        if ($requested === 'CREATE') {
            $created = create_module($moduledata);
            $cmid = (int) $created->coursemodule;
            $instanceid = (int) $created->instance;
            $performed = 'CREATED';
        } else {
            $cmid = (int) $operation['target_id'];
            $cm = get_coursemodule_from_id('qbank', $cmid, $course->id, false, MUST_EXIST);
            $this->assert_module_section($cm, $course, $sectionnumber, 'Question Bank');
            [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
            $moduleinfo->name = $moduledata->name;
            $moduleinfo->introeditor = $moduledata->introeditor;
            update_module($moduleinfo);
            $instanceid = (int) $cm->instance;
            $performed = 'UPDATED';
        }

        $context = \context_module::instance($cmid);
        $category = question_get_default_category($context->id, true);
        if (!$category || (int) $category->contextid !== (int) $context->id) {
            throw new \coding_exception('Unable to create/resolve the Moodle Question Bank default category.');
        }

        $this->save_mapping(
            $sourcecourseid,
            (string) $operation['source_ref_id'],
            (string) ($operation['source_obj_id'] ?? ''),
            'qbank',
            $cmid,
            $sourceversion
        );

        $result = $operation;
        $result['requested_action'] = $requested;
        $result['action'] = $performed;
        $result['target_id'] = $cmid;
        $result['instance_id'] = $instanceid;
        $result['moodle_section'] = $sectionnumber;
        $result['question_category_id'] = (int) $category->id;
        $result['question_count'] = 0;
        return $result;
    }

    /** Create the quiz/private bank/questions, or verify an identical replay. */
    private function apply_test(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion,
        int $sectionnumber
    ): array {
        global $DB;

        $questions = $this->read_json_relative((string) $operation['migration_questions_path']);
        $quizdocument = $this->read_json_relative((string) $operation['migration_quiz_path']);
        $builder = new phase6_moodle_xml_builder();
        $built = $builder->build($questions, (string) $operation['source_ref_id']);
        $descriptors = $built['questions'];
        $order = is_array($quizdocument['question_order'] ?? null) ? $quizdocument['question_order'] : [];
        if (count($descriptors) !== count($order)) {
            throw new \coding_exception('Phase 6 Moodle XML question count differs from quiz order count.');
        }

        $requested = (string) $operation['action'];
        $totalmax = (float) ($quizdocument['total_max_score'] ?? 0.0);
        $description = (string) ($operation['description'] ?? '');

        if ($requested === 'CREATE') {
            $moduledata = $this->quiz_module_data(
                $course,
                (string) $operation['title'],
                $description,
                $sectionnumber,
                $totalmax
            );
            $created = create_module($moduledata);
            $cmid = (int) $created->coursemodule;
            $instanceid = (int) $created->instance;
            $context = \context_module::instance($cmid);
            $category = question_get_default_category($context->id, true);
            if (!$category) {
                throw new \coding_exception('Unable to resolve the Quiz private question category.');
            }

            $questionids = $this->import_moodle_xml(
                (string) $built['xml'],
                $category,
                $context,
                $course,
                count($descriptors)
            );

            $questionbyident = [];
            foreach ($descriptors as $index => $descriptor) {
                $qid = (int) $questionids[$index];
                $this->verify_question_identity($qid, $descriptor);
                $questionbyident[(string) $descriptor['source_ident']] = [
                    'id' => $qid,
                    'descriptor' => $descriptor,
                ];
                $this->save_mapping(
                    $sourcecourseid,
                    (string) $descriptor['mapping_ref'],
                    (string) $operation['source_obj_id'],
                    'question',
                    $qid,
                    $sourceversion
                );
            }

            $quiz = $DB->get_record('quiz', ['id' => $instanceid, 'course' => $course->id], '*', MUST_EXIST);
            $added = 0;
            foreach ($order as $entry) {
                if (!is_array($entry)) {
                    throw new \coding_exception('Invalid Phase 6 quiz order entry during apply.');
                }
                $ident = (string) ($entry['source_ident'] ?? '');
                if (!isset($questionbyident[$ident])) {
                    throw new \coding_exception('Phase 6 quiz order references a question not imported: ' . $ident);
                }
                $qid = (int) $questionbyident[$ident]['id'];
                $maxmark = (float) ($entry['max_score'] ?? 0.0);
                if (quiz_add_quiz_question($qid, $quiz, 0, $maxmark) === false) {
                    throw new \coding_exception('Moodle refused to add an imported question to the Quiz: ' . $ident);
                }
                $added++;
            }
            if ($added !== count($order)) {
                throw new \coding_exception('Not all Phase 6 questions were added to the Moodle Quiz.');
            }

            // Moodle 5.0 does not refresh quiz.sumgrades inside quiz_add_quiz_question().
            // Core edit flows explicitly recompute it after structural slot changes.
            quiz_delete_previews($quiz);
            \mod_quiz\quiz_settings::create($instanceid)
                ->get_grade_calculator()
                ->recompute_quiz_sumgrades();

            $performed = 'CREATED';
            $contentimported = true;
        } else {
            $cmid = (int) $operation['target_id'];
            $cm = get_coursemodule_from_id('quiz', $cmid, $course->id, false, MUST_EXIST);
            $this->assert_module_section($cm, $course, $sectionnumber, 'Quiz');
            $instanceid = (int) $cm->instance;
            $context = \context_module::instance($cmid);
            $category = question_get_default_category($context->id, false);
            if (!$category) {
                throw new \coding_exception('Mapped Moodle Quiz no longer has a private question category.');
            }

            $quiz = $DB->get_record('quiz', ['id' => $instanceid, 'course' => $course->id], '*', MUST_EXIST);
            if (abs((float) $quiz->grade - $totalmax) > 0.000001) {
                throw new \coding_exception(
                    'Mapped Moodle Quiz maximum grade differs from the current ILIAS package; safe score-changing UPDATE is refused.'
                );
            }
            $this->verify_existing_quiz(
                $quiz,
                $context,
                $descriptors,
                $order,
                $sourcecourseid
            );

            $metadatachanged = (string) $quiz->name !== (string) $operation['title']
                || (string) $quiz->intro !== $description
                || (int) $quiz->introformat !== FORMAT_HTML;
            if ($metadatachanged) {
                [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
                $moduleinfo->name = (string) $operation['title'];
                $moduleinfo->introeditor = $this->build_intro_editor($description);
                // Moodle Quiz form uses quizpassword; quiz_process_options() maps it back to password.
                // Preserve the existing value so a metadata-only update never writes password=NULL.
                $moduleinfo->quizpassword = (string) $quiz->password;
                update_module($moduleinfo);
            }
            $performed = 'UPDATED';
            $contentimported = false;
        }

        $this->save_mapping(
            $sourcecourseid,
            (string) $operation['source_ref_id'],
            (string) ($operation['source_obj_id'] ?? ''),
            'quiz',
            $cmid,
            $sourceversion
        );

        $verification = $this->verify_quiz_structure(
            $instanceid,
            \context_module::instance($cmid),
            $descriptors,
            $order
        );

        $result = $operation;
        $result['requested_action'] = $requested;
        $result['action'] = $performed;
        $result['target_id'] = $cmid;
        $result['instance_id'] = $instanceid;
        $result['moodle_section'] = $sectionnumber;
        $result['question_category_id'] = (int) $category->id;
        $result['question_content_imported'] = $contentimported;
        $result['question_count'] = $verification['question_count'];
        $result['slot_count'] = $verification['slot_count'];
        $result['sumgrades'] = $verification['sumgrades'];
        $result['grade'] = $verification['grade'];
        $result['effective_qtypes'] = $verification['effective_qtypes'];
        $result['transforms'] = $verification['transforms'];
        return $result;
    }

    /** Complete Quiz form-compatible defaults for Moodle 5.0 create_module(). */
    private function quiz_module_data(
        \stdClass $course,
        string $name,
        string $description,
        int $sectionnumber,
        float $grade
    ): \stdClass {
        return (object) [
            'modulename' => 'quiz',
            'course' => (int) $course->id,
            'section' => $sectionnumber,
            'visible' => 1,
            'name' => $name,
            'introeditor' => $this->build_intro_editor($description),
            'intro' => $description,
            'introformat' => FORMAT_HTML,
            'timeopen' => 0,
            'timeclose' => 0,
            'timelimit' => 0,
            'overduehandling' => 'autosubmit',
            'graceperiod' => 86400,
            'preferredbehaviour' => 'deferredfeedback',
            'attempts' => 0,
            'attemptonlast' => 0,
            'grademethod' => QUIZ_GRADEHIGHEST,
            'decimalpoints' => 2,
            'questiondecimalpoints' => -1,
            'questionsperpage' => 1,
            'navmethod' => QUIZ_NAVMETHOD_FREE,
            'shuffleanswers' => 1,
            'grade' => $grade,
            'sumgrades' => 0,
            'quizpassword' => '',
            'subnet' => '',
            'browsersecurity' => '',
            'delay1' => 0,
            'delay2' => 0,
            'showuserpicture' => 0,
            'showblocks' => 0,
            'attemptduring' => 1,
            'correctnessduring' => 1,
            'maxmarksduring' => 1,
            'marksduring' => 1,
            'specificfeedbackduring' => 1,
            'generalfeedbackduring' => 1,
            'rightanswerduring' => 1,
            'overallfeedbackduring' => 0,
            'attemptimmediately' => 1,
            'correctnessimmediately' => 1,
            'maxmarksimmediately' => 1,
            'marksimmediately' => 1,
            'specificfeedbackimmediately' => 1,
            'generalfeedbackimmediately' => 1,
            'rightanswerimmediately' => 1,
            'overallfeedbackimmediately' => 1,
            'attemptopen' => 1,
            'correctnessopen' => 1,
            'maxmarksopen' => 1,
            'marksopen' => 1,
            'specificfeedbackopen' => 1,
            'generalfeedbackopen' => 1,
            'rightansweropen' => 1,
            'overallfeedbackopen' => 1,
            'attemptclosed' => 1,
            'correctnessclosed' => 1,
            'maxmarksclosed' => 1,
            'marksclosed' => 1,
            'specificfeedbackclosed' => 1,
            'generalfeedbackclosed' => 1,
            'rightanswerclosed' => 1,
            'overallfeedbackclosed' => 1,
        ];
    }

    /** Import generated XML through Moodle core qformat_xml and return top-level question ids. */
    private function import_moodle_xml(
        string $xml,
        \stdClass $category,
        \context_module $context,
        \stdClass $course,
        int $expectedcount
    ): array {
        $tempdir = make_temp_directory('local_iliasmigration/phase6');
        $filename = tempnam($tempdir, 'questions_');
        if ($filename === false || file_put_contents($filename, $xml) === false) {
            throw new \coding_exception('Unable to create temporary Moodle XML question import file.');
        }

        try {
            $format = new \qformat_xml();
            $format->setCategory($category);
            $format->setContexts([$context]);
            $format->setCourse($course);
            $format->setFilename($filename);
            $format->setRealfilename('ilias2moodle-phase6.xml');
            $format->setMatchgrades('error');
            $format->setCatfromfile(false);
            $format->setContextfromfile(false);
            $format->setStoponerror(true);
            $format->set_display_progress(false);
            if (!$format->importprocess()) {
                throw new \coding_exception('Moodle core XML question import failed.');
            }
            $ids = array_values(array_map('intval', $format->questionids));
            if (count($ids) !== $expectedcount || in_array(0, $ids, true)) {
                throw new \coding_exception(
                    'Moodle XML import returned an unexpected number of top-level question ids.'
                );
            }
            return $ids;
        } finally {
            @unlink($filename);
        }
    }

    /** Verify imported question qtype and deterministic idnumber. */
    private function verify_question_identity(int $questionid, array $descriptor): void {
        global $DB;
        $record = $DB->get_record_sql(
            'SELECT q.id, q.qtype, qbe.idnumber
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE q.id = ?',
            [$questionid],
            MUST_EXIST
        );
        if ((string) $record->qtype !== (string) $descriptor['effective_qtype']) {
            throw new \coding_exception(
                'Imported Moodle qtype differs from the Phase 6 score-preserving plan for ' . $descriptor['source_ident'] . '.'
            );
        }
        if ((string) $record->idnumber !== (string) $descriptor['idnumber']) {
            throw new \coding_exception(
                'Imported Moodle question idnumber fingerprint differs from the Phase 6 plan.'
            );
        }
    }

    /** Verify unchanged replay mappings and quiz slots before allowing UPDATE. */
    private function verify_existing_quiz(
        \stdClass $quiz,
        \context_module $context,
        array $descriptors,
        array $order,
        string $sourcecourseid
    ): void {
        $byident = [];
        foreach ($descriptors as $descriptor) {
            $mapping = $this->find_mapping(
                $sourcecourseid,
                (string) $descriptor['mapping_ref'],
                'question'
            );
            if (!$mapping || (int) $mapping->targetid <= 0) {
                throw new \coding_exception(
                    'Mapped Phase 6 Quiz is missing a persistent question mapping; safe UPDATE is refused.'
                );
            }
            $qid = (int) $mapping->targetid;
            $this->verify_question_identity($qid, $descriptor);
            $byident[(string) $descriptor['source_ident']] = $qid;
        }

        $slots = array_values(\mod_quiz\question\bank\qbank_helper::get_question_structure((int) $quiz->id, $context));
        if (count($slots) !== count($order)) {
            throw new \coding_exception('Mapped Phase 6 Quiz slot count differs from the current package.');
        }
        foreach ($order as $index => $entry) {
            $ident = (string) ($entry['source_ident'] ?? '');
            $expectedqid = $byident[$ident] ?? 0;
            $slot = $slots[$index];
            if ((int) $slot->questionid !== $expectedqid
                    || abs((float) $slot->maxmark - (float) ($entry['max_score'] ?? 0.0)) > 0.000001) {
                throw new \coding_exception(
                    'Mapped Phase 6 Quiz question order/marks differ from the current package; destructive UPDATE is refused.'
                );
            }
        }
    }

    /** Final core-API verification after CREATE/UPDATE. */
    private function verify_quiz_structure(
        int $quizid,
        \context_module $context,
        array $descriptors,
        array $order
    ): array {
        global $DB;
        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $slots = array_values(\mod_quiz\question\bank\qbank_helper::get_question_structure($quizid, $context));
        if (count($slots) !== count($order)) {
            throw new \coding_exception('Moodle Quiz verification found an unexpected slot count.');
        }

        $descriptorsbyident = [];
        foreach ($descriptors as $descriptor) {
            $descriptorsbyident[(string) $descriptor['source_ident']] = $descriptor;
        }
        $effective = [];
        $transforms = [];
        $sum = 0.0;
        foreach ($slots as $index => $slot) {
            $entry = $order[$index];
            $ident = (string) ($entry['source_ident'] ?? '');
            $descriptor = $descriptorsbyident[$ident] ?? null;
            if (!is_array($descriptor)) {
                throw new \coding_exception('Moodle Quiz verification cannot resolve a Phase 6 descriptor.');
            }
            if ((string) $slot->qtype !== (string) $descriptor['effective_qtype']) {
                throw new \coding_exception('Moodle Quiz verification found an unexpected effective qtype.');
            }
            $expectedmark = (float) ($entry['max_score'] ?? 0.0);
            if (abs((float) $slot->maxmark - $expectedmark) > 0.000001) {
                throw new \coding_exception('Moodle Quiz verification found an unexpected slot maximum mark.');
            }
            $sum += (float) $slot->maxmark;
            $effective[] = (string) $slot->qtype;
            $transforms[] = [
                'source_ident' => $ident,
                'neutral_type' => (string) $descriptor['neutral_type'],
                'effective_qtype' => (string) $descriptor['effective_qtype'],
                'transform' => (string) $descriptor['transform'],
                'question_id' => (int) $slot->questionid,
                'maxmark' => (float) $slot->maxmark,
            ];
        }
        if (abs($sum - (float) $quiz->sumgrades) > 0.000001) {
            throw new \coding_exception('Moodle Quiz sumgrades differs from the verified slot mark total.');
        }

        return [
            'question_count' => count($descriptors),
            'slot_count' => count($slots),
            'sumgrades' => (float) $quiz->sumgrades,
            'grade' => (float) $quiz->grade,
            'effective_qtypes' => array_count_values($effective),
            'transforms' => $transforms,
        ];
    }

    /** Moodle editor payload required by create/update module APIs. */
    private function build_intro_editor(string $text): array {
        return [
            'text' => $text,
            'format' => FORMAT_HTML,
            'itemid' => file_get_unused_draft_itemid(),
        ];
    }

    /** Read one already-validated package JSON file, rechecking containment. */
    private function read_json_relative(string $relative): array {
        $file = $this->resolve_relative_file($relative);
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \coding_exception('Unable to read a Phase 6 normalized JSON file during apply.');
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \coding_exception('Invalid Phase 6 JSON during apply: ' . $exception->getMessage());
        }
        if (!is_array($data)) {
            throw new \coding_exception('Phase 6 normalized JSON must contain an object.');
        }
        return $data;
    }

    /** Resolve Moodle section number for a parent section/subsection mapping. */
    private function resolve_parent_section_number(
        \stdClass $course,
        string $parentsourceref,
        string $sourcecourseid
    ): int {
        global $DB;
        if ($parentsourceref === '') {
            return 0;
        }
        $sectionmapping = $this->find_mapping($sourcecourseid, $parentsourceref, 'section');
        if ($sectionmapping) {
            $section = $DB->get_record(
                'course_sections',
                ['id' => (int) $sectionmapping->targetid, 'course' => (int) $course->id],
                'id,section',
                MUST_EXIST
            );
            return (int) $section->section;
        }
        $subsectionmapping = $this->find_mapping($sourcecourseid, $parentsourceref, 'subsection');
        if ($subsectionmapping) {
            $cm = get_coursemodule_from_id(
                'subsection',
                (int) $subsectionmapping->targetid,
                (int) $course->id,
                false,
                MUST_EXIST
            );
            $delegated = $DB->get_record(
                'course_sections',
                ['course' => (int) $course->id, 'component' => 'mod_subsection', 'itemid' => (int) $cm->instance],
                'id,section',
                MUST_EXIST
            );
            return (int) $delegated->section;
        }
        throw new \coding_exception("No Moodle section mapping exists for ILIAS parent ref_id {$parentsourceref}.");
    }

    /** Assert mapped activity is still in expected section. */
    private function assert_module_section(
        \stdClass $cm,
        \stdClass $course,
        int $expectedsectionnumber,
        string $label
    ): void {
        global $DB;
        $section = $DB->get_record(
            'course_sections',
            ['id' => (int) $cm->section, 'course' => (int) $course->id],
            'id,section',
            MUST_EXIST
        );
        if ((int) $section->section !== $expectedsectionnumber) {
            throw new \coding_exception("Moving an existing Phase 6 {$label} to another Moodle section is not supported.");
        }
    }

    /** Find source mapping with legacy empty-sourceinstance fallback. */
    private function find_mapping(string $sourcecourse, string $sourceref, string $targettype): \stdClass|false {
        global $DB;
        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => $targettype,
        ];
        $mapping = $DB->get_record('local_iliasmigration_map', $conditions);
        if ($mapping) {
            return $mapping;
        }
        $conditions['sourceinstance'] = '';
        return $DB->get_record('local_iliasmigration_map', $conditions);
    }

    /** Save/refresh one plugin-owned source mapping row. */
    private function save_mapping(
        string $sourcecourse,
        string $sourceref,
        string $sourceobj,
        string $targettype,
        int $targetid,
        string $sourceversion
    ): void {
        global $DB;
        if (strlen($sourceref) > 64) {
            throw new \coding_exception('Phase 6 source mapping ref exceeds the plugin schema limit.');
        }
        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => $targettype,
        ];
        $existing = $DB->get_record('local_iliasmigration_map', $conditions);
        if (!$existing && $this->sourceinstance !== '') {
            $legacyconditions = $conditions;
            $legacyconditions['sourceinstance'] = '';
            $existing = $DB->get_record('local_iliasmigration_map', $legacyconditions);
        }

        $now = time();
        $record = (object) ($conditions + [
            'sourceversion' => $sourceversion !== '' ? $sourceversion : null,
            'sourceobj' => $sourceobj !== '' ? $sourceobj : null,
            'targetid' => $targetid,
            'status' => 'READY',
            'timemodified' => $now,
        ]);
        if ($existing) {
            $record->id = (int) $existing->id;
            $record->timecreated = (int) $existing->timecreated;
            $DB->update_record('local_iliasmigration_map', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('local_iliasmigration_map', $record);
        }
    }

    /** Resolve a safe package-relative file. */
    private function resolve_relative_file(string $relative): string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:\//', $relative)) {
            throw new \coding_exception('Unsafe Phase 6 package path.');
        }
        $parts = explode('/', $relative);
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new \coding_exception('Unsafe Phase 6 package path traversal.');
        }
        $candidate = $this->packageroot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        $resolved = realpath($candidate);
        if ($resolved === false
                || !is_file($resolved)
                || is_link($candidate)
                || !str_starts_with($resolved, $this->packageroot . DIRECTORY_SEPARATOR)) {
            throw new \coding_exception('Phase 6 package path is missing or escapes the package root.');
        }
        return $resolved;
    }
}

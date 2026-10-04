<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Persistent state machine for the operator console.
 */
final class operator_run_manager {
    public const RUN_READY = 'READY';
    public const RUN_RUNNING = 'RUNNING';
    public const RUN_WAITING = 'WAITING_DECISION';
    public const RUN_COMPLETED = 'COMPLETED';
    public const RUN_COMPLETED_WITH_SKIPS = 'COMPLETED_WITH_SKIPS';

    public const STEP_PENDING = 'PENDING';
    public const STEP_RUNNING = 'RUNNING';
    public const STEP_SUCCESS = 'SUCCESS';
    public const STEP_FAILED = 'FAILED';
    public const STEP_SKIPPED = 'SKIPPED';

    private operator_pipeline $pipeline;

    public function __construct() {
        $this->pipeline = new operator_pipeline();
    }

    public function create_run(
        string $sourcepath,
        int $categoryid,
        string $categorypath,
        int $userid
    ): int {
        global $DB;

        $sourcepath = trim($sourcepath);
        $categorypath = trim($categorypath);
        $resolved = realpath($sourcepath);

        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new \coding_exception(
                'migration.json is not readable: ' . $sourcepath
            );
        }

        if (($categoryid > 0) === ($categorypath !== '')) {
            throw new \coding_exception(
                'Choose exactly one target: an existing Moodle category or a category path.'
            );
        }

        if ($categoryid > 0
                && !$DB->record_exists('course_categories', ['id' => $categoryid])) {
            throw new \coding_exception(
                'The selected Moodle category does not exist.'
            );
        }

        $inspection = $this->pipeline->inspect_source($resolved);
        $types = $inspection['types'];
        $now = time();

        $transaction = $DB->start_delegated_transaction();

        try {
            $runid = (int) $DB->insert_record(
                'local_iliasmigration_run',
                (object) [
                    'status' => self::RUN_READY,
                    'sourcepath' => $resolved,
                    'sourcehash' => hash_file('sha256', $resolved) ?: '',
                    'categoryid' => $categoryid > 0 ? $categoryid : null,
                    'categorypath' => $categorypath !== '' ? $categorypath : null,
                    'createdby' => $userid,
                    'currentstep' => null,
                    'summaryjson' => $this->encode([
                        'detected_types' => $types,
                        'phase7_extensions' =>
                            'assisted_outside_operator_console_beta1',
                        'order_reconciliation_v2' =>
                            'guarded_cli_post_step',
                    ]),
                    'timecreated' => $now,
                    'timestarted' => 0,
                    'timefinished' => 0,
                    'timemodified' => $now,
                ]
            );

            $position = 0;
            $firstpending = null;

            foreach (
                operator_pipeline::step_definitions()
                as $stepkey => $definition
            ) {
                $position++;
                $applicable = $this->pipeline->is_applicable(
                    $stepkey,
                    $types
                );
                $status = $applicable
                    ? self::STEP_PENDING
                    : self::STEP_SKIPPED;

                $stepid = (int) $DB->insert_record(
                    'local_iliasmigration_step',
                    (object) [
                        'runid' => $runid,
                        'stepkey' => $stepkey,
                        'position' => $position,
                        'label' => (string) $definition['label'],
                        'status' => $status,
                        'attempts' => 0,
                        'ignored' => 0,
                        'resultjson' => $applicable
                            ? null
                            : $this->encode([
                                'operator_status' =>
                                    'SKIPPED_NOT_APPLICABLE',
                                'reason' =>
                                    'No matching source object exists in migration.json.',
                            ]),
                        'errormessage' => null,
                        'timestarted' => 0,
                        'timefinished' => $applicable ? 0 : $now,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]
                );

                if ($applicable && $firstpending === null) {
                    $firstpending = $stepkey;
                }

                if (!$applicable) {
                    $this->log(
                        $runid,
                        $stepid,
                        'INFO',
                        'STEP_NOT_APPLICABLE',
                        (string) $definition['label']
                            . ' : aucun objet source correspondant.',
                        ['stepkey' => $stepkey],
                        $userid
                    );
                }
            }

            $DB->set_field(
                'local_iliasmigration_run',
                'currentstep',
                $firstpending,
                ['id' => $runid]
            );

            $this->log(
                $runid,
                null,
                'INFO',
                'RUN_CREATED',
                'Migration opérateur créée.',
                [
                    'sourcepath' => $resolved,
                    'sourcehash' => hash_file('sha256', $resolved) ?: '',
                    'detected_types' => $types,
                ],
                $userid
            );

            $transaction->allow_commit();
            return $runid;
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
    }

    /**
     * Execute exactly one pending step.
     */
    public function execute_next(int $runid, int $userid): \stdClass {
        global $DB;

        $run = $this->get_run($runid);

        if (in_array(
            $run->status,
            [self::RUN_COMPLETED, self::RUN_COMPLETED_WITH_SKIPS],
            true
        )) {
            return $run;
        }

        if ($run->status === self::RUN_WAITING) {
            return $run;
        }

        $step = $DB->get_record_sql(
            "SELECT *
               FROM {local_iliasmigration_step}
              WHERE runid = ?
                AND status = ?
           ORDER BY position ASC",
            [$runid, self::STEP_PENDING],
            IGNORE_MULTIPLE
        );

        if (!$step) {
            $this->finalize_run($runid, $userid);
            return $this->get_run($runid);
        }

        $now = time();
        $step->status = self::STEP_RUNNING;
        $step->attempts = (int) $step->attempts + 1;
        $step->timestarted = $now;
        $step->timemodified = $now;
        $DB->update_record('local_iliasmigration_step', $step);

        $run->status = self::RUN_RUNNING;
        $run->currentstep = $step->stepkey;
        if ((int) $run->timestarted === 0) {
            $run->timestarted = $now;
        }
        $run->timemodified = $now;
        $DB->update_record('local_iliasmigration_run', $run);

        $this->log(
            $runid,
            (int) $step->id,
            'INFO',
            'STEP_STARTED',
            'Début : ' . $step->label,
            ['attempt' => (int) $step->attempts],
            $userid
        );

        try {
            $result = $this->pipeline->execute_step(
                (string) $step->stepkey,
                (string) $run->sourcepath,
                (int) ($run->categoryid ?? 0),
                (string) ($run->categorypath ?? '')
            );

            $summary = $this->pipeline->summarize_result($result);

            if ($step->stepkey === 'structure'
                    && (int) ($run->categoryid ?? 0) <= 0) {
                $resolvedcategory =
                    $this->pipeline->extract_category_id($result);

                if ($resolvedcategory <= 0) {
                    throw new \coding_exception(
                        'Phase 2 succeeded but the target Moodle category could not be resolved.'
                    );
                }

                $DB->set_field(
                    'local_iliasmigration_run',
                    'categoryid',
                    $resolvedcategory,
                    ['id' => $runid]
                );
            }

            $step->status = self::STEP_SUCCESS;
            $step->resultjson = $this->encode($summary);
            $step->errormessage = null;
            $step->timefinished = time();
            $step->timemodified = $step->timefinished;
            $DB->update_record('local_iliasmigration_step', $step);

            $this->log(
                $runid,
                (int) $step->id,
                'INFO',
                'STEP_SUCCESS',
                'Étape terminée : ' . $step->label,
                $summary,
                $userid
            );

            $this->refresh_current_step($runid);
            $this->finalize_run($runid, $userid);

            return $this->get_run($runid);
        } catch (\Throwable $exception) {
            $step->status = self::STEP_FAILED;
            $step->errormessage = $exception->getMessage();
            $step->timefinished = time();
            $step->timemodified = $step->timefinished;
            $DB->update_record('local_iliasmigration_step', $step);

            $run = $this->get_run($runid);
            $run->status = self::RUN_WAITING;
            $run->currentstep = $step->stepkey;
            $run->timemodified = time();
            $DB->update_record('local_iliasmigration_run', $run);

            $this->log(
                $runid,
                (int) $step->id,
                'ERROR',
                'STEP_FAILED',
                $step->label . ' : ' . $exception->getMessage(),
                [
                    'exception' => get_class($exception),
                    'attempt' => (int) $step->attempts,
                ],
                $userid
            );

            return $this->get_run($runid);
        }
    }

    public function retry_failed(
        int $runid,
        int $stepid,
        int $userid
    ): void {
        global $DB;

        $step = $this->get_step($runid, $stepid);

        if ($step->status !== self::STEP_FAILED) {
            throw new \coding_exception(
                'Only a failed step can be retried.'
            );
        }

        $step->status = self::STEP_PENDING;
        $step->errormessage = null;
        $step->timestarted = 0;
        $step->timefinished = 0;
        $step->timemodified = time();
        $DB->update_record('local_iliasmigration_step', $step);

        $run = $this->get_run($runid);
        $run->status = self::RUN_RUNNING;
        $run->currentstep = $step->stepkey;
        $run->timemodified = time();
        $DB->update_record('local_iliasmigration_run', $run);

        $this->log(
            $runid,
            $stepid,
            'INFO',
            'STEP_RETRY_REQUESTED',
            'Nouvelle tentative demandée pour : ' . $step->label,
            [],
            $userid
        );
    }

    public function ignore_failed(
        int $runid,
        int $stepid,
        int $userid
    ): void {
        global $DB;

        $step = $this->get_step($runid, $stepid);

        if ($step->status !== self::STEP_FAILED) {
            throw new \coding_exception(
                'Only a failed step can be ignored.'
            );
        }

        $originalerror = (string) ($step->errormessage ?? '');

        $step->status = self::STEP_SKIPPED;
        $step->ignored = 1;
        $step->resultjson = $this->encode([
            'operator_status' => 'IGNORED_AFTER_FAILURE',
            'error' => $originalerror,
        ]);
        $step->timefinished = time();
        $step->timemodified = $step->timefinished;
        $DB->update_record('local_iliasmigration_step', $step);

        $this->log(
            $runid,
            $stepid,
            'WARNING',
            'STEP_IGNORED',
            'Étape ignorée par l’opérateur : ' . $step->label,
            ['error' => $originalerror],
            $userid
        );

        $run = $this->get_run($runid);
        $run->status = self::RUN_RUNNING;
        $run->timemodified = time();
        $DB->update_record('local_iliasmigration_run', $run);

        $this->refresh_current_step($runid);
        $this->finalize_run($runid, $userid);
    }

    public function get_run(int $runid): \stdClass {
        global $DB;

        return $DB->get_record(
            'local_iliasmigration_run',
            ['id' => $runid],
            '*',
            MUST_EXIST
        );
    }

    /**
     * @return array<int, \stdClass>
     */
    public function get_steps(int $runid): array {
        global $DB;

        return $DB->get_records(
            'local_iliasmigration_step',
            ['runid' => $runid],
            'position ASC'
        );
    }

    /**
     * @return array<int, \stdClass>
     */
    public function get_logs(int $runid, int $limit = 200): array {
        global $DB;

        return $DB->get_records(
            'local_iliasmigration_log',
            ['runid' => $runid],
            'id DESC',
            '*',
            0,
            max(1, $limit)
        );
    }

    /**
     * @return array<int, \stdClass>
     */
    public function recent_runs(int $limit = 20): array {
        global $DB;

        return $DB->get_records(
            'local_iliasmigration_run',
            [],
            'id DESC',
            '*',
            0,
            max(1, $limit)
        );
    }

    public function build_report(int $runid): array {
        $run = $this->get_run($runid);
        $steps = $this->get_steps($runid);
        $logs = array_reverse($this->get_logs($runid, 1000));

        $counts = [];
        foreach ($steps as $step) {
            $counts[$step->status] =
                ($counts[$step->status] ?? 0) + 1;
        }

        return [
            'schema_version' => '1.0',
            'generated_at' => time(),
            'run' => [
                'id' => (int) $run->id,
                'status' => (string) $run->status,
                'sourcepath' => (string) $run->sourcepath,
                'sourcehash' => (string) $run->sourcehash,
                'categoryid' => $run->categoryid !== null
                    ? (int) $run->categoryid
                    : null,
                'categorypath' =>
                    (string) ($run->categorypath ?? ''),
                'createdby' => (int) $run->createdby,
                'timecreated' => (int) $run->timecreated,
                'timestarted' => (int) $run->timestarted,
                'timefinished' => (int) $run->timefinished,
            ],
            'summary' => [
                'counts' => $counts,
                'has_ignored_failures' => array_reduce(
                    $steps,
                    static fn(
                        bool $carry,
                        \stdClass $step
                    ): bool => $carry || !empty($step->ignored),
                    false
                ),
                'phase7_extensions' =>
                    'not_automated_in_operator_console_beta1',
                'order_reconciliation_v2' =>
                    'guarded_cli_post_step',
            ],
            'steps' => array_values(array_map(
                function(\stdClass $step): array {
                    return [
                        'id' => (int) $step->id,
                        'stepkey' => (string) $step->stepkey,
                        'label' => (string) $step->label,
                        'status' => (string) $step->status,
                        'attempts' => (int) $step->attempts,
                        'ignored' => (bool) $step->ignored,
                        'error' =>
                            (string) ($step->errormessage ?? ''),
                        'result' => $this->decode(
                            (string) ($step->resultjson ?? '')
                        ),
                        'timestarted' => (int) $step->timestarted,
                        'timefinished' => (int) $step->timefinished,
                    ];
                },
                $steps
            )),
            'logs' => array_values(array_map(
                function(\stdClass $log): array {
                    return [
                        'id' => (int) $log->id,
                        'stepid' => $log->stepid !== null
                            ? (int) $log->stepid
                            : null,
                        'level' => (string) $log->level,
                        'event' => (string) $log->event,
                        'message' => (string) $log->message,
                        'context' => $this->decode(
                            (string) ($log->contextjson ?? '')
                        ),
                        'userid' => (int) $log->userid,
                        'timecreated' => (int) $log->timecreated,
                    ];
                },
                $logs
            )),
        ];
    }

    private function get_step(
        int $runid,
        int $stepid
    ): \stdClass {
        global $DB;

        return $DB->get_record(
            'local_iliasmigration_step',
            ['id' => $stepid, 'runid' => $runid],
            '*',
            MUST_EXIST
        );
    }

    private function refresh_current_step(int $runid): void {
        global $DB;

        $next = $DB->get_record_sql(
            "SELECT *
               FROM {local_iliasmigration_step}
              WHERE runid = ?
                AND status = ?
           ORDER BY position ASC",
            [$runid, self::STEP_PENDING],
            IGNORE_MULTIPLE
        );

        $DB->set_field(
            'local_iliasmigration_run',
            'currentstep',
            $next ? (string) $next->stepkey : null,
            ['id' => $runid]
        );
    }

    private function finalize_run(
        int $runid,
        int $userid
    ): void {
        global $DB;

        $steps = $this->get_steps($runid);

        foreach ($steps as $step) {
            if (in_array(
                $step->status,
                [
                    self::STEP_PENDING,
                    self::STEP_RUNNING,
                    self::STEP_FAILED,
                ],
                true
            )) {
                return;
            }
        }

        $hasignored = false;
        $counts = [];

        foreach ($steps as $step) {
            $counts[$step->status] =
                ($counts[$step->status] ?? 0) + 1;
            $hasignored =
                $hasignored || !empty($step->ignored);
        }

        $run = $this->get_run($runid);
        $run->status = $hasignored
            ? self::RUN_COMPLETED_WITH_SKIPS
            : self::RUN_COMPLETED;
        $run->currentstep = null;
        $run->timefinished = time();
        $run->timemodified = $run->timefinished;
        $run->summaryjson = $this->encode([
            'counts' => $counts,
            'has_ignored_failures' => $hasignored,
            'phase7_extensions' =>
                'assisted_outside_operator_console_beta1',
            'order_reconciliation_v2' =>
                'guarded_cli_post_step',
        ]);
        $DB->update_record('local_iliasmigration_run', $run);

        $this->log(
            $runid,
            null,
            $hasignored ? 'WARNING' : 'INFO',
            'RUN_COMPLETED',
            $hasignored
                ? 'Migration terminée avec au moins une étape ignorée.'
                : 'Migration terminée sans étape ignorée.',
            ['counts' => $counts],
            $userid
        );
    }

    private function log(
        int $runid,
        ?int $stepid,
        string $level,
        string $event,
        string $message,
        array $context,
        int $userid
    ): void {
        global $DB;

        $DB->insert_record(
            'local_iliasmigration_log',
            (object) [
                'runid' => $runid,
                'stepid' => $stepid,
                'level' => $level,
                'event' => $event,
                'message' => $message,
                'contextjson' => $context
                    ? $this->encode($context)
                    : null,
                'userid' => $userid,
                'timecreated' => time(),
            ]
        );
    }

    private function encode(array $value): string {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        );
    }

    private function decode(string $value): ?array {
        if (trim($value) === '') {
            return null;
        }

        try {
            $decoded = json_decode(
                $value,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }
}

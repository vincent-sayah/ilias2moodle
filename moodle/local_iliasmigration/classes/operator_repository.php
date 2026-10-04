<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Persistence gateway for operator jobs, steps and logs.
 */
final class operator_repository {
    private function json(mixed $value): string {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    public function create_job(
        int $userid,
        string $sourcefilename,
        string $categorypath,
        string $failurepolicy
    ): int {
        global $DB;

        if (!in_array($failurepolicy, ['pause', 'continue'], true)) {
            throw new \coding_exception('Invalid operator failure policy.');
        }

        $now = time();
        return (int) $DB->insert_record(
            'local_iliasmigration_job',
            (object) [
                'status' => 'NEW',
                'failurepolicy' => $failurepolicy,
                'sourcefilename' => $sourcefilename,
                'categorypath' => $categorypath,
                'createdby' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]
        );
    }

    public function attach_package(
        int $jobid,
        string $sourcefilename,
        string $sourcehash,
        string $packagepath,
        string $migrationjson
    ): void {
        $this->update_job($jobid, [
            'sourcefilename' => $sourcefilename,
            'sourcehash' => $sourcehash,
            'packagepath' => $packagepath,
            'migrationjson' => $migrationjson,
            'status' => 'QUEUED',
            'lasterror' => null,
        ]);
    }

    public function replace_steps(int $jobid, array $steps): void {
        global $DB;

        $DB->delete_records('local_iliasmigration_step', ['jobid' => $jobid]);
        $now = time();

        foreach ($steps as $step) {
            $DB->insert_record(
                'local_iliasmigration_step',
                (object) [
                    'jobid' => $jobid,
                    'sequence' => (int) ($step['sequence'] ?? 0),
                    'stepkey' => (string) ($step['stepkey'] ?? ''),
                    'label' => (string) ($step['label'] ?? ''),
                    'status' => 'PENDING',
                    'critical' => !empty($step['critical']) ? 1 : 0,
                    'skippable' => !empty($step['skippable']) ? 1 : 0,
                    'ignored' => 0,
                    'attempts' => 0,
                    'contextjson' => $this->json($step['context'] ?? []),
                    'timemodified' => $now,
                ]
            );
        }
    }

    public function get_job(int $jobid): \stdClass {
        global $DB;
        return $DB->get_record(
            'local_iliasmigration_job',
            ['id' => $jobid],
            '*',
            MUST_EXIST
        );
    }

    public function get_jobs(int $limit = 50): array {
        global $DB;
        return $DB->get_records(
            'local_iliasmigration_job',
            [],
            'id DESC',
            '*',
            0,
            $limit
        );
    }

    public function get_steps(int $jobid): array {
        global $DB;
        return array_values($DB->get_records(
            'local_iliasmigration_step',
            ['jobid' => $jobid],
            'sequence ASC, id ASC'
        ));
    }

    public function get_step(int $stepid): \stdClass {
        global $DB;
        return $DB->get_record(
            'local_iliasmigration_step',
            ['id' => $stepid],
            '*',
            MUST_EXIST
        );
    }

    public function get_logs(int $jobid): array {
        global $DB;
        return array_values($DB->get_records(
            'local_iliasmigration_log',
            ['jobid' => $jobid],
            'id ASC'
        ));
    }

    public function update_job(int $jobid, array $fields): void {
        global $DB;
        $record = (object) array_merge(
            ['id' => $jobid, 'timemodified' => time()],
            $fields
        );
        $DB->update_record('local_iliasmigration_job', $record);
    }

    public function start_step(int $stepid): void {
        $step = $this->get_step($stepid);
        $this->update_step($stepid, [
            'status' => 'RUNNING',
            'attempts' => (int) $step->attempts + 1,
            'timestarted' => time(),
            'timefinished' => null,
            'errormessage' => null,
        ]);
    }

    public function complete_step(int $stepid, array $result): void {
        $this->update_step($stepid, [
            'status' => 'SUCCESS',
            'resultjson' => $this->json($result),
            'errormessage' => null,
            'timefinished' => time(),
        ]);
    }

    public function fail_step(
        int $stepid,
        \Throwable $exception,
        string $status = 'FAILED'
    ): void {
        $this->update_step($stepid, [
            'status' => $status,
            'errormessage' => get_class($exception) . ': ' . $exception->getMessage(),
            'timefinished' => time(),
        ]);
    }

    public function ignore_step(int $stepid): void {
        $step = $this->get_step($stepid);
        if (empty($step->skippable)) {
            throw new \coding_exception('This migration step is critical and cannot be ignored.');
        }
        if ((string) $step->status !== 'FAILED') {
            throw new \coding_exception('Only a failed step can be manually ignored.');
        }
        $this->update_step($stepid, [
            'status' => 'IGNORED',
            'ignored' => 1,
            'timefinished' => time(),
        ]);
    }

    public function retry_from_step(int $jobid, int $stepid): void {
        $step = $this->get_step($stepid);
        if ((int) $step->jobid !== $jobid) {
            throw new \coding_exception('Step does not belong to this migration job.');
        }

        foreach ($this->get_steps($jobid) as $candidate) {
            if ((int) $candidate->sequence < (int) $step->sequence) {
                continue;
            }
            if ((string) $candidate->status === 'IGNORED') {
                continue;
            }
            $this->update_step((int) $candidate->id, [
                'status' => 'PENDING',
                'ignored' => 0,
                'resultjson' => null,
                'errormessage' => null,
                'timestarted' => null,
                'timefinished' => null,
            ]);
        }
    }

    private function update_step(int $stepid, array $fields): void {
        global $DB;
        $record = (object) array_merge(
            ['id' => $stepid, 'timemodified' => time()],
            $fields
        );
        $DB->update_record('local_iliasmigration_step', $record);
    }

    public function log(
        int $jobid,
        ?int $stepid,
        string $level,
        string $eventcode,
        string $message,
        array $context = []
    ): void {
        global $DB;

        $DB->insert_record(
            'local_iliasmigration_log',
            (object) [
                'jobid' => $jobid,
                'stepid' => $stepid,
                'level' => strtoupper(substr($level, 0, 10)),
                'eventcode' => substr($eventcode, 0, 64),
                'message' => $message,
                'contextjson' => $context ? $this->json($context) : null,
                'timecreated' => time(),
            ]
        );
    }

    public function build_report(int $jobid): array {
        $job = $this->get_job($jobid);
        $steps = $this->get_steps($jobid);
        $logs = $this->get_logs($jobid);

        $counts = [];
        foreach ($steps as $step) {
            $status = (string) $step->status;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return [
            'schema_version' => 'operator-report-1',
            'job' => [
                'id' => (int) $job->id,
                'status' => (string) $job->status,
                'failure_policy' => (string) $job->failurepolicy,
                'source_filename' => (string) $job->sourcefilename,
                'source_sha256' => (string) $job->sourcehash,
                'category_path' => (string) $job->categorypath,
                'category_id' => $job->categoryid !== null ? (int) $job->categoryid : null,
                'course_id' => $job->courseid !== null ? (int) $job->courseid : null,
                'created_by' => (int) $job->createdby,
                'time_created' => (int) $job->timecreated,
                'time_started' => $job->timestarted !== null ? (int) $job->timestarted : null,
                'time_finished' => $job->timefinished !== null ? (int) $job->timefinished : null,
                'last_error' => $job->lasterror,
            ],
            'step_counts' => $counts,
            'steps' => array_map(
                static function(\stdClass $step): array {
                    return [
                        'id' => (int) $step->id,
                        'sequence' => (int) $step->sequence,
                        'key' => (string) $step->stepkey,
                        'label' => (string) $step->label,
                        'status' => (string) $step->status,
                        'critical' => (bool) $step->critical,
                        'skippable' => (bool) $step->skippable,
                        'ignored' => (bool) $step->ignored,
                        'attempts' => (int) $step->attempts,
                        'context' => $step->contextjson
                            ? json_decode($step->contextjson, true)
                            : null,
                        'result' => $step->resultjson
                            ? json_decode($step->resultjson, true)
                            : null,
                        'error' => $step->errormessage,
                        'time_started' => $step->timestarted !== null
                            ? (int) $step->timestarted
                            : null,
                        'time_finished' => $step->timefinished !== null
                            ? (int) $step->timefinished
                            : null,
                    ];
                },
                $steps
            ),
            'logs' => array_map(
                static function(\stdClass $log): array {
                    return [
                        'id' => (int) $log->id,
                        'step_id' => $log->stepid !== null ? (int) $log->stepid : null,
                        'level' => (string) $log->level,
                        'event_code' => (string) $log->eventcode,
                        'message' => (string) $log->message,
                        'context' => $log->contextjson
                            ? json_decode($log->contextjson, true)
                            : null,
                        'time_created' => (int) $log->timecreated,
                    ];
                },
                $logs
            ),
        ];
    }
}

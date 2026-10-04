<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Resumable background runner for operator migrations.
 */
final class operator_job_runner {
    public static function queue(int $jobid): void {
        $task = new \local_iliasmigration\task\run_operator_job();
        $task->set_custom_data(['jobid' => $jobid]);
        \core\task\manager::queue_adhoc_task($task);
    }

    public function run(int $jobid): void {
        $factory = \core\lock\lock_config::get_lock_factory('local_iliasmigration');
        $lock = $factory->get_lock('operator_job_' . $jobid, 0);
        if (!$lock) {
            return;
        }

        $repo = new operator_repository();

        try {
            $job = $repo->get_job($jobid);
            if (in_array((string) $job->status, ['COMPLETED', 'COMPLETED_WARNINGS'], true)) {
                return;
            }

            $repo->update_job($jobid, [
                'status' => 'RUNNING',
                'timestarted' => $job->timestarted ?: time(),
                'timefinished' => null,
                'lasterror' => null,
            ]);
            $repo->log($jobid, null, 'INFO', 'JOB_STARTED', 'Migration job started.');

            $migrationjson = (string) $job->migrationjson;
            if ($migrationjson === '' || !is_readable($migrationjson)) {
                throw new \coding_exception('migration.json is missing or no longer readable.');
            }

            $document = (new migration_reader())->read($migrationjson);
            $hadwarnings = false;

            foreach ($repo->get_steps($jobid) as $step) {
                if ((string) $step->status !== 'PENDING') {
                    if (in_array((string) $step->status, ['IGNORED', 'SKIPPED_ERROR'], true)) {
                        $hadwarnings = true;
                    }
                    continue;
                }

                $repo->start_step((int) $step->id);
                $repo->log(
                    $jobid,
                    (int) $step->id,
                    'INFO',
                    'STEP_STARTED',
                    'Starting migration step: ' . $step->label
                );

                try {
                    $job = $repo->get_job($jobid);
                    $result = $this->execute_step($step, $job, $document, $repo);
                    $repo->complete_step((int) $step->id, $result);
                    $repo->log(
                        $jobid,
                        (int) $step->id,
                        'INFO',
                        'STEP_SUCCESS',
                        'Migration step completed: ' . $step->label
                    );
                } catch (\Throwable $exception) {
                    $context = [
                        'exception' => get_class($exception),
                        'message' => $exception->getMessage(),
                    ];
                    $repo->log(
                        $jobid,
                        (int) $step->id,
                        'ERROR',
                        'STEP_FAILED',
                        'Migration step failed: ' . $step->label,
                        $context
                    );

                    if (!empty($step->critical)) {
                        $repo->fail_step((int) $step->id, $exception);
                        $repo->update_job($jobid, [
                            'status' => 'FAILED',
                            'lasterror' => $exception->getMessage(),
                            'timefinished' => time(),
                            'summaryjson' => $this->encode($repo->build_report($jobid)),
                        ]);
                        return;
                    }

                    $job = $repo->get_job($jobid);
                    if ((string) $job->failurepolicy === 'continue') {
                        $repo->fail_step((int) $step->id, $exception, 'SKIPPED_ERROR');
                        $repo->log(
                            $jobid,
                            (int) $step->id,
                            'WARN',
                            'STEP_AUTO_SKIPPED',
                            'Step skipped after error because the job is configured to continue.'
                        );
                        $hadwarnings = true;
                        continue;
                    }

                    $repo->fail_step((int) $step->id, $exception);
                    $repo->update_job($jobid, [
                        'status' => 'PAUSED',
                        'lasterror' => $exception->getMessage(),
                        'summaryjson' => $this->encode($repo->build_report($jobid)),
                    ]);
                    return;
                }
            }

            $status = $hadwarnings ? 'COMPLETED_WARNINGS' : 'COMPLETED';
            $repo->update_job($jobid, [
                'status' => $status,
                'timefinished' => time(),
                'summaryjson' => $this->encode($repo->build_report($jobid)),
                'lasterror' => null,
            ]);
            $repo->log(
                $jobid,
                null,
                $hadwarnings ? 'WARN' : 'INFO',
                'JOB_FINISHED',
                $hadwarnings
                    ? 'Migration job completed with ignored/skipped errors.'
                    : 'Migration job completed successfully.'
            );
            $repo->update_job($jobid, [
                'summaryjson' => $this->encode($repo->build_report($jobid)),
            ]);
        } catch (\Throwable $exception) {
            $repo->log(
                $jobid,
                null,
                'ERROR',
                'JOB_FATAL',
                $exception->getMessage(),
                ['exception' => get_class($exception)]
            );
            $repo->update_job($jobid, [
                'status' => 'FAILED',
                'lasterror' => get_class($exception) . ': ' . $exception->getMessage(),
                'timefinished' => time(),
                'summaryjson' => $this->encode($repo->build_report($jobid)),
            ]);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function execute_step(
        \stdClass $step,
        \stdClass $job,
        array $document,
        operator_repository $repo
    ): array {
        $source = (string) $job->migrationjson;
        $key = (string) $step->stepkey;

        if ($key === 'preflight') {
            return [
                'source' => $document['source'] ?? [],
                'course' => [
                    'source_id' => $document['course']['source_id'] ?? null,
                    'title' => $document['course']['title'] ?? '',
                ],
                'inventory' => operator_pipeline::inventory($document),
                'writes_performed' => false,
            ];
        }

        if ($key === 'structure') {
            $result = (new importer())->import(
                $source,
                0,
                false,
                2,
                (string) $job->categorypath
            );
            $resolution = (new category_path_resolver())->plan((string) $job->categorypath);
            $categoryid = (int) ($resolution['target_id'] ?? 0);
            if ($categoryid <= 0) {
                throw new \coding_exception('Unable to resolve the target category after Phase 2.');
            }
            $courseid = (int) ($result['course']['target_id'] ?? 0);
            $repo->update_job((int) $job->id, [
                'categoryid' => $categoryid,
                'courseid' => $courseid > 0 ? $courseid : null,
            ]);
            return $result;
        }

        $categoryid = (int) $job->categoryid;
        if ($categoryid <= 0) {
            $fresh = $repo->get_job((int) $job->id);
            $categoryid = (int) $fresh->categoryid;
        }
        if ($categoryid <= 0) {
            throw new \coding_exception('Target Moodle category is unresolved.');
        }

        return match ($key) {
            'simple_resources' => (new importer())->import($source, $categoryid, false, 3),
            'scorm' => (new importer())->import($source, $categoryid, false, 4),
            'learning_modules' => (new importer())->import($source, $categoryid, false, 5),
            'tests_questions' => (new importer())->import($source, $categoryid, false, 6),
            'content_pages' => (new importer())->import($source, $categoryid, false, 65),
            'glossaries' => (new phase65_glossary_executor($source))->execute($document, $categoryid),
            'wikis' => (new phase65_wiki_executor($source))->execute($document, $categoryid),
            'exercises' => (new phase65_exercise_executor($source))->execute($document, $categoryid),
            'forums' => (new phase65_forum_executor($source))->execute($document, $categoryid),
            'mediacasts' => (new phase65_mediacast_executor($source))->execute($document, $categoryid),
            'blogs' => (new phase65_blog_executor($source))->execute($document, $categoryid),
            'media_pools' => (new phase65_media_pool_executor($source))->execute($document, $categoryid),
            'item_groups' => (new phase65_item_group_executor($source))->execute($document, $categoryid),
            'order_reconciliation' => (new order_reconciler($source))->execute($document),
            'final_report' => [
                'report_available' => true,
                'writes_performed' => false,
                'note' => 'The final report is assembled from persistent job/step/log records.',
            ],
            default => throw new \coding_exception('Unknown operator step: ' . $key),
        };
    }

    private function encode(array $value): string {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}

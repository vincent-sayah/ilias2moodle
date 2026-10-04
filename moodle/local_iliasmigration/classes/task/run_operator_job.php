<?php

namespace local_iliasmigration\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Runs one queued operator migration job.
 */
final class run_operator_job extends \core\task\adhoc_task {
    public function get_name(): string {
        return get_string('taskrunoperatorjob', 'local_iliasmigration');
    }

    public function execute(): void {
        $data = $this->get_custom_data();
        $jobid = (int) ($data->jobid ?? 0);
        if ($jobid <= 0) {
            throw new \coding_exception('Operator task has no valid job id.');
        }

        (new \local_iliasmigration\operator_job_runner())->run($jobid);
    }
}

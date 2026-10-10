<?php

namespace local_iliasmigration\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task that automatically applies source recovery bundles.
 */
final class recovery_reprepare_task extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string(
            'task_recovery_reprepare',
            'local_iliasmigration'
        );
    }

    public function execute(): void {
        $worker = new \local_iliasmigration\operator_recovery_reprepare_worker();

        $result = $worker->run(5);

        mtrace(
            json_encode(
                $result,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
            )
        );

        if (!empty($result['failed_count'])) {
            throw new \coding_exception(
                'Automatic recovery reprepare task reported '
                . (int) $result['failed_count']
                . ' failed package(s).'
            );
        }
    }
}

<?php

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/iliasmigration:operate', $context);

$runid = required_param('id', PARAM_INT);
$provided = required_param('sesskey', PARAM_ALPHANUM);

if (!confirm_sesskey($provided)) {
    throw new moodle_exception('invalidsesskey');
}

$manager = new \local_iliasmigration\operator_run_manager();
$run = $manager->execute_next($runid, (int) $USER->id);

if (in_array(
    $run->status,
    [
        \local_iliasmigration\operator_run_manager::RUN_COMPLETED,
        \local_iliasmigration\operator_run_manager::RUN_COMPLETED_WITH_SKIPS,
        \local_iliasmigration\operator_run_manager::RUN_WAITING,
    ],
    true
)) {
    redirect(new moodle_url('/local/iliasmigration/run.php', ['id' => $runid]));
}

redirect(new moodle_url(
    '/local/iliasmigration/execute.php',
    ['id' => $runid, 'sesskey' => sesskey()]
));

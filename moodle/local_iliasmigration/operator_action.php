<?php

require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/iliasmigration:manage', context_system::instance());
require_sesskey();

$jobid = required_param('jobid', PARAM_INT);
$stepid = required_param('stepid', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

$repo = new \local_iliasmigration\operator_repository();
$repo->get_job($jobid);
$step = $repo->get_step($stepid);

if ((int) $step->jobid !== $jobid) {
    throw new moodle_exception('invalidparameter');
}

if ($action === 'ignore') {
    $repo->ignore_step($stepid);
    $repo->update_job($jobid, [
        'status' => 'QUEUED',
        'lasterror' => null,
        'timefinished' => null,
    ]);
    $repo->log(
        $jobid,
        $stepid,
        'WARN',
        'STEP_MANUALLY_IGNORED',
        'Operator ignored the failed step and requested continuation.'
    );
} else if ($action === 'retry') {
    $repo->retry_from_step($jobid, $stepid);
    $repo->update_job($jobid, [
        'status' => 'QUEUED',
        'lasterror' => null,
        'timefinished' => null,
    ]);
    $repo->log(
        $jobid,
        $stepid,
        'INFO',
        'STEP_RETRY_REQUESTED',
        'Operator requested retry from this step.'
    );
} else {
    throw new moodle_exception('invalidparameter');
}

\local_iliasmigration\operator_job_runner::queue($jobid);

redirect(new moodle_url('/local/iliasmigration/operator.php', ['jobid' => $jobid]));

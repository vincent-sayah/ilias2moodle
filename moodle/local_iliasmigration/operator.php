<?php

require(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/iliasmigration:manage', $context);

$jobid = optional_param('jobid', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/iliasmigration/operator.php', $jobid ? ['jobid' => $jobid] : []));
$PAGE->set_title(get_string('operatorconsole', 'local_iliasmigration'));
$PAGE->set_heading(get_string('operatorconsole', 'local_iliasmigration'));

$repo = new \local_iliasmigration\operator_repository();

if (data_submitted() && optional_param('createjob', 0, PARAM_BOOL)) {
    require_sesskey();

    $categorypath = trim(required_param('categorypath', PARAM_TEXT));
    $failurepolicy = optional_param('failurepolicy', 'pause', PARAM_ALPHA);
    $serverpath = trim(optional_param('migrationjsonpath', '', PARAM_RAW_TRIMMED));
    $confirmed = optional_param('confirmapply', 0, PARAM_BOOL);

    if (!$confirmed) {
        throw new moodle_exception('operatorconfirmrequired', 'local_iliasmigration');
    }
    if ($categorypath === '') {
        throw new moodle_exception('operatorcategoryrequired', 'local_iliasmigration');
    }
    if (!in_array($failurepolicy, ['pause', 'continue'], true)) {
        throw new moodle_exception('invalidparameter');
    }

    $upload = $_FILES['packagezip'] ?? null;
    $hasupload = is_array($upload)
        && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasupload && (int) $upload['error'] !== UPLOAD_ERR_OK) {
        throw new moodle_exception('operatoruploadfailed', 'local_iliasmigration');
    }
    if ($hasupload === ($serverpath !== '')) {
        throw new moodle_exception('operatorsourcechoice', 'local_iliasmigration');
    }

    $sourcefilename = $hasupload
        ? clean_param((string) $upload['name'], PARAM_FILE)
        : basename($serverpath);

    $newjobid = $repo->create_job(
        (int) $USER->id,
        $sourcefilename,
        $categorypath,
        $failurepolicy
    );

    try {
        if ($hasupload) {
            $package = \local_iliasmigration\operator_package::store_uploaded_zip(
                $newjobid,
                (string) $upload['tmp_name'],
                (string) $upload['name']
            );
        } else {
            $package = \local_iliasmigration\operator_package::use_existing_migration_json(
                $newjobid,
                $serverpath
            );
        }

        $repo->attach_package(
            $newjobid,
            (string) $package['sourcefilename'],
            (string) $package['sourcehash'],
            (string) $package['packagepath'],
            (string) $package['migrationjson']
        );

        $document = (new \local_iliasmigration\migration_reader())->read(
            (string) $package['migrationjson']
        );
        $repo->replace_steps(
            $newjobid,
            \local_iliasmigration\operator_pipeline::steps($document)
        );
        $repo->log(
            $newjobid,
            null,
            'INFO',
            'JOB_CREATED',
            'Operator migration job created.',
            [
                'storage' => $package['storage'] ?? '',
                'category_path' => $categorypath,
                'failure_policy' => $failurepolicy,
            ]
        );

        \local_iliasmigration\operator_job_runner::queue($newjobid);
        redirect(
            new moodle_url('/local/iliasmigration/operator.php', ['jobid' => $newjobid]),
            get_string('operatorjobqueued', 'local_iliasmigration')
        );
    } catch (Throwable $exception) {
        $repo->update_job($newjobid, [
            'status' => 'FAILED',
            'lasterror' => get_class($exception) . ': ' . $exception->getMessage(),
            'timefinished' => time(),
        ]);
        $repo->log(
            $newjobid,
            null,
            'ERROR',
            'JOB_CREATION_FAILED',
            $exception->getMessage(),
            ['exception' => get_class($exception)]
        );
        throw $exception;
    }
}

echo $OUTPUT->header();

if ($jobid > 0) {
    $job = $repo->get_job($jobid);
    $steps = $repo->get_steps($jobid);
    $logs = $repo->get_logs($jobid);

    echo $OUTPUT->heading(get_string('operatorjob', 'local_iliasmigration') . ' #' . $jobid, 2);
    echo html_writer::div(
        get_string('operatorjobstatus', 'local_iliasmigration') . ': '
        . html_writer::tag('strong', s((string) $job->status))
    );
    echo html_writer::div(
        get_string('operatorcategorypath', 'local_iliasmigration') . ': '
        . s((string) $job->categorypath)
    );
    if (!empty($job->courseid)) {
        echo html_writer::div('Moodle course id: ' . (int) $job->courseid);
    }

    $table = new html_table();
    $table->head = ['#', get_string('operatorstep', 'local_iliasmigration'), 'Status', 'Attempts', 'Erreur', 'Actions'];
    foreach ($steps as $step) {
        $actions = '';
        if ((string) $step->status === 'FAILED') {
            $base = [
                'jobid' => $jobid,
                'stepid' => (int) $step->id,
                'sesskey' => sesskey(),
            ];
            $retryurl = new moodle_url('/local/iliasmigration/operator_action.php', $base + ['action' => 'retry']);
            $actions .= html_writer::link($retryurl, get_string('operatorretry', 'local_iliasmigration'));
            if (!empty($step->skippable)) {
                $ignoreurl = new moodle_url('/local/iliasmigration/operator_action.php', $base + ['action' => 'ignore']);
                $actions .= ' | ' . html_writer::link(
                    $ignoreurl,
                    get_string('operatorignorecontinue', 'local_iliasmigration')
                );
            }
        }

        $table->data[] = [
            (int) $step->sequence,
            s((string) $step->label),
            s((string) $step->status),
            (int) $step->attempts,
            s((string) ($step->errormessage ?? '')),
            $actions,
        ];
    }
    echo html_writer::table($table);

    $reporturl = new moodle_url('/local/iliasmigration/operator_report.php', ['jobid' => $jobid]);
    echo html_writer::div(html_writer::link($reporturl, get_string('operatorreport', 'local_iliasmigration')));

    echo $OUTPUT->heading(get_string('operatorlogs', 'local_iliasmigration'), 3);
    $logtable = new html_table();
    $logtable->head = ['Date', 'Niveau', 'Code', 'Message'];
    foreach ($logs as $log) {
        $logtable->data[] = [
            userdate((int) $log->timecreated),
            s((string) $log->level),
            s((string) $log->eventcode),
            s((string) $log->message),
        ];
    }
    echo html_writer::table($logtable);

    echo html_writer::div(
        html_writer::link(
            new moodle_url('/local/iliasmigration/operator.php'),
            get_string('operatorback', 'local_iliasmigration')
        )
    );
} else {
    echo $OUTPUT->heading(get_string('operatornewjob', 'local_iliasmigration'), 2);

    echo '<form method="post" enctype="multipart/form-data" action="">';
    echo '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
    echo '<input type="hidden" name="createjob" value="1">';
    echo '<div class="mb-3"><label>' . s(get_string('operatorpackagezip', 'local_iliasmigration'))
        . '<br><input type="file" name="packagezip" accept=".zip"></label></div>';
    echo '<div class="mb-3"><label>' . s(get_string('operatormigrationjsonpath', 'local_iliasmigration'))
        . '<br><input class="form-control" type="text" size="100" name="migrationjsonpath"></label></div>';
    echo '<div class="mb-3"><label>' . s(get_string('operatorcategorypath', 'local_iliasmigration'))
        . '<br><input class="form-control" type="text" size="80" name="categorypath" required '
        . 'placeholder="Marine &gt; Formation &gt; Migration ILIAS"></label></div>';
    echo '<div class="mb-3"><label>' . s(get_string('operatorfailurepolicy', 'local_iliasmigration'))
        . '<br><select class="form-select" name="failurepolicy">'
        . '<option value="pause">' . s(get_string('operatorpolicy_pause', 'local_iliasmigration')) . '</option>'
        . '<option value="continue">' . s(get_string('operatorpolicy_continue', 'local_iliasmigration')) . '</option>'
        . '</select></label></div>';
    echo '<div class="mb-3"><label><input type="checkbox" name="confirmapply" value="1" required> '
        . s(get_string('operatorconfirmapply', 'local_iliasmigration')) . '</label></div>';
    echo '<button class="btn btn-primary" type="submit">'
        . s(get_string('operatorstart', 'local_iliasmigration')) . '</button>';
    echo '</form>';

    echo $OUTPUT->heading(get_string('operatorjobs', 'local_iliasmigration'), 2);
    $jobs = $repo->get_jobs();
    $table = new html_table();
    $table->head = ['ID', 'Date', 'Source', 'Catégorie', 'Status'];
    foreach ($jobs as $job) {
        $url = new moodle_url('/local/iliasmigration/operator.php', ['jobid' => (int) $job->id]);
        $table->data[] = [
            html_writer::link($url, '#' . (int) $job->id),
            userdate((int) $job->timecreated),
            s((string) $job->sourcefilename),
            s((string) $job->categorypath),
            s((string) $job->status),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();

<?php

require(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/iliasmigration:manage', $context);

$jobid = required_param('jobid', PARAM_INT);
$download = optional_param('download', 0, PARAM_BOOL);

$repo = new \local_iliasmigration\operator_repository();
$report = $repo->build_report($jobid);

if ($download) {
    $json = json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="ilias2moodle-job-' . $jobid . '-report.json"');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/iliasmigration/operator_report.php', ['jobid' => $jobid]));
$PAGE->set_title(get_string('operatorreport', 'local_iliasmigration'));
$PAGE->set_heading(get_string('operatorreport', 'local_iliasmigration') . ' #' . $jobid);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('operatorreport', 'local_iliasmigration') . ' #' . $jobid, 2);
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/iliasmigration/operator_report.php', ['jobid' => $jobid, 'download' => 1]),
        get_string('operatordownloadjson', 'local_iliasmigration')
    )
);
echo html_writer::tag(
    'pre',
    s(json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ))
);
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/iliasmigration/operator.php', ['jobid' => $jobid]),
        get_string('operatorback', 'local_iliasmigration')
    )
);
echo $OUTPUT->footer();

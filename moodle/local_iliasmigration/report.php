<?php

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/iliasmigration:viewreports', $context);

$runid = required_param('id', PARAM_INT);
$format = optional_param('format', 'html', PARAM_ALPHA);

$manager = new \local_iliasmigration\operator_run_manager();
$report = $manager->build_report($runid);

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="ilias2moodle-run-'
        . $runid
        . '-report.json"'
    );

    echo json_encode(
        $report,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
    );
    exit;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url(
    '/local/iliasmigration/report.php',
    ['id' => $runid]
));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string(
    'migrationreport',
    'local_iliasmigration',
    $runid
));
$PAGE->set_heading(get_string(
    'migrationreport',
    'local_iliasmigration',
    $runid
));

echo $OUTPUT->header();
echo html_writer::tag(
    'p',
    get_string('reportnotice', 'local_iliasmigration'),
    ['class' => 'alert alert-info']
);

$run = $report['run'];
$summary = new html_table();
$summary->data = [
    ['Run', (int) $run['id']],
    [get_string('status'), s((string) $run['status'])],
    [
        get_string('sourcepath', 'local_iliasmigration'),
        s((string) $run['sourcepath']),
    ],
    [
        get_string('sourcehash', 'local_iliasmigration'),
        s((string) $run['sourcehash']),
    ],
    [
        get_string('targetcategory', 'local_iliasmigration'),
        s((string) ($run['categoryid'] ?? $run['categorypath'] ?? '')),
    ],
];
echo html_writer::table($summary);

echo $OUTPUT->heading(
    get_string('stepsummary', 'local_iliasmigration'),
    3
);

$table = new html_table();
$table->head = [
    get_string('migrationstep', 'local_iliasmigration'),
    get_string('status'),
    get_string('attempts', 'local_iliasmigration'),
    get_string('message'),
];

foreach ($report['steps'] as $step) {
    $message = $step['error'] ?? '';

    if ($message === ''
            && is_array($step['result'] ?? null)) {
        $result = $step['result'];
        $message = !empty($result['reason'])
            ? (string) $result['reason']
            : 'operations='
                . (int) ($result['operation_count'] ?? 0);
    }

    $table->data[] = [
        s((string) $step['label']),
        s((string) $step['status'])
            . (!empty($step['ignored']) ? ' (IGNORÉ)' : ''),
        (int) $step['attempts'],
        s((string) $message),
    ];
}

echo html_writer::table($table);

echo html_writer::link(
    new moodle_url(
        '/local/iliasmigration/report.php',
        ['id' => $runid, 'format' => 'json']
    ),
    get_string('downloadjsonreport', 'local_iliasmigration'),
    ['class' => 'btn btn-primary mr-2']
);

echo html_writer::link(
    new moodle_url(
        '/local/iliasmigration/run.php',
        ['id' => $runid]
    ),
    get_string('backtorun', 'local_iliasmigration'),
    ['class' => 'btn btn-secondary']
);

echo $OUTPUT->footer();

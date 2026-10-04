<?php

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/iliasmigration:operate', $context);

$runid = required_param('id', PARAM_INT);
$manager = new \local_iliasmigration\operator_run_manager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $action = required_param('action', PARAM_ALPHA);
    $stepid = optional_param('stepid', 0, PARAM_INT);

    if ($action === 'retry') {
        $manager->retry_failed($runid, $stepid, (int) $USER->id);
    } else if ($action === 'ignore') {
        $manager->ignore_failed($runid, $stepid, (int) $USER->id);
    } else if ($action !== 'continue') {
        throw new moodle_exception('invalidparameter');
    }

    redirect(new moodle_url(
        '/local/iliasmigration/execute.php',
        ['id' => $runid, 'sesskey' => sesskey()]
    ));
}

$run = $manager->get_run($runid);
$steps = $manager->get_steps($runid);
$logs = $manager->get_logs($runid, 100);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/iliasmigration/run.php', ['id' => $runid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('migrationrun', 'local_iliasmigration', $runid));
$PAGE->set_heading(get_string('migrationrun', 'local_iliasmigration', $runid));

echo $OUTPUT->header();

echo html_writer::div(
    get_string('runstatus', 'local_iliasmigration')
        . ': '
        . html_writer::tag('strong', s((string) $run->status)),
    'mb-3'
);

$summary = new html_table();
$summary->data = [
    [get_string('sourcepath', 'local_iliasmigration'), s((string) $run->sourcepath)],
    [get_string('sourcehash', 'local_iliasmigration'), s((string) $run->sourcehash)],
    [
        get_string('targetcategory', 'local_iliasmigration'),
        s(
            $run->categoryid
                ? (string) $run->categoryid
                : (string) ($run->categorypath ?? '')
        ),
    ],
    [get_string('timecreated'), userdate((int) $run->timecreated)],
];
echo html_writer::table($summary);

$table = new html_table();
$table->head = [
    '#',
    get_string('migrationstep', 'local_iliasmigration'),
    get_string('status'),
    get_string('attempts', 'local_iliasmigration'),
    get_string('result', 'local_iliasmigration'),
];

$failedstep = null;

foreach ($steps as $step) {
    $result = '';

    if (!empty($step->errormessage)) {
        $result = html_writer::div(
            s((string) $step->errormessage),
            'text-danger'
        );

        if ($step->status ===
                \local_iliasmigration\operator_run_manager::STEP_FAILED) {
            $failedstep = $step;
        }
    } else if (!empty($step->resultjson)) {
        $decoded = json_decode((string) $step->resultjson, true);

        if (is_array($decoded)) {
            $parts = [];

            if (isset($decoded['operation_count'])) {
                $parts[] =
                    'operations=' . (int) $decoded['operation_count'];
            }

            if (!empty($decoded['actions'])
                    && is_array($decoded['actions'])) {
                foreach ($decoded['actions'] as $action => $count) {
                    $parts[] = $action . '=' . $count;
                }
            }

            if (!empty($decoded['reason'])) {
                $parts[] = (string) $decoded['reason'];
            }

            $result = s(implode(' ; ', $parts));
        }
    }

    $table->data[] = [
        (int) $step->position,
        s((string) $step->label),
        s((string) $step->status)
            . (!empty($step->ignored) ? ' (IGNORÉ)' : ''),
        (int) $step->attempts,
        $result,
    ];
}

echo html_writer::table($table);

if ($run->status ===
        \local_iliasmigration\operator_run_manager::RUN_WAITING
        && $failedstep) {
    echo html_writer::tag(
        'div',
        get_string('failuredecision', 'local_iliasmigration'),
        ['class' => 'alert alert-warning']
    );

    foreach (['retry', 'ignore'] as $action) {
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => (new moodle_url(
                '/local/iliasmigration/run.php',
                ['id' => $runid]
            ))->out(false),
            'class' => 'd-inline mr-2',
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'action',
            'value' => $action,
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'stepid',
            'value' => (int) $failedstep->id,
        ]);
        echo html_writer::tag(
            'button',
            get_string(
                $action === 'retry'
                    ? 'retrystep'
                    : 'ignorestep',
                'local_iliasmigration'
            ),
            [
                'type' => 'submit',
                'class' => $action === 'retry'
                    ? 'btn btn-primary'
                    : 'btn btn-warning',
            ]
        );
        echo html_writer::end_tag('form');
    }
} else if (in_array(
    $run->status,
    [
        \local_iliasmigration\operator_run_manager::RUN_READY,
        \local_iliasmigration\operator_run_manager::RUN_RUNNING,
    ],
    true
)) {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url(
            '/local/iliasmigration/run.php',
            ['id' => $runid]
        ))->out(false),
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'sesskey',
        'value' => sesskey(),
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'action',
        'value' => 'continue',
    ]);
    echo html_writer::tag(
        'button',
        get_string('continuepipeline', 'local_iliasmigration'),
        ['type' => 'submit', 'class' => 'btn btn-primary']
    );
    echo html_writer::end_tag('form');
}

echo html_writer::div(
    html_writer::link(
        new moodle_url(
            '/local/iliasmigration/report.php',
            ['id' => $runid]
        ),
        get_string('viewreport', 'local_iliasmigration'),
        ['class' => 'btn btn-secondary mr-2']
    )
    . html_writer::link(
        new moodle_url(
            '/local/iliasmigration/report.php',
            ['id' => $runid, 'format' => 'json']
        ),
        get_string('downloadjsonreport', 'local_iliasmigration'),
        ['class' => 'btn btn-secondary']
    ),
    'my-3'
);

echo $OUTPUT->heading(
    get_string('journal', 'local_iliasmigration'),
    3
);

$logtable = new html_table();
$logtable->head = [
    get_string('time'),
    get_string('level', 'local_iliasmigration'),
    get_string('event', 'local_iliasmigration'),
    get_string('message'),
];

foreach ($logs as $log) {
    $logtable->data[] = [
        userdate((int) $log->timecreated),
        s((string) $log->level),
        s((string) $log->event),
        s((string) $log->message),
    ];
}

echo html_writer::table($logtable);
echo $OUTPUT->footer();

<?php

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/iliasmigration:operate', $context);

$sourcepath = required_param('sourcepath', PARAM_RAW_TRIMMED);
$categoryid = optional_param('categoryid', 0, PARAM_INT);
$categorypath = optional_param('categorypath', '', PARAM_RAW_TRIMMED);

$resetter = new \local_iliasmigration\operator_mapping_reset();
$manager = new \local_iliasmigration\operator_run_manager();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url(
    '/local/iliasmigration/reset.php',
    [
        'sourcepath' => $sourcepath,
        'categoryid' => $categoryid,
        'categorypath' => $categorypath,
    ]
));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string(
    'resetorphanedmapping',
    'local_iliasmigration'
));
$PAGE->set_heading(get_string(
    'resetorphanedmapping',
    'local_iliasmigration'
));

try {
    $inspection = $resetter->inspect_source($sourcepath);
} catch (Throwable $exception) {
    \core\notification::error($exception->getMessage());
    redirect(new moodle_url('/local/iliasmigration/index.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    if (empty($inspection['reset_allowed'])) {
        throw new moodle_exception(
            'resetnotallowed',
            'local_iliasmigration'
        );
    }

    try {
        $result = $resetter->reset_source(
            $sourcepath,
            (int) $USER->id
        );

        \core\notification::success(get_string(
            'resetcompleted',
            'local_iliasmigration',
            (object) [
                'count' => (int) $result['mapping_count'],
                'auditid' => (int) $result['audit_id'],
            ]
        ));

        $runid = $manager->create_run(
            $sourcepath,
            $categoryid,
            $categorypath,
            (int) $USER->id
        );

        redirect(new moodle_url(
            '/local/iliasmigration/execute.php',
            ['id' => $runid, 'sesskey' => sesskey()]
        ));
    } catch (Throwable $exception) {
        \core\notification::error($exception->getMessage());
        redirect(new moodle_url('/local/iliasmigration/index.php'));
    }
}

echo $OUTPUT->header();

if (empty($inspection['reset_allowed'])) {
    echo html_writer::div(
        s((string) ($inspection['message'] ?? '')),
        'alert alert-danger'
    );

    echo html_writer::link(
        new moodle_url('/local/iliasmigration/index.php'),
        get_string('back')
    );

    echo $OUTPUT->footer();
    exit;
}

echo html_writer::div(
    get_string('resetwarning', 'local_iliasmigration'),
    'alert alert-warning'
);

$table = new html_table();
$table->data = [
    [
        get_string('sourcepath', 'local_iliasmigration'),
        s((string) $inspection['sourcepath']),
    ],
    [
        get_string('sourcecourse', 'local_iliasmigration'),
        s((string) $inspection['sourcecourse']),
    ],
    [
        get_string('sourceinstance', 'local_iliasmigration'),
        s((string) $inspection['sourceinstance']),
    ],
    [
        get_string('deletedtargetcourse', 'local_iliasmigration'),
        $inspection['targetcourseid'] !== null
            ? (int) $inspection['targetcourseid']
            : '-',
    ],
    [
        get_string('mappingcount', 'local_iliasmigration'),
        (int) $inspection['mapping_count'],
    ],
];
echo html_writer::table($table);

if (!empty($inspection['mapping_types'])
        && is_array($inspection['mapping_types'])) {
    $typetable = new html_table();
    $typetable->head = [
        get_string('mappingtype', 'local_iliasmigration'),
        get_string('mappingquantity', 'local_iliasmigration'),
    ];

    foreach ($inspection['mapping_types'] as $type => $count) {
        $typetable->data[] = [
            s((string) $type),
            (int) $count,
        ];
    }

    echo $OUTPUT->heading(
        get_string('mappingdetails', 'local_iliasmigration'),
        3
    );
    echo html_writer::table($typetable);
}

echo html_writer::tag(
    'p',
    get_string('resetauditnotice', 'local_iliasmigration'),
    ['class' => 'text-muted']
);

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url(
        '/local/iliasmigration/reset.php',
        [
            'sourcepath' => $sourcepath,
            'categoryid' => $categoryid,
            'categorypath' => $categorypath,
        ]
    ))->out(false),
]);

echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey(),
]);

echo html_writer::tag(
    'button',
    get_string('resetandrelaunch', 'local_iliasmigration'),
    ['type' => 'submit', 'class' => 'btn btn-danger mr-2']
);

echo html_writer::link(
    new moodle_url('/local/iliasmigration/index.php'),
    get_string('cancel'),
    ['class' => 'btn btn-secondary']
);

echo html_writer::end_tag('form');
echo $OUTPUT->footer();

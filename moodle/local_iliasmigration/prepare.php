<?php

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/iliasmigration:operate', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/iliasmigration/prepare.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string(
    'prepareexportresult',
    'local_iliasmigration'
));
$PAGE->set_heading(get_string(
    'operatorconsole',
    'local_iliasmigration'
));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(new moodle_url(
        '/local/iliasmigration/index.php'
    ));
}

require_sesskey();

$zipname = required_param(
    'zipname',
    PARAM_FILE
);
$outputname = required_param(
    'outputname',
    PARAM_RAW_TRIMMED
);

$preparer = new \local_iliasmigration\operator_package_preparer();
$recoveryimporter = new \local_iliasmigration\operator_recovery_bundle_importer();

try {
    $imports = $preparer->available_imports();

    if (!array_key_exists($zipname, $imports)) {
        throw new \coding_exception(
            'Selected ILIAS ZIP is not available in importsroot.'
        );
    }

    $result = $preparer->prepare(
        (string) $imports[$zipname]['path'],
        $outputname
    );
} catch (Throwable $exception) {
    \core\notification::error(
        $exception->getMessage()
    );
    redirect(new moodle_url(
        '/local/iliasmigration/index.php'
    ));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(
    get_string(
        'prepareexportresult',
        'local_iliasmigration'
    ),
    3
);

$table = new html_table();
$table->data = [
    [
        get_string('importzip', 'local_iliasmigration'),
        s($zipname),
    ],
    [
        get_string('sourcecourse', 'local_iliasmigration'),
        s((string) ($result['course'] ?? '')),
    ],
    [
        get_string('coursetitle', 'local_iliasmigration'),
        s((string) ($result['title'] ?? '')),
    ],
    [
        get_string('packagepath', 'local_iliasmigration'),
        s((string) ($result['package_root'] ?? '')),
    ],
    [
        get_string('sourcepath', 'local_iliasmigration'),
        s((string) ($result['migration_json'] ?? '')),
    ],
    [
        get_string('packageitems', 'local_iliasmigration'),
        (int) ($result['total_items'] ?? 0),
    ],
    [
        get_string('missingcount', 'local_iliasmigration'),
        (int) ($result['missing_count'] ?? 0),
    ],
];
echo html_writer::table($table);

$recoveryrequired = !empty(
    $result['recovery_required']
);

if ($recoveryrequired) {
    echo html_writer::div(
        get_string(
            'recoveryrequirednotice',
            'local_iliasmigration',
            (int) ($result['missing_count'] ?? 0)
        ),
        'alert alert-warning'
    );

    $plan = is_array(
        $result['recovery_plan'] ?? null
    )
        ? $result['recovery_plan']
        : [];

    $requests = is_array(
        $plan['requests'] ?? null
    )
        ? $plan['requests']
        : [];

    if ($requests) {
        echo $OUTPUT->heading(
            get_string(
                'recoveryrequests',
                'local_iliasmigration'
            ),
            4
        );

        $requesttable = new html_table();
        $requesttable->head = [
            get_string('recoverytype', 'local_iliasmigration'),
            get_string('sourceobject', 'local_iliasmigration'),
            get_string('assignmentid', 'local_iliasmigration'),
            get_string('recoveryresource', 'local_iliasmigration'),
            get_string('recoverytarget', 'local_iliasmigration'),
            get_string('readonly', 'local_iliasmigration'),
        ];

        foreach ($requests as $request) {
            if (!is_array($request)) {
                continue;
            }

            $resource = (string) (
                $request['collection_uuid']
                ?? $request['source_path']
                ?? ''
            );

            $requesttable->data[] = [
                s((string) ($request['type'] ?? '')),
                s((string) ($request['source_ref_id'] ?? '')),
                s((string) ($request['assignment_id'] ?? '')),
                s($resource),
                s((string) (
                    $request['execution_target']
                    ?? ''
                )),
                !empty($request['read_only'])
                    ? get_string('yes')
                    : get_string('no'),
            ];
        }

        echo html_writer::table($requesttable);
    }

    $unresolved = (int) (
        $plan['unresolved_count'] ?? 0
    );

    if ($unresolved > 0) {
        echo html_writer::div(
            get_string(
                'unresolvedrecoveries',
                'local_iliasmigration',
                $unresolved
            ),
            'alert alert-danger'
        );
    }

    echo html_writer::tag(
        'p',
        get_string(
            'recoveryplanpath',
            'local_iliasmigration',
            (string) (
                $result['recovery_plan_path']
                ?? ''
            )
        ),
        ['class' => 'text-muted']
    );

    if ($requests && $unresolved === 0) {
        echo $OUTPUT->heading(
            get_string(
                'importrecoverybundle',
                'local_iliasmigration'
            ),
            4
        );

        echo html_writer::tag(
            'p',
            get_string(
                'recoverybundleintro',
                'local_iliasmigration'
            ),
            ['class' => 'alert alert-info']
        );

        $serverbundles = [];
        try {
            $serverbundles = $recoveryimporter
                ->available_server_bundles();
        } catch (Throwable $exception) {
            \core\notification::warning(
                $exception->getMessage()
            );
        }

        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => (new moodle_url(
                '/local/iliasmigration/recover.php'
            ))->out(false),
            'enctype' => 'multipart/form-data',
            'class' => 'mb-4',
        ]);

        foreach ([
            'sesskey' => sesskey(),
            'zipname' => $zipname,
            'outputname' => $outputname,
        ] as $name => $value) {
            echo html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => $name,
                'value' => $value,
            ]);
        }

        if ($serverbundles) {
            $serveroptions = [];
            foreach (
                $serverbundles
                as $name => $metadata
            ) {
                $serveroptions[$name] =
                    $name
                    . ' — '
                    . display_size(
                        (int) (
                            $metadata['size']
                            ?? 0
                        )
                    );
            }

            echo html_writer::start_div(
                'form-group'
            );
            echo html_writer::label(
                get_string(
                    'recoverybundleserver',
                    'local_iliasmigration'
                ),
                'id_serverbundle'
            );
            echo html_writer::select(
                $serveroptions,
                'serverbundle',
                '',
                [
                    '' => get_string(
                        'recoverybundleservernone',
                        'local_iliasmigration'
                    ),
                ],
                [
                    'id' => 'id_serverbundle',
                    'class' => 'form-control',
                ]
            );
            echo html_writer::end_div();

            echo html_writer::tag(
                'p',
                get_string(
                    'recoverybundleorupload',
                    'local_iliasmigration'
                ),
                ['class' => 'text-muted']
            );
        }

        echo html_writer::start_div('form-group');
        echo html_writer::label(
            get_string(
                'recoverybundlefile',
                'local_iliasmigration'
            ),
            'id_recoverybundle'
        );
        echo html_writer::empty_tag('input', [
            'type' => 'file',
            'name' => 'recoverybundle',
            'id' => 'id_recoverybundle',
            'class' => 'form-control-file',
            'accept' => '.tar.gz,.tgz,application/gzip',
        ]);
        echo html_writer::end_div();

        echo html_writer::tag(
            'button',
            get_string(
                'recoverybundleaction',
                'local_iliasmigration'
            ),
            [
                'type' => 'submit',
                'class' => 'btn btn-primary',
            ]
        );

        echo html_writer::end_tag('form');
    }
} else {
    echo html_writer::div(
        get_string(
            'packagepreparedready',
            'local_iliasmigration'
        ),
        'alert alert-success'
    );

    echo html_writer::link(
        new moodle_url(
            '/local/iliasmigration/index.php',
            [
                'sourcepath' =>
                    (string) $result['migration_json'],
            ]
        ),
        get_string(
            'usepreparedpackage',
            'local_iliasmigration'
        ),
        ['class' => 'btn btn-primary mr-2']
    );
}

echo html_writer::link(
    new moodle_url('/local/iliasmigration/index.php'),
    get_string('back'),
    ['class' => 'btn btn-secondary']
);

echo $OUTPUT->footer();

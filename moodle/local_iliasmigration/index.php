<?php

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/iliasmigration:operate', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/iliasmigration/index.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('operatorconsole', 'local_iliasmigration'));
$PAGE->set_heading(get_string('operatorconsole', 'local_iliasmigration'));

$manager = new \local_iliasmigration\operator_run_manager();
$resetter = new \local_iliasmigration\operator_mapping_reset();
$preparer = new \local_iliasmigration\operator_package_preparer();
$artifacts = new \local_iliasmigration\operator_artifact_manager();
$prefillpackage = optional_param('package', '', PARAM_FILE);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    try {
        $packagename = required_param(
            'packagename',
            PARAM_FILE
        );
        $categoryid = optional_param(
            'categoryid',
            0,
            PARAM_INT
        );
        $categorypath = optional_param(
            'categorypath',
            '',
            PARAM_RAW_TRIMMED
        );

        $sourcepath = $artifacts->package_migration_path(
            $packagename
        );

        $inspection = $resetter->inspect_source(
            $sourcepath
        );

        if (($inspection['state'] ?? '')
                === \local_iliasmigration\operator_mapping_reset::STATE_ORPHANED
                && !empty($inspection['reset_allowed'])) {
            redirect(new moodle_url(
                '/local/iliasmigration/reset.php',
                [
                    'sourcepath' => $sourcepath,
                    'categoryid' => $categoryid,
                    'categorypath' => $categorypath,
                ]
            ));
        }

        if (($inspection['state'] ?? '')
                === \local_iliasmigration\operator_mapping_reset::STATE_AMBIGUOUS) {
            throw new \coding_exception(
                (string) ($inspection['message'] ?? 'Unsafe mapping reset state.')
            );
        }

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
    }
}

echo $OUTPUT->header();

echo html_writer::div(
    get_string('manualworkflowintro', 'local_iliasmigration'),
    'alert alert-info'
);

/*
 * 1. Native ILIAS exports.
 */
echo $OUTPUT->heading(
    get_string('exportsmanagement', 'local_iliasmigration'),
    3
);

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url(
        '/local/iliasmigration/upload_export.php'
    ))->out(false),
    'enctype' => 'multipart/form-data',
    'class' => 'mb-4',
]);

echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey(),
]);

echo html_writer::start_div('form-group');
echo html_writer::label(
    get_string('exportuploadfile', 'local_iliasmigration'),
    'id_exportzip'
);
echo html_writer::empty_tag('input', [
    'type' => 'file',
    'name' => 'exportzip',
    'id' => 'id_exportzip',
    'class' => 'form-control-file',
    'accept' => '.zip,application/zip',
    'required' => 'required',
]);
echo html_writer::end_div();

echo html_writer::tag(
    'button',
    get_string('exportuploadaction', 'local_iliasmigration'),
    ['type' => 'submit', 'class' => 'btn btn-primary']
);
echo html_writer::end_tag('form');

try {
    $imports = $preparer->available_imports();
} catch (Throwable $exception) {
    $imports = [];
    \core\notification::error($exception->getMessage());
}

if (!$imports) {
    echo html_writer::div(
        get_string('noimports', 'local_iliasmigration'),
        'alert alert-secondary'
    );
} else {
    $exporttable = new html_table();
    $exporttable->head = [
        get_string('importzip', 'local_iliasmigration'),
        get_string('size'),
        get_string('lastmodified'),
        get_string('actions'),
    ];

    foreach ($imports as $name => $metadata) {
        $deleteform = html_writer::start_tag('form', [
            'method' => 'post',
            'action' => (new moodle_url(
                '/local/iliasmigration/delete_export.php'
            ))->out(false),
            'class' => 'd-inline',
        ]);
        $deleteform .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]);
        $deleteform .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'zipname',
            'value' => $name,
        ]);
        $deleteform .= html_writer::tag(
            'button',
            get_string('delete'),
            [
                'type' => 'submit',
                'class' => 'btn btn-sm btn-danger',
                'onclick' => "return confirm('"
                    . addslashes(get_string(
                        'confirmdeleteexport',
                        'local_iliasmigration',
                        $name
                    ))
                    . "');",
            ]
        );
        $deleteform .= html_writer::end_tag('form');

        $exporttable->data[] = [
            s($name),
            display_size((int) ($metadata['size'] ?? 0)),
            userdate((int) ($metadata['mtime'] ?? 0)),
            $deleteform,
        ];
    }

    echo html_writer::table($exporttable);
}

/*
 * 2. Prepare one selected native export.
 */
echo $OUTPUT->heading(
    get_string('prepareexport', 'local_iliasmigration'),
    3
);
echo html_writer::tag(
    'p',
    get_string('prepareexportintro_manual', 'local_iliasmigration'),
    ['class' => 'text-muted']
);

if ($imports) {
    $importoptions = [];
    foreach ($imports as $name => $metadata) {
        $importoptions[$name] =
            $name . ' — ' . display_size((int) ($metadata['size'] ?? 0));
    }

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url(
            '/local/iliasmigration/prepare.php'
        ))->out(false),
        'class' => 'mb-5',
    ]);

    echo html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'sesskey',
        'value' => sesskey(),
    ]);

    echo html_writer::start_div('form-group');
    echo html_writer::label(
        get_string('importzip', 'local_iliasmigration'),
        'id_zipname'
    );
    echo html_writer::select(
        $importoptions,
        'zipname',
        '',
        false,
        [
            'id' => 'id_zipname',
            'class' => 'form-control',
            'required' => 'required',
        ]
    );
    echo html_writer::end_div();

    echo html_writer::start_div('form-group');
    echo html_writer::label(
        get_string('outputname', 'local_iliasmigration'),
        'id_outputname'
    );
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'outputname',
        'id' => 'id_outputname',
        'class' => 'form-control',
        'required' => 'required',
        'placeholder' => 'course504',
        'pattern' => '[A-Za-z0-9][A-Za-z0-9._-]{0,63}',
    ]);
    echo html_writer::end_div();

    echo html_writer::tag(
        'button',
        get_string('preparepackage', 'local_iliasmigration'),
        ['type' => 'submit', 'class' => 'btn btn-primary']
    );

    echo html_writer::end_tag('form');
}

/*
 * 3. Prepared packages.
 */
echo $OUTPUT->heading(
    get_string('packagesmanagement', 'local_iliasmigration'),
    3
);

try {
    $packages = $artifacts->available_packages();
} catch (Throwable $exception) {
    $packages = [];
    \core\notification::error($exception->getMessage());
}

if (!$packages) {
    echo html_writer::div(
        get_string('nopackages', 'local_iliasmigration'),
        'alert alert-secondary'
    );
} else {
    $packagetable = new html_table();
    $packagetable->head = [
        get_string('packagename', 'local_iliasmigration'),
        get_string('coursetitle', 'local_iliasmigration'),
        get_string('sourcecourse', 'local_iliasmigration'),
        get_string('missingcount', 'local_iliasmigration'),
        get_string('status'),
        get_string('size'),
        get_string('actions'),
    ];

    foreach ($packages as $name => $metadata) {
        $actions = '';

        if (!empty($metadata['ready'])) {
            $actions .= html_writer::link(
                new moodle_url(
                    '/local/iliasmigration/index.php',
                    ['package' => $name]
                ),
                get_string('selectpackage', 'local_iliasmigration'),
                ['class' => 'btn btn-sm btn-primary mr-2']
            );
        }

        $actions .= html_writer::start_tag('form', [
            'method' => 'post',
            'action' => (new moodle_url(
                '/local/iliasmigration/delete_package.php'
            ))->out(false),
            'class' => 'd-inline',
        ]);
        $actions .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'sesskey',
            'value' => sesskey(),
        ]);
        $actions .= html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'packagename',
            'value' => $name,
        ]);
        $actions .= html_writer::tag(
            'button',
            get_string('delete'),
            [
                'type' => 'submit',
                'class' => 'btn btn-sm btn-danger',
                'onclick' => "return confirm('"
                    . addslashes(get_string(
                        'confirmdeletepackage',
                        'local_iliasmigration',
                        $name
                    ))
                    . "');",
            ]
        );
        $actions .= html_writer::end_tag('form');

        $missing = $metadata['missing_count'];
        $packagetable->data[] = [
            s($name),
            s((string) ($metadata['title'] ?? '')),
            s((string) ($metadata['source_course'] ?? '')),
            $missing === null ? '-' : (int) $missing,
            s((string) ($metadata['status'] ?? '')),
            display_size((int) ($metadata['size'] ?? 0)),
            $actions,
        ];
    }

    echo html_writer::table($packagetable);
}

/*
 * 4. Start a migration from a ready package selected by name.
 */
echo $OUTPUT->heading(
    get_string('newmigration', 'local_iliasmigration'),
    3
);
echo html_writer::tag(
    'p',
    get_string('operatorintro_manual', 'local_iliasmigration'),
    ['class' => 'alert alert-info']
);

$readypackages = [];
foreach ($packages as $name => $metadata) {
    if (empty($metadata['ready'])) {
        continue;
    }

    $label = $name;
    if ((string) ($metadata['title'] ?? '') !== '') {
        $label .= ' — ' . (string) $metadata['title'];
    }
    $readypackages[$name] = $label;
}

if (!$readypackages) {
    echo html_writer::div(
        get_string('noreadypackages', 'local_iliasmigration'),
        'alert alert-warning'
    );
} else {
    if (!array_key_exists($prefillpackage, $readypackages)) {
        $prefillpackage = '';
    }

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url(
            '/local/iliasmigration/index.php'
        ))->out(false),
        'class' => 'mb-4',
    ]);

    echo html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'sesskey',
        'value' => sesskey(),
    ]);

    echo html_writer::start_div('form-group');
    echo html_writer::label(
        get_string('selectpackage', 'local_iliasmigration'),
        'id_packagename'
    );
    echo html_writer::select(
        $readypackages,
        'packagename',
        $prefillpackage,
        ['' => get_string('choose')],
        [
            'id' => 'id_packagename',
            'class' => 'form-control',
            'required' => 'required',
        ]
    );
    echo html_writer::end_div();

    $categories = $DB->get_records(
        'course_categories',
        [],
        'sortorder ASC',
        'id,name,parent'
    );
    $options = [
        '0' => get_string('selectcategory', 'local_iliasmigration'),
    ];
    foreach ($categories as $category) {
        $options[(string) $category->id] =
            $category->id . ' — ' . format_string($category->name);
    }

    echo html_writer::start_div('form-group');
    echo html_writer::label(
        get_string('existingcategory', 'local_iliasmigration'),
        'id_categoryid'
    );
    echo html_writer::select(
        $options,
        'categoryid',
        0,
        false,
        [
            'id' => 'id_categoryid',
            'class' => 'form-control',
        ]
    );
    echo html_writer::end_div();

    echo html_writer::tag(
        'p',
        get_string('categoryorpath', 'local_iliasmigration'),
        ['class' => 'text-muted']
    );

    echo html_writer::start_div('form-group');
    echo html_writer::label(
        get_string('categorypath', 'local_iliasmigration'),
        'id_categorypath'
    );
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'categorypath',
        'id' => 'id_categorypath',
        'class' => 'form-control',
        'placeholder' => 'Marine > Formation > Migration ILIAS',
    ]);
    echo html_writer::end_div();

    echo html_writer::tag(
        'button',
        get_string('createandrun', 'local_iliasmigration'),
        ['type' => 'submit', 'class' => 'btn btn-primary']
    );

    echo html_writer::end_tag('form');
}

echo $OUTPUT->heading(
    get_string('recentruns', 'local_iliasmigration'),
    3
);
$runs = $manager->recent_runs(25);

if (!$runs) {
    echo html_writer::tag(
        'p',
        get_string('noruns', 'local_iliasmigration')
    );
} else {
    $table = new html_table();
    $table->head = [
        '#',
        get_string('status'),
        get_string('sourcepath', 'local_iliasmigration'),
        get_string('targetcategory', 'local_iliasmigration'),
        get_string('timecreated'),
        '',
    ];

    foreach ($runs as $run) {
        $target = $run->categoryid
            ? (string) $run->categoryid
            : (string) ($run->categorypath ?? '');

        $table->data[] = [
            (int) $run->id,
            s((string) $run->status),
            s((string) $run->sourcepath),
            s($target),
            userdate((int) $run->timecreated),
            html_writer::link(
                new moodle_url(
                    '/local/iliasmigration/run.php',
                    ['id' => $run->id]
                ),
                get_string('view')
            ),
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();

<?php

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/iliasmigration:operate', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/iliasmigration/recover.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string(
    'recoverybundleresult',
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

if (!isset($_FILES['recoverybundle'])
        || !is_array($_FILES['recoverybundle'])
        || (int) (
            $_FILES['recoverybundle']['error']
            ?? UPLOAD_ERR_NO_FILE
        ) !== UPLOAD_ERR_OK) {
    \core\notification::error(
        get_string(
            'recoverybundleuploadmissing',
            'local_iliasmigration'
        )
    );
    redirect(new moodle_url(
        '/local/iliasmigration/index.php'
    ));
}

$tmpname = (string) (
    $_FILES['recoverybundle']['tmp_name']
    ?? ''
);
$originalname = (string) (
    $_FILES['recoverybundle']['name']
    ?? ''
);

if ($tmpname === ''
        || !is_uploaded_file($tmpname)) {
    \core\notification::error(
        get_string(
            'recoverybundleuploadmissing',
            'local_iliasmigration'
        )
    );
    redirect(new moodle_url(
        '/local/iliasmigration/index.php'
    ));
}

$preparer = new \local_iliasmigration\operator_package_preparer();
$importer = new \local_iliasmigration\operator_recovery_bundle_importer();

try {
    $imports = $preparer->available_imports();

    if (!array_key_exists($zipname, $imports)) {
        throw new \coding_exception(
            'Selected ILIAS ZIP is not available in importsroot.'
        );
    }

    $result = $importer->import_and_reprepare(
        (string) $imports[$zipname]['path'],
        $outputname,
        $tmpname,
        $originalname
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
        'recoverybundleresult',
        'local_iliasmigration'
    ),
    3
);

$notice = (object) [
    'before' => (int) (
        $result['previous_missing_count']
        ?? 0
    ),
    'after' => (int) (
        $result['missing_count']
        ?? 0
    ),
];

echo html_writer::div(
    get_string(
        'recoverybundleimported',
        'local_iliasmigration',
        $notice
    ),
    'alert alert-success'
);

$table = new html_table();
$table->data = [
    [
        get_string('importzip', 'local_iliasmigration'),
        s($zipname),
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
        get_string('missingcount', 'local_iliasmigration'),
        (int) ($result['missing_count'] ?? 0),
    ],
];
echo html_writer::table($table);

if (empty($result['recovery_required'])
        && (int) ($result['missing_count'] ?? 0) === 0) {
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

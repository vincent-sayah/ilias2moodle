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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    try {
        $sourcepath = required_param(
            'sourcepath',
            PARAM_RAW_TRIMMED
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
echo $OUTPUT->heading(get_string('newmigration', 'local_iliasmigration'), 3);
echo html_writer::tag(
    'p',
    get_string('operatorintro', 'local_iliasmigration'),
    ['class' => 'alert alert-info']
);

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/iliasmigration/index.php'))->out(false),
    'class' => 'mb-4',
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey(),
]);

echo html_writer::start_div('form-group');
echo html_writer::label(
    get_string('sourcepath', 'local_iliasmigration'),
    'id_sourcepath'
);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'sourcepath',
    'id' => 'id_sourcepath',
    'class' => 'form-control',
    'required' => 'required',
    'placeholder' => '/opt/ilias2moodle/work/course/migration.json',
]);
echo html_writer::end_div();

$categories = $DB->get_records(
    'course_categories',
    [],
    'sortorder ASC',
    'id,name,parent'
);
$options = ['0' => get_string('selectcategory', 'local_iliasmigration')];
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
    ['id' => 'id_categoryid', 'class' => 'form-control']
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

echo $OUTPUT->heading(get_string('recentruns', 'local_iliasmigration'), 3);
$runs = $manager->recent_runs(25);

if (!$runs) {
    echo html_writer::tag('p', get_string('noruns', 'local_iliasmigration'));
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
                new moodle_url('/local/iliasmigration/run.php', ['id' => $run->id]),
                get_string('view')
            ),
        ];
    }

    echo html_writer::table($table);
}

echo $OUTPUT->footer();

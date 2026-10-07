<?php

define('MOODLE_INTERNAL', true);

function get_string(string $key, string $component): string {
    return $component . ':' . $key;
}

require_once(
    __DIR__
    . '/../../moodle/local_iliasmigration/classes/operator_pipeline.php'
);

$pipeline = new \local_iliasmigration\operator_pipeline();

$result = [
    'mode' => 'apply',
    'phase' => 65,
    'writes_performed' => true,
    'operations' => [
        ['kind' => 'course', 'action' => 'UPDATE'],
        ['kind' => 'wiki', 'action' => 'CREATE'],
        ['kind' => 'wiki', 'action' => 'UPDATE'],
        ['kind' => 'exercise', 'action' => 'CREATE'],
    ],
];

$summary = $pipeline->summarize_result('wikis', $result);

if (($summary['operation_count'] ?? null) !== 2) {
    fwrite(STDERR, "Wiki operation count is not step-specific.\n");
    exit(1);
}

if (($summary['actions']['CREATE'] ?? 0) !== 1
        || ($summary['actions']['UPDATE'] ?? 0) !== 1) {
    fwrite(STDERR, "Wiki action counts are incorrect.\n");
    exit(1);
}

if (isset($summary['kinds']['course'])
        || isset($summary['kinds']['exercise'])) {
    fwrite(STDERR, "Unrelated operations leaked into the Wiki summary.\n");
    exit(1);
}

$structure = $pipeline->summarize_result(
    'structure',
    [
        'operations' => [
            ['kind' => 'course', 'action' => 'CREATE'],
            ['kind' => 'section', 'action' => 'CREATE'],
            ['kind' => 'subsection', 'action' => 'CREATE'],
            ['kind' => 'file', 'action' => 'DEFER'],
        ],
    ]
);

if (($structure['operation_count'] ?? null) !== 3) {
    fwrite(STDERR, "Structure operation count is incorrect.\n");
    exit(1);
}

echo "OPERATOR_STEP_SUMMARY_OK\n";

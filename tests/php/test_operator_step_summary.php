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

$phase7 = $pipeline->summarize_result(
    'phase7_identities',
    [
        'mode' => 'apply',
        'phase' => '7.1',
        'writes_performed' => true,
        'identity_counts' => [
            'matched' => 2,
            'not_in_target' => 3,
        ],
        'account_creation_counts' => [
            'create_candidate' => 3,
            'blocked' => 0,
        ],
        'membership_counts' => [
            'enrol' => 5,
            'update' => 0,
            'defer' => 0,
            'blocked' => 0,
        ],
        'created_user_count' => 3,
        'reused_user_count' => 2,
        'enrolled_membership_count' => 5,
        'updated_membership_count' => 0,
        'ready_for_apply' => true,
    ]
);

if (($phase7['created_user_count'] ?? null) !== 3
        || ($phase7['reused_user_count'] ?? null) !== 2
        || ($phase7['enrolled_membership_count'] ?? null) !== 5
        || empty($phase7['ready_for_apply'])) {
    fwrite(STDERR, "Phase 7.1 operator summary is incomplete.\n");
    exit(1);
}

echo "OPERATOR_STEP_SUMMARY_OK\n";

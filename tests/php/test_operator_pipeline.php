<?php

define('MOODLE_INTERNAL', true);

require_once(
    __DIR__
    . '/../../moodle/local_iliasmigration/classes/operator_pipeline.php'
);

$document = [
    'course' => [
        'items' => [
            ['source_id' => '10', 'type' => 'file', 'items' => []],
            ['source_id' => '20', 'type' => 'scorm', 'items' => []],
            ['source_id' => '30', 'type' => 'wiki', 'items' => []],
            ['source_id' => '40', 'type' => 'itgr', 'items' => []],
            [
                'source_id' => '50',
                'type' => 'folder',
                'items' => [
                    ['source_id' => '51', 'type' => 'glo', 'items' => []],
                ],
            ],
        ],
    ],
];

$inventory = \local_iliasmigration\operator_pipeline::inventory($document);
$steps = \local_iliasmigration\operator_pipeline::steps($document);
$keys = array_column($steps, 'stepkey');

$checks = [
    $inventory['simple_resources'] === ['10'],
    $inventory['scorm'] === ['20'],
    $inventory['wikis'] === ['30'],
    $inventory['item_groups'] === ['40'],
    $inventory['glossaries'] === ['51'],
    in_array('preflight', $keys, true),
    in_array('structure', $keys, true),
    in_array('simple_resources', $keys, true),
    in_array('scorm', $keys, true),
    in_array('wikis', $keys, true),
    in_array('glossaries', $keys, true),
    in_array('item_groups', $keys, true),
    !in_array('order_reconciliation', $keys, true),
    end($keys) === 'final_report',
];

foreach ($checks as $ok) {
    if (!$ok) {
        fwrite(STDERR, "Operator pipeline regression failed.\n");
        exit(1);
    }
}

echo "OPERATOR_PIPELINE_OK\n";

<?php

define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../../moodle/local_iliasmigration/classes/folder_flattener.php');

use local_iliasmigration\folder_flattener;

function fail_test(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assert_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fail_test($message . ' expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true));
    }
}

$document = [
    'schema_version' => '1.0',
    'source' => ['lms' => 'ILIAS', 'version' => '10.8'],
    'course' => [
        'source_id' => '100',
        'title' => 'POC profondeur',
        'items' => [
            [
                'source_id' => '110',
                'type' => 'folder',
                'title' => 'Niveau 1',
                'items' => [
                    [
                        'source_id' => '120',
                        'type' => 'folder',
                        'title' => 'Niveau 2',
                        'items' => [
                            ['source_id' => '121', 'type' => 'file', 'title' => 'avant.pdf'],
                            [
                                'source_id' => '130',
                                'type' => 'folder',
                                'title' => 'Niveau 3',
                                'items' => [
                                    ['source_id' => '131', 'type' => 'url', 'title' => 'lien'],
                                    [
                                        'source_id' => '140',
                                        'type' => 'folder',
                                        'title' => 'Niveau 4',
                                        'items' => [
                                            ['source_id' => '141', 'type' => 'file', 'title' => 'profond.pdf'],
                                        ],
                                    ],
                                ],
                            ],
                            ['source_id' => '122', 'type' => 'file', 'title' => 'apres.pdf'],
                        ],
                    ],
                    ['source_id' => '150', 'type' => 'url', 'title' => 'racine section'],
                ],
            ],
        ],
    ],
];

$result = (new folder_flattener())->flatten($document);
$items = $result['course']['items'][0]['items'];

assert_same(['120', '130', '140', '150'], array_column($items, 'source_id'),
    'Flattened folders must become deterministic level-2 siblings.');
assert_same('Niveau 2', $items[0]['title'], 'Level-2 folder title must remain unchanged.');
assert_same(['121', '122'], array_column($items[0]['items'], 'source_id'),
    'Direct level-2 activities must remain in their nearest source folder.');
assert_same('Niveau 2 / Niveau 3', $items[1]['title'],
    'Level-3 folder must receive a hierarchical Moodle title.');
assert_same(['131'], array_column($items[1]['items'], 'source_id'),
    'Level-3 direct activities must remain attached to the level-3 source folder.');
assert_same('Niveau 2 / Niveau 3 / Niveau 4', $items[2]['title'],
    'Level-4 folder must receive a complete hierarchical Moodle title.');
assert_same(['141'], array_column($items[2]['items'], 'source_id'),
    'Level-4 direct activities must remain attached to the level-4 source folder.');
assert_same(3, $items[1]['metadata']['moodle_original_depth'],
    'Level-3 source depth must remain traceable.');
assert_same('120', $items[1]['metadata']['moodle_original_parent_source_ref_id'],
    'Original level-3 parent must remain traceable.');
assert_same('110', $items[1]['metadata']['moodle_level_one_source_ref_id'],
    'Flattened folder must identify its Moodle level-1 anchor.');
assert_same(4, $items[2]['metadata']['moodle_original_depth'],
    'Level-4 source depth must remain traceable.');

$summary = $result['source']['transformations']['folder_flattening'];
assert_same(true, $summary['applied'], 'Flattening summary must report the transformation.');
assert_same(2, $summary['flattened_count'], 'Exactly two deep folders must be reported.');
assert_same('deep_folders_as_sibling_subsections', $summary['strategy'],
    'Flattening strategy identifier must remain stable.');

fwrite(STDOUT, "OK: folder flattening policy\n");

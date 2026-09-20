<?php

define('MOODLE_INTERNAL', true);

class coding_exception extends Exception {}

require_once(
    __DIR__
    . '/../../moodle/local_iliasmigration/classes/phase65_media_pool_renderer.php'
);

$structure = [
    'media' => [
        '819' => [
            'title' => 'duo',
            'items' => [[
                'purpose' => 'Standard',
                'mime_type' => 'image/png',
                'migration_path' => 'media_pools/278/media/819/du2.png',
            ]],
        ],
        '820' => [
            'title' => 'video5',
            'items' => [[
                'purpose' => 'Standard',
                'mime_type' => 'video/mp4',
                'migration_path' => 'media_pools/278/media/820/vid5.mp4',
            ]],
        ],
        '821' => [
            'title' => 'trio.png',
            'items' => [[
                'purpose' => 'Standard',
                'mime_type' => 'image/png',
                'migration_path' => 'media_pools/278/media/821/trio.png',
            ]],
        ],
    ],
    'records' => [
        [
            'source_tree_id' => '2',
            'item_type' => 'media',
            'title' => 'duo',
            'folder_path' => [],
            'media_source_id' => '819',
        ],
        [
            'source_tree_id' => '3',
            'item_type' => 'media',
            'title' => 'video5',
            'folder_path' => [],
            'media_source_id' => '820',
        ],
        [
            'source_tree_id' => '4',
            'item_type' => 'page',
            'title' => 'texte media',
            'folder_path' => [],
            'content' => [
                'blocks' => [
                    [
                        'type' => 'paragraph',
                        'text' => 'texte de contenu',
                        'inline' => [],
                    ],
                    [
                        'type' => 'media',
                        'source_id' => '821',
                    ],
                ],
            ],
        ],
    ],
];

$renderer = new \local_iliasmigration\phase65_media_pool_renderer();
$result = $renderer->render(
    $structure,
    static fn(string $path, array $asset): string =>
        '/pluginfile.php/test/' . basename($path)
);

$records = $result['records'];
$checks = [
    count($records) === 3,
    str_contains($records[0]['html'], '<img'),
    str_contains($records[0]['html'], 'du2.png'),
    str_contains($records[1]['html'], '<video'),
    str_contains($records[1]['html'], 'vid5.mp4'),
    str_contains($records[2]['html'], '<p>texte de contenu</p>'),
    str_contains($records[2]['html'], 'trio.png'),
    count($result['assets']) === 3,
];

foreach ($checks as $ok) {
    if (!$ok) {
        fwrite(STDERR, "Media Pool renderer regression failed.\n");
        exit(1);
    }
}

echo "MEDIA_POOL_RENDERER_OK\n";

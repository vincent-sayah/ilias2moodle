<?php

define('MOODLE_INTERNAL', true);

require_once(__DIR__ . '/../../moodle/local_iliasmigration/classes/phase65_content_renderer.php');
require_once(__DIR__ . '/../../moodle/local_iliasmigration/classes/phase65_wiki_renderer.php');

use local_iliasmigration\phase65_wiki_renderer;

$structure = [
    'pages' => [
        [
            'source_id' => '10',
            'title' => 'Accueil',
            'content' => [
                'status' => 'ok',
                'blocks' => [
                    [
                        'type' => 'paragraph',
                        'characteristic' => 'Headline1',
                        'inline' => [['type' => 'text', 'text' => 'Accueil']],
                    ],
                    [
                        'type' => 'paragraph',
                        'inline' => [
                            ['type' => 'text', 'text' => 'Voir '],
                            [
                                'type' => 'internal_link',
                                'text' => 'Page 2',
                                'target' => 'il_0_wpg_11',
                                'target_type' => 'wpg',
                                'source_ref_id' => '11',
                                'children' => [['type' => 'text', 'text' => 'Page 2']],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        [
            'source_id' => '11',
            'title' => 'Page 2',
            'content' => [
                'status' => 'ok',
                'blocks' => [
                    [
                        'type' => 'media',
                        'source_id' => '901',
                        'media' => [
                            'title' => 'wiki-image.png',
                            'items' => [[
                                'purpose' => 'Standard',
                                'mime_type' => 'image/png',
                            ]],
                        ],
                    ],
                    [
                        'type' => 'file_list',
                        'title' => 'Documents',
                        'files' => [[
                            'source_id' => '902',
                            'filename' => 'document.pdf',
                            'file' => null,
                        ]],
                    ],
                ],
            ],
        ],
    ],
    'media' => [
        '901' => [
            'source_id' => '901',
            'title' => 'wiki-image.png',
            'items' => [[
                'purpose' => 'Standard',
                'mime_type' => 'image/png',
                'migration_path' => 'wikis/273/media/901/wiki-image.png',
            ]],
        ],
    ],
    'files' => [
        '902' => [
            'source_id' => '902',
            'filename' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'migration_path' => 'wikis/273/files/902/document.pdf',
        ],
    ],
];

$result = (new phase65_wiki_renderer())->render($structure);
if (count($result['pages']) !== 2) {
    fwrite(STDERR, "Expected 2 Wiki pages.\n");
    exit(1);
}
if (count($result['assets']) !== 2) {
    fwrite(STDERR, "Expected 2 rehydrated Wiki assets.\n");
    exit(1);
}
if (count($result['internal_link_resolutions']) !== 1) {
    fwrite(STDERR, "Expected 1 Wiki internal link.\n");
    exit(1);
}

$accueil = $result['pages'][0]['html'];
$page2 = $result['pages'][1]['html'];

if (!str_contains($accueil, '#ilias-wiki-page-11')) {
    fwrite(STDERR, "Wiki page-link rendering mismatch.\n");
    exit(1);
}
if (!str_contains($page2, '@@PLUGINFILE@@/wikis/273/media/901/wiki-image.png')) {
    fwrite(STDERR, "Wiki media rendering mismatch.\n");
    exit(1);
}
if (!str_contains($page2, '@@PLUGINFILE@@/wikis/273/files/902/document.pdf')) {
    fwrite(STDERR, "Wiki file-list rendering mismatch.\n");
    exit(1);
}
if (!preg_match('/^[a-f0-9]{64}$/', (string) $result['fingerprint_sha256'])) {
    fwrite(STDERR, "Wiki fingerprint is invalid.\n");
    exit(1);
}

echo "Phase 6.5 Wiki renderer regression: OK\n";

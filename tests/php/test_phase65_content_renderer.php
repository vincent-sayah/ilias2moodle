<?php

define('MOODLE_INTERNAL', true);

require_once(__DIR__ . '/../../moodle/local_iliasmigration/classes/phase65_content_renderer.php');

use local_iliasmigration\phase65_content_renderer;

$structure = [
    'schema_version' => '1.0',
    'source' => [
        'lms' => 'ILIAS',
        'ref_id' => '270',
        'object_id' => '789',
    ],
    'blocks' => [
        [
            'type' => 'paragraph',
            'characteristic' => 'Headline1',
            'inline' => [
                [
                    'type' => 'strong',
                    'text' => 'Titre',
                    'children' => [['type' => 'text', 'text' => 'Titre']],
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'characteristic' => 'Standard',
            'inline' => [
                ['type' => 'text', 'text' => 'Voir '],
                [
                    'type' => 'external_link',
                    'text' => 'externe',
                    'href' => 'https://example.org',
                    'children' => [['type' => 'text', 'text' => 'externe']],
                ],
                ['type' => 'text', 'text' => ' puis '],
                [
                    'type' => 'internal_link',
                    'text' => 'interne',
                    'target' => 'il_0_htlm_518_134',
                    'target_type' => 'htlm',
                    'source_ref_id' => '134',
                    'children' => [['type' => 'text', 'text' => 'interne']],
                ],
            ],
        ],
        [
            'type' => 'media',
            'source_id' => '790',
            'media' => [
                'title' => 'image.png',
                'items' => [[
                    'purpose' => 'Standard',
                    'mime_type' => 'image/png',
                    'location' => 'image.png',
                    'migration_path' => 'content_pages/270/media/790/image.png',
                ]],
            ],
        ],
        [
            'type' => 'file_list',
            'title' => 'Fichiers',
            'files' => [[
                'source_id' => '791',
                'filename' => 'document test.pdf',
                'file' => [
                    'filename' => 'document test.pdf',
                    'migration_path' => 'content_pages/270/files/791/document test.pdf',
                ],
            ]],
        ],
        [
            'type' => 'table',
            'rows' => [[[
                [
                    'type' => 'paragraph',
                    'text' => 'Cellule',
                    'inline' => [['type' => 'text', 'text' => 'Cellule']],
                ],
            ]]],
        ],
        [
            'type' => 'section',
            'characteristic' => 'Attention',
            'blocks' => [[
                'type' => 'tabs',
                'tabs' => [[
                    'caption' => 'Onglet 1',
                    'blocks' => [[
                        'type' => 'paragraph',
                        'text' => 'Contenu onglet',
                        'inline' => [['type' => 'text', 'text' => 'Contenu onglet']],
                    ]],
                ]],
            ]],
        ],
        [
            'type' => 'grid',
            'cells' => [[
                'widths' => ['width_m' => '4'],
                'blocks' => [[
                    'type' => 'paragraph',
                    'text' => 'Colonne',
                    'inline' => [['type' => 'text', 'text' => 'Colonne']],
                ]],
            ]],
        ],
    ],
];

$renderer = new phase65_content_renderer();
$result = $renderer->render(
    $structure,
    static function(array $link): array {
        if (($link['source_ref_id'] ?? '') !== '134') {
            throw new RuntimeException('Unexpected internal-link ref_id.');
        }
        return [
            'status' => 'REWRITTEN',
            'reason' => null,
            'candidate_count' => 1,
            'url' => 'http://moodle.example/mod/url/view.php?id=16',
            'fallback_url' => 'http://ilias.example/goto.php?target=htlm_134',
            'moodle_target_type' => 'url',
            'moodle_target_id' => 16,
            'moodle_module' => 'url',
        ];
    }
);

$html = $result['html'];
$checks = [
    '<h1><strong>Titre</strong></h1>',
    '<a href="https://example.org">externe</a>',
    '<a href="http://moodle.example/mod/url/view.php?id=16">interne</a>',
    '@@PLUGINFILE@@/media/790/image.png',
    '@@PLUGINFILE@@/files/791/document%20test.pdf',
    '<table class="table table-bordered">',
    '<h3>Onglet 1</h3>',
    'col-md-4',
];

foreach ($checks as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "Missing rendered fragment: {$needle}\n");
        exit(1);
    }
}

if (count($result['assets']) !== 2) {
    fwrite(STDERR, "Expected exactly 2 rendered assets.\n");
    exit(1);
}

if (count($result['internal_link_resolutions']) !== 1) {
    fwrite(STDERR, "Expected exactly 1 internal-link resolution.\n");
    exit(1);
}

$resolution = $result['internal_link_resolutions'][0];
if (($resolution['status'] ?? '') !== 'REWRITTEN'
        || ($resolution['source_ref_id'] ?? '') !== '134') {
    fwrite(STDERR, "Internal-link resolution did not preserve the expected mapping metadata.\n");
    exit(1);
}

echo "Phase 6.5 Content Page renderer regression: OK\n";

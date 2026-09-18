<?php

define('MOODLE_INTERNAL', true);

require_once(__DIR__ . '/../../moodle/local_iliasmigration/classes/phase65_content_renderer.php');
require_once(__DIR__ . '/../../moodle/local_iliasmigration/classes/phase65_glossary_renderer.php');

use local_iliasmigration\phase65_glossary_renderer;

$structure = [
    'terms' => [
        [
            'source_id' => '6',
            'term' => 'Chat',
            'language' => 'fr',
            'definition' => [
                'status' => 'ok',
                'blocks' => [
                    [
                        'type' => 'paragraph',
                        'inline' => [['type' => 'text', 'text' => 'ceci est un chat']],
                    ],
                    [
                        'type' => 'media',
                        'source_id' => '798',
                        'media' => [
                            'title' => 'chat.jpg',
                            'items' => [[
                                'purpose' => 'Standard',
                                'mime_type' => 'image/jpeg',
                                'migration_path' => 'glossaries/272/media/798/chat.jpg',
                            ]],
                        ],
                    ],
                ],
            ],
        ],
        [
            'source_id' => '7',
            'term' => 'chien',
            'language' => 'fr',
            'definition' => [
                'status' => 'ok',
                'blocks' => [[
                    'type' => 'paragraph',
                    'inline' => [
                        ['type' => 'text', 'text' => 'Ceci est un '],
                        [
                            'type' => 'strong',
                            'children' => [[
                                'type' => 'emphasis',
                                'children' => [['type' => 'text', 'text' => 'chien']],
                            ]],
                        ],
                    ],
                ]],
            ],
        ],
    ],
];

$result = (new phase65_glossary_renderer())->render($structure);
if (count($result['terms']) !== 2) {
    fwrite(STDERR, "Expected 2 glossary terms.\n");
    exit(1);
}
if (count($result['assets']) !== 1) {
    fwrite(STDERR, "Expected 1 glossary asset.\n");
    exit(1);
}

$chat = $result['terms'][0]['html'];
$chien = $result['terms'][1]['html'];
if (!str_contains($chat, 'ceci est un chat')
        || !str_contains($chat, '@@PLUGINFILE@@/glossaries/272/media/798/chat.jpg')) {
    fwrite(STDERR, "Chat definition rendering mismatch.\n");
    exit(1);
}
if (!str_contains($chien, '<strong><em>chien</em></strong>')) {
    fwrite(STDERR, "Rich chien definition rendering mismatch.\n");
    exit(1);
}
if (!preg_match('/^[a-f0-9]{64}$/', (string) $result['fingerprint_sha256'])) {
    fwrite(STDERR, "Glossary fingerprint is invalid.\n");
    exit(1);
}

echo "Phase 6.5 Glossary renderer regression: OK\n";

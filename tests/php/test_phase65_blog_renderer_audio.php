<?php

define('MOODLE_INTERNAL', true);

class coding_exception extends Exception {}

require_once(
    __DIR__
    . '/../../moodle/local_iliasmigration/classes/phase65_blog_renderer.php'
);

$posting = [
    'content' => [
        'blocks' => [
            [
                'type' => 'media',
                'source_id' => '970',
                'media' => [
                    'title' => 'Rain-Window Fire.mp3',
                    'items' => [
                        [
                            'purpose' => 'Standard',
                            'mime_type' => 'audio/mpeg',
                            'location_type' => 'LocalFile',
                            'location' => 'Rain-Window Fire.mp3',
                            'migration_path' =>
                                'blogs/332/media/970/Rain-Window Fire.mp3',
                        ],
                    ],
                ],
            ],
        ],
    ],
];

$renderer = new \local_iliasmigration\phase65_blog_renderer();
$result = $renderer->render(
    $posting,
    static fn(string $path, array $asset): string =>
        '/pluginfile.php/test/' . rawurlencode(basename($path))
);

$html = $result['html'];

$checks = [
    str_contains($html, '<audio controls="controls"'),
    str_contains($html, 'type="audio/mpeg"'),
    str_contains($html, 'Rain-Window%20Fire.mp3'),
    count($result['assets']) === 1,
];

foreach ($checks as $ok) {
    if (!$ok) {
        fwrite(STDERR, "Blog MP3 renderer regression failed.\n");
        fwrite(STDERR, $html . "\n");
        exit(1);
    }
}

echo "BLOG_AUDIO_RENDERER_OK\n";

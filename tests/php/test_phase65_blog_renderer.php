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
                'type' => 'paragraph',
                'text' => 'Premier paragraphe',
                'inline' => [],
            ],
            [
                'type' => 'media',
                'source_id' => '735',
                'media' => [
                    'title' => 'tous.png',
                    'items' => [
                        [
                            'purpose' => 'Standard',
                            'mime_type' => 'image/png',
                            'location' => 'tous.png',
                            'migration_path' => 'blogs/247/media/735/tous.png',
                        ],
                    ],
                ],
            ],
            [
                'type' => 'grid',
                'cells' => [
                    [
                        'widths' => ['width_m' => 4],
                        'blocks' => [
                            [
                                'type' => 'paragraph',
                                'text' => 'colonne 1',
                                'inline' => [],
                            ],
                        ],
                    ],
                    [
                        'widths' => ['width_m' => 4],
                        'blocks' => [
                            [
                                'type' => 'media',
                                'source_id' => '736',
                                'media' => [
                                    'title' => 'trio.png',
                                    'items' => [
                                        [
                                            'purpose' => 'Standard',
                                            'mime_type' => 'image/png',
                                            'location' => 'trio.png',
                                            'migration_path' => 'blogs/247/media/736/trio.png',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'widths' => ['width_m' => 4],
                        'blocks' => [
                            [
                                'type' => 'paragraph',
                                'text' => 'colonne 3',
                                'inline' => [],
                            ],
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
        '/pluginfile.php/test/' . basename($path)
);

$html = $result['html'];

$checks = [
    str_contains($html, '<p>Premier paragraphe</p>'),
    str_contains($html, 'src="/pluginfile.php/test/tous.png"'),
    str_contains($html, 'src="/pluginfile.php/test/trio.png"'),
    substr_count($html, 'col-md-4') === 3,
    count($result['assets']) === 2,
];

foreach ($checks as $ok) {
    if (!$ok) {
        fwrite(STDERR, "Blog renderer regression failed.\n");
        exit(1);
    }
}

echo "BLOG_RENDERER_OK\n";

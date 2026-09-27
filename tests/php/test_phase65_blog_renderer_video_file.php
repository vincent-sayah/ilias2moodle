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
                'text' => 'Billet avec vidéo et fichier',
                'inline' => [],
            ],
            [
                'type' => 'media',
                'source_id' => '817',
                'media' => [
                    'title' => 'vid4.mp4',
                    'items' => [
                        [
                            'purpose' => 'Standard',
                            'mime_type' => 'video/mp4',
                            'location_type' => 'LocalFile',
                            'location' => 'vid4.mp4',
                            'migration_path' =>
                                'blogs/277/media/817/vid4.mp4',
                        ],
                    ],
                ],
            ],
            [
                'type' => 'file_list',
                'title' => 'liste de fichier',
                'files' => [
                    [
                        'source_id' => '816',
                        'filename' => 'usertakeover.docx',
                        'mime_type' =>
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'file' => [
                            'filename' => 'usertakeover.docx',
                            'migration_path' =>
                                'blogs/277/files/816/usertakeover.docx',
                        ],
                    ],
                ],
            ],
        ],
    ],
];

$renderer =
    new \local_iliasmigration\phase65_blog_renderer();

$result = $renderer->render(
    $posting,
    static fn(
        string $path,
        array $asset
    ): string =>
        '/pluginfile.php/test/'
        . basename($path)
);

$html = $result['html'];

$checks = [
    str_contains(
        $html,
        '<p>Billet avec vidéo et fichier</p>'
    ),
    str_contains(
        $html,
        '<video controls="controls"'
    ),
    str_contains(
        $html,
        'src="/pluginfile.php/test/vid4.mp4"'
    ),
    str_contains(
        $html,
        'type="video/mp4"'
    ),
    str_contains(
        $html,
        'href="/pluginfile.php/test/usertakeover.docx"'
    ),
    str_contains(
        $html,
        '>usertakeover.docx</a>'
    ),
    count($result['assets']) === 2,
];

foreach ($checks as $ok) {
    if (!$ok) {
        fwrite(
            STDERR,
            "Blog video/file-list renderer regression failed.\n"
        );
        fwrite(STDERR, $html . "\n");
        exit(1);
    }
}

echo "BLOG_VIDEO_FILE_RENDERER_OK\n";

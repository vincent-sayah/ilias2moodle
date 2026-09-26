<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../classes/phase7_blog_author_resolver.php');

[$options, $unrecognized] = cli_get_params(
    [
        'course' => 0,
        'blog-ref' => '',
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognized) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

$help = "ILIAS2Moodle Phase 7.5 Blog author dry-run\n\n"
    . "php local/iliasmigration/cli/phase7_blog_author_dry_run.php "
    . "--course=ID --blog-ref=REF_ID\n\n"
    . "Read-only. Audits existing mod_data Blog record owners against "
    . "persistent ILIAS user mappings.\n";

if ($options['help']) {
    echo $help;
    exit(0);
}

$courseid = (int) $options['course'];
$blogref = trim((string) $options['blog-ref']);

if ($courseid <= 0) {
    cli_error('A valid --course=ID is required.');
}
if ($blogref === '') {
    cli_error('Missing --blog-ref.');
}

try {
    $result = (
        new \local_iliasmigration\phase7_blog_author_resolver()
    )->resolve(
        $courseid,
        $blogref
    );

    echo json_encode(
        $result,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
    );
    echo PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "[PHASE7.5 BLOG DRY-RUN ERROR] "
        . get_class($exception)
        . ": "
        . $exception->getMessage()
        . PHP_EOL
    );
    fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);
    exit(1);
}

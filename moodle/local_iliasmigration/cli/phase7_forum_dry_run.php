<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'source' => '',
        'category' => 0,
        'forum-ref' => '',
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognized) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

$help = "ILIAS2Moodle Phase 7.4 Forum contribution dry-run\n\n"
    . "php local/iliasmigration/cli/phase7_forum_dry_run.php "
    . "--source=/path/to/migration.json "
    . "--category=ID "
    . "--forum-ref=REF_ID\n\n"
    . "Read-only. Validates author mappings, discussions, posts and assets. "
    . "No Moodle contribution is written.\n";

if ($options['help']) {
    echo $help;
    exit(0);
}

$source = trim((string) $options['source']);
$categoryid = (int) $options['category'];
$forumref = trim((string) $options['forum-ref']);

if ($source === '') {
    cli_error('Missing --source.');
}
if ($categoryid <= 0) {
    cli_error('A valid --category=ID is required.');
}
if ($forumref === '') {
    cli_error('Missing --forum-ref.');
}

try {
    $reader = new \local_iliasmigration\migration_reader();
    $document = $reader->read($source);

    $result = (
        new \local_iliasmigration\phase7_forum_contribution_resolver($source)
    )->resolve(
        $document,
        $source,
        $categoryid,
        $forumref
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
        "[PHASE7.4 FORUM DRY-RUN ERROR] "
        . get_class($exception)
        . ": "
        . $exception->getMessage()
        . PHP_EOL
    );
    fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);
    exit(1);
}

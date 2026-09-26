<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../classes/phase7_forum_contribution_resolver.php');
require_once(__DIR__ . '/../classes/phase7_forum_contribution_executor.php');

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

$help = "ILIAS2Moodle Phase 7.4 Forum contribution apply\n\n"
    . "php local/iliasmigration/cli/phase7_forum_apply.php "
    . "--source=/path/to/migration.json "
    . "--category=ID "
    . "--forum-ref=REF_ID\n\n"
    . "Requires a successful Phase 7.4 dry-run. Creates authored Forum "
    . "discussions/posts and attachments idempotently.\n";

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
        new \local_iliasmigration\phase7_forum_contribution_executor(
            $source
        )
    )->execute(
        $document,
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
        "[PHASE7.4 FORUM APPLY ERROR] "
        . get_class($exception)
        . ": "
        . $exception->getMessage()
        . PHP_EOL
    );
    fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);
    exit(1);
}

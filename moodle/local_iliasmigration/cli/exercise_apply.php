<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'source' => '',
        'category' => 0,
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    cli_error(
        'Unknown options: '
        . implode(', ', $unrecognized)
    );
}

$help =
    "ILIAS2Moodle Exercise apply\n\n"
    . "php local/iliasmigration/cli/exercise_apply.php "
    . "--source=/path/to/migration.json "
    . "--category=ID\n\n"
    . "One Moodle mod_assign is created or updated "
    . "for each validated ILIAS Exercise unit.\n"
    . "User submissions, grades and group memberships "
    . "are not migrated in Phase 6.5.4.\n";

if ($options['help']) {
    echo $help;
    exit(0);
}

$source = trim((string) $options['source']);
$categoryid = (int) $options['category'];

if ($source === '') {
    cli_error('Missing --source.');
}

if ($categoryid <= 0) {
    cli_error(
        'A valid --category=ID is required.'
    );
}

$reader =
    new \local_iliasmigration\migration_reader();

$document = $reader->read($source);

$executor =
    new \local_iliasmigration\phase65_exercise_executor(
        $source
    );

$result = $executor->execute(
    $document,
    $categoryid
);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
);

echo PHP_EOL;

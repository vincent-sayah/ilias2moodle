<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    ['source' => '', 'category' => 0, 'help' => false],
    ['h' => 'help']
);

if ($unrecognized) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

$help = "ILIAS2Moodle Wiki apply\n\n"
    . "php local/iliasmigration/cli/wiki_apply.php "
    . "--source=/path/to/migration.json --category=ID\n\n"
    . "The command revalidates the complete Wiki package and persisted "
    . "Moodle prerequisites immediately before writing.\n";

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
    cli_error('A valid --category=ID is required.');
}

$reader = new \local_iliasmigration\migration_reader();
$document = $reader->read($source);
$executor = new \local_iliasmigration\phase65_wiki_executor($source);

$result = $executor->execute($document, $categoryid);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
echo PHP_EOL;

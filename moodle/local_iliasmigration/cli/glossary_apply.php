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

$help = "ILIAS2Moodle Glossary apply\n\n"
    . "php local/iliasmigration/cli/glossary_apply.php "
    . "--source=/path/to/migration.json --category=ID\n\n"
    . "The command revalidates the complete Glossary package immediately before writing.\n";

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
$executor = new \local_iliasmigration\phase65_glossary_executor($source);
$reconciler = new \local_iliasmigration\phase65_glossary_media_reconciler($source);

// Keep the executor and the embedded-media reconciliation under one outer
// delegated transaction. The executor has its own delegated transaction; Moodle
// defers the final commit until this outer transaction is allowed to commit.
$transaction = $DB->start_delegated_transaction();
try {
    $result = $executor->execute($document, $categoryid);
    $result = $reconciler->reconcile($result);
    $transaction->allow_commit();
} catch (\Throwable $exception) {
    $transaction->rollback($exception);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo PHP_EOL;

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

$help = "ILIAS2Moodle Forum dry-run\n\n"
    . "php local/iliasmigration/cli/forum_dry_run.php "
    . "--source=/path/to/migration.json --category=ID\n\n"
    . "Creates no Moodle content. Authored discussions/posts remain deferred to Phase 7.\n";

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

$plan = (new \local_iliasmigration\phase6_plan_builder($categoryid))->build($document);
$plan['phase'] = '6.5.5';

$plan = (new \local_iliasmigration\phase65_forum_package_validator($source))
    ->validate($plan);

$plan['mode'] = 'dry-run';
$plan['writes_performed'] = false;
$plan['phase'] = '6.5.5';
$plan['phase65_object'] = 'forum';

echo json_encode(
    $plan,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
echo PHP_EOL;

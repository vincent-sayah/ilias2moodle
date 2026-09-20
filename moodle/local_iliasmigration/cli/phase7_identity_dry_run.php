<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'identities' => '',
        'overrides' => '',
        'course' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognized) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

$help = "ILIAS2Moodle Phase 7.1 identity dry-run\n\n"
    . "php local/iliasmigration/cli/phase7_identity_dry_run.php "
    . "--identities=/path/to/phase7_ilias_identities.json "
    . "[--overrides=/path/to/phase7_identity_overrides.json] "
    . "--course=ID\n\n"
    . "Read-only. Resolves ILIAS identities against existing Moodle users, "
    . "applies explicit audited identity overrides, and plans account/enrolment "
    . "actions without creating users or enrolments.\n";

if ($options['help']) {
    echo $help;
    exit(0);
}

$identities = trim((string) $options['identities']);
$overrides = trim((string) $options['overrides']);
$courseid = (int) $options['course'];

if ($identities === '') {
    cli_error('Missing --identities.');
}
if ($courseid <= 0) {
    cli_error('A valid --course=ID is required.');
}

$result = (
    new \local_iliasmigration\phase7_identity_resolver()
)->resolve(
    $identities,
    $courseid,
    $overrides !== '' ? $overrides : null
);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
echo PHP_EOL;

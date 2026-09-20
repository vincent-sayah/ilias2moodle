<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../classes/phase7_identity_resolver.php');
require_once(__DIR__ . '/../classes/phase7_identity_executor.php');

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

$help = "ILIAS2Moodle Phase 7.1 identity apply\n\n"
    . "php local/iliasmigration/cli/phase7_identity_apply.php "
    . "--identities=/path/to/phase7_ilias_identities.json "
    . "[--overrides=/path/to/phase7_identity_overrides.json] "
    . "--course=ID\n\n"
    . "Creates missing Moodle users approved by the Phase 7.1 dry-run, "
    . "persists identity mappings, and enrols source course members using "
    . "the Moodle manual enrolment API. Generated passwords are never printed.\n";

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

try {
    $result = (
        new \local_iliasmigration\phase7_identity_executor()
    )->execute(
        $identities,
        $courseid,
        $overrides !== '' ? $overrides : null
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
        "[PHASE7.1 APPLY ERROR] "
        . get_class($exception)
        . ": "
        . $exception->getMessage()
        . PHP_EOL
    );

    fwrite(
        STDERR,
        $exception->getTraceAsString()
        . PHP_EOL
    );

    exit(1);
}

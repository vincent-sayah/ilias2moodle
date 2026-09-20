<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'group' => '',
        'course' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognized) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

$help = "ILIAS2Moodle Phase 7.2 group dry-run\n\n"
    . "php local/iliasmigration/cli/phase7_group_dry_run.php "
    . "--group=/path/to/phase7_ilias_group.json "
    . "--course=ID\n\n"
    . "Read-only. Resolves a source ILIAS group against Phase 7.1 persistent "
    . "user mappings and current Moodle course enrolments. No group or group "
    . "membership is created.\n";

if ($options['help']) {
    echo $help;
    exit(0);
}

$groupjson = trim((string) $options['group']);
$courseid = (int) $options['course'];

if ($groupjson === '') {
    cli_error('Missing --group.');
}
if ($courseid <= 0) {
    cli_error('A valid --course=ID is required.');
}

try {
    $result = (
        new \local_iliasmigration\phase7_group_resolver()
    )->resolve(
        $groupjson,
        $courseid
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
        "[PHASE7.2 ERROR] "
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

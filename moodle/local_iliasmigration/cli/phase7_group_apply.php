<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../classes/phase7_group_resolver.php');
require_once(__DIR__ . '/../classes/phase7_group_executor.php');

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

$help = "ILIAS2Moodle Phase 7.2 group apply\n\n"
    . "php local/iliasmigration/cli/phase7_group_apply.php "
    . "--group=/path/to/phase7_ilias_group.json "
    . "--course=ID\n\n"
    . "Creates or updates the mapped Moodle group and adds only eligible "
    . "members resolved by Phase 7.1. Group-only source users are not "
    . "automatically enrolled in the target course.\n";

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
        new \local_iliasmigration\phase7_group_executor()
    )->execute(
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
        "[PHASE7.2 APPLY ERROR] "
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

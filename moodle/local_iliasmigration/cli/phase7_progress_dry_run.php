<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../classes/phase7_progress_resolver.php');

[$options, $unrecognized] = cli_get_params(
    [
        'progress' => '',
        'test' => '',
        'scorm719' => '',
        'scorm720' => '',
        'exercise' => '',
        'course' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognized) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

$help = "ILIAS2Moodle Phase 7.3 progress/results dry-run\n\n"
    . "php local/iliasmigration/cli/phase7_progress_dry_run.php "
    . "--progress=/path/progress.json "
    . "--test=/path/test.json "
    . "--scorm719=/path/scorm719.json "
    . "--scorm720=/path/scorm720.json "
    . "--exercise=/path/exercise.json "
    . "--course=ID\n\n"
    . "Read-only classification. No Moodle completion, grades, attempts "
    . "or mapping records are written.\n";

if ($options['help']) {
    echo $help;
    exit(0);
}

$paths = [
    'progress' => trim((string) $options['progress']),
    'test' => trim((string) $options['test']),
    'scorm719' => trim((string) $options['scorm719']),
    'scorm720' => trim((string) $options['scorm720']),
    'exercise' => trim((string) $options['exercise']),
];

foreach ($paths as $name => $path) {
    if ($path === '') {
        cli_error('Missing --' . $name . '.');
    }
}

$courseid = (int) $options['course'];
if ($courseid <= 0) {
    cli_error('A valid --course=ID is required.');
}

try {
    $result = (
        new \local_iliasmigration\phase7_progress_resolver()
    )->resolve(
        $paths['progress'],
        $paths['test'],
        $paths['scorm719'],
        $paths['scorm720'],
        $paths['exercise'],
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
        "[PHASE7.3 DRY-RUN ERROR] "
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

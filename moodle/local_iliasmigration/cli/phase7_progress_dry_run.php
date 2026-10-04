<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../classes/phase7_progress_resolver.php');

[$options, $unrecognized] = cli_get_params(
    [
        'progress' => '',
        'tests' => '',
        'scorms' => '',
        'exercises' => '',
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
    . "Generic usage:\n"
    . "php local/iliasmigration/cli/phase7_progress_dry_run.php "
    . "--progress=/path/progress.json "
    . "[--tests=/path/test1.json,/path/test2.json] "
    . "[--scorms=/path/scorm1.json,/path/scorm2.json] "
    . "[--exercises=/path/exercise1.json,/path/exercise2.json] "
    . "--course=ID\n\n"
    . "Legacy options remain supported: "
    . "--test, --scorm719, --scorm720, --exercise.\n\n"
    . "Read-only classification. No Moodle completion, grades, attempts "
    . "or mapping records are written.\n";

if ($options['help']) {
    echo $help;
    exit(0);
}

$progress = trim((string) $options['progress']);

$splitpaths = static function(string $value): array {
    $value = trim($value);

    if ($value === '') {
        return [];
    }

    $paths = preg_split('/\s*,\s*/', $value);

    return array_values(
        array_unique(
            array_filter(
                array_map(
                    static fn($path): string => trim((string) $path),
                    $paths ?: []
                ),
                static fn($path): bool => $path !== ''
            )
        )
    );
};

$tests = $splitpaths((string) $options['tests']);
$scorms = $splitpaths((string) $options['scorms']);
$exercises = $splitpaths((string) $options['exercises']);

if (!$tests) {
    $legacy = trim((string) $options['test']);
    if ($legacy !== '') {
        $tests[] = $legacy;
    }
}

if (!$scorms) {
    foreach (['scorm719', 'scorm720'] as $name) {
        $legacy = trim((string) $options[$name]);
        if ($legacy !== '') {
            $scorms[] = $legacy;
        }
    }
}

if (!$exercises) {
    $legacy = trim((string) $options['exercise']);
    if ($legacy !== '') {
        $exercises[] = $legacy;
    }
}

if ($progress === '') {
    cli_error('Missing --progress.');
}

$courseid = (int) $options['course'];
if ($courseid <= 0) {
    cli_error('A valid --course=ID is required.');
}

try {
    $result = (
        new \local_iliasmigration\phase7_progress_resolver()
    )->resolve_many(
        $progress,
        $tests,
        $scorms,
        $exercises,
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

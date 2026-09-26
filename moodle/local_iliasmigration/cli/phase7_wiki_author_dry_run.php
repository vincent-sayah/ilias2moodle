<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../classes/phase7_wiki_author_resolver.php');

[$options, $unrecognized] = cli_get_params(
    [
        'source' => '',
        'course' => 0,
        'wiki-ref' => '',
        'help' => false,
    ],
    ['h' => 'help']
);

if ($unrecognized) {
    cli_error('Unknown options: ' . implode(', ', $unrecognized));
}

$help = "ILIAS2Moodle Phase 7.6 Wiki author dry-run\n\n"
    . "php local/iliasmigration/cli/phase7_wiki_author_dry_run.php "
    . "--source=/tmp/phase76_wiki_801_authors.json "
    . "--course=5 --wiki-ref=273\n";

if ($options['help']) {
    echo $help;
    exit(0);
}

$sourcefile = trim((string) $options['source']);
$courseid = (int) $options['course'];
$wikiref = trim((string) $options['wiki-ref']);

if ($sourcefile === '' || !is_file($sourcefile)) {
    cli_error('A valid --source JSON file is required.');
}
if ($courseid <= 0) {
    cli_error('A valid --course=ID is required.');
}
if ($wikiref === '') {
    cli_error('Missing --wiki-ref.');
}

try {
    $raw = file_get_contents($sourcefile);
    if ($raw === false) {
        throw new \coding_exception(
            'Unable to read Wiki author inventory.'
        );
    }

    $source = json_decode(
        $raw,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($source)) {
        throw new \coding_exception(
            'Wiki author inventory must contain a JSON object.'
        );
    }

    $result = (
        new \local_iliasmigration\phase7_wiki_author_resolver()
    )->resolve(
        $source,
        $courseid,
        $wikiref
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
        "[PHASE7.6 WIKI DRY-RUN ERROR] "
        . get_class($exception)
        . ": "
        . $exception->getMessage()
        . PHP_EOL
    );
    fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);
    exit(1);
}

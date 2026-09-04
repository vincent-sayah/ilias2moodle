<?php

// CLI entry point for ILIAS2Moodle Phase 2.
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'source' => '',
        'category' => 0,
        'dry-run' => false,
        'apply' => false,
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error("Unknown options:\n  " . $unrecognized);
}

$help = <<<EOF
ILIAS2Moodle - Phase 2 Moodle structure import

Usage (safe preview):
  php local/iliasmigration/cli/import.php \\
      --source=/path/to/migration.json \\
      --category=ID \\
      --dry-run

Usage (real structure write):
  php local/iliasmigration/cli/import.php \\
      --source=/path/to/migration.json \\
      --category=ID \\
      --apply

Options:
  --source      Absolute path to migration.json.
  --category    Existing Moodle course category id.
  --dry-run     Build the import plan; performs no Moodle content writes.
  --apply       Create/update only the course and first-level sections.
                The course is created hidden. Resources remain deferred.
  -h, --help    Display this help.

Exactly one of --dry-run or --apply is required.

EOF;

if ($options['help']) {
    echo $help;
    exit(0);
}

if (trim((string) $options['source']) === '') {
    cli_error("Missing --source.\n\n" . $help);
}

$categoryid = (int) $options['category'];
if ($categoryid <= 0) {
    cli_error("Missing or invalid --category.\n\n" . $help);
}

$dryrun = (bool) $options['dry-run'];
$apply = (bool) $options['apply'];
if ($dryrun === $apply) {
    cli_error("Choose exactly one of --dry-run or --apply.\n\n" . $help);
}

$importer = new \local_iliasmigration\importer();
$result = $importer->import((string) $options['source'], $categoryid, $dryrun);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo PHP_EOL;

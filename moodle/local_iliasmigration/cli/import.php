<?php

// CLI entry point for ILIAS2Moodle Moodle-side migration.
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'source' => '',
        'category' => 0,
        'phase' => 2,
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
ILIAS2Moodle - Moodle import

Phase 2 preview:
  php local/iliasmigration/cli/import.php \\
      --source=/path/to/migration.json \\
      --category=ID \\
      --phase=2 \\
      --dry-run

Phase 2 real structure write:
  php local/iliasmigration/cli/import.php \\
      --source=/path/to/migration.json \\
      --category=ID \\
      --phase=2 \\
      --apply

Phase 3 resource/package preview (no resource writes yet):
  php local/iliasmigration/cli/import.php \\
      --source=/path/to/migration.json \\
      --category=ID \\
      --phase=3 \\
      --dry-run

Options:
  --source      Absolute path to migration.json.
  --category    Existing Moodle course category id.
  --phase       Migration phase: 2 (structure) or 3 (simple resources). Default: 2.
  --dry-run     Build and validate the import plan; performs no Moodle content writes.
  --apply       Phase 2 only: create/update the hidden course, sections and subsections.
                Phase 3 apply remains disabled until its POC dry-run is validated.
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

$phase = (int) $options['phase'];
if (!in_array($phase, [2, 3], true)) {
    cli_error("Invalid --phase. Use 2 or 3.\n\n" . $help);
}

$dryrun = (bool) $options['dry-run'];
$apply = (bool) $options['apply'];
if ($dryrun === $apply) {
    cli_error("Choose exactly one of --dry-run or --apply.\n\n" . $help);
}

$importer = new \local_iliasmigration\importer();
$result = $importer->import((string) $options['source'], $categoryid, $dryrun, $phase);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo PHP_EOL;

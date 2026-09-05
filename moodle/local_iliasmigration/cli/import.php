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

Phase 3 resource/package preview:
  php local/iliasmigration/cli/import.php \\
      --source=/path/to/migration.json \\
      --category=ID \\
      --phase=3 \\
      --dry-run

Phase 3 real simple-resource write:
  php local/iliasmigration/cli/import.php \\
      --source=/path/to/migration.json \\
      --category=ID \\
      --phase=3 \\
      --apply

Phase 4 SCORM/package preview:
  php local/iliasmigration/cli/import.php \\
      --source=/path/to/migration.json \\
      --category=ID \\
      --phase=4 \\
      --dry-run

Phase 4 --apply is intentionally disabled until the SCORM POC package and
Moodle mod_scorm creation/update path have been validated.

Options:
  --source      Absolute path to migration.json.
  --category    Existing Moodle course category id.
  --phase       Migration phase: 2 (structure), 3 (simple resources),
                or 4 (SCORM dry-run). Default: 2.
  --dry-run     Build and validate the import plan; performs no Moodle content writes.
  --apply       Apply the selected supported phase.
                Phase 3 requires Phase 2 structure to exist and package validation to pass.
                Phase 4 apply is not enabled yet.
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
if (!in_array($phase, [2, 3, 4], true)) {
    cli_error("Invalid --phase. Use 2, 3 or 4.\n\n" . $help);
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

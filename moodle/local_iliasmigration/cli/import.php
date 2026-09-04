<?php

// CLI dry-run entry point for ILIAS2Moodle Phase 2.
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'source' => '',
        'category' => 0,
        'dry-run' => false,
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
ILIAS2Moodle - Phase 2 Moodle structure dry-run

Usage:
  php local/iliasmigration/cli/import.php \\
      --source=/path/to/migration.json \\
      --category=ID \\
      --dry-run

Options:
  --source      Absolute path to migration.json.
  --category    Existing Moodle course category id.
  --dry-run     Required in the current alpha. Performs no Moodle content writes.
  -h, --help    Display this help.

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

if (!$options['dry-run']) {
    cli_error('Phase 2 alpha refuses to run without --dry-run.');
}

$importer = new \local_iliasmigration\importer();
$plan = $importer->import((string) $options['source'], $categoryid, true);

echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo PHP_EOL;

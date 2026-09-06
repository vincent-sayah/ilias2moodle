<?php

// CLI entry point for global ILIAS -> Moodle course order reconciliation.
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'source' => '',
        'dry-run' => false,
        'apply' => false,
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    cli_error("Unknown options:\n  " . implode("\n  ", $unrecognized));
}

$help = <<<EOF
ILIAS2Moodle - global course order reconciliation

Read-only preview:
  php local/iliasmigration/cli/reconcile_order.php \\
      --source=/path/to/migration.json \\
      --dry-run

Guarded apply:
  php local/iliasmigration/cli/reconcile_order.php \\
      --source=/path/to/migration.json \\
      --apply

The command uses migration.json plus local_iliasmigration_map to restore the
visible ILIAS order after Phases 2-6. It never recreates pedagogical content.
Unmanaged Moodle activities are preserved. Moodle qbank modules remain in
section 0 because they are non-displayable by design.

First guarded release supports:
- root activities before the first ILIAS folder -> Moodle section 0;
- first-level ILIAS folders -> existing Moodle sections;
- second-level folders -> existing mod_subsection delegated sections;
- root activity runs after the final first-level folder -> synthetic Moodle
  section(s) named Contenu / Contenu N;
- exact mapped activity order inside each managed section.

It intentionally refuses:
- folder depth > 2;
- a synthetic root activity run between two first-level source folders;
- a changed order of existing first-level source sections.

Exactly one of --dry-run or --apply is required.

EOF;

if ($options['help']) {
    echo $help;
    exit(0);
}

$source = trim((string) $options['source']);
if ($source === '') {
    cli_error("Missing --source.\n\n" . $help);
}

$dryrun = (bool) $options['dry-run'];
$apply = (bool) $options['apply'];
if ($dryrun === $apply) {
    cli_error("Choose exactly one of --dry-run or --apply.\n\n" . $help);
}

$reader = new \local_iliasmigration\migration_reader();
$document = $reader->read($source);
$reconciler = new \local_iliasmigration\order_reconciler($source);

$result = $dryrun
    ? $reconciler->build($document)
    : $reconciler->execute($document);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
echo PHP_EOL;

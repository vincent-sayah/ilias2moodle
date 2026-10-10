<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'zip' => null,
        'output-name' => null,
        'exercise-irss-recovery' => null,
        'mediacast-media-recovery' => null,
        'mediaobject-recovery' => null,
        'forum-attachment-recovery' => null,
        'wiki-content-recovery' => null,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(
        "Options inconnues :\n  " . $unrecognized
    );
}

$help = <<<'HELP'
Préparer un package ILIAS2Moodle depuis un export ZIP natif ILIAS.

Options obligatoires :
  --zip=/var/moodledata/ilias2moodle/imports/course.zip
  --output-name=course827

Options de récupération complémentaires :
  --exercise-irss-recovery=/chemin/irss
  --mediacast-media-recovery=/chemin/mediacast
  --mediaobject-recovery=/chemin/mediaobjects
  --forum-attachment-recovery=/chemin/forum
  --wiki-content-recovery=/chemin/wiki

Configuration Moodle utilisée :
  local_iliasmigration/projectroot
  local_iliasmigration/importsroot
  local_iliasmigration/packagesroot
  local_iliasmigration/iliasversion

Le worker réutilise tools/run-ilias2moodle.sh prepare-export.
Il ne réimplémente aucun parseur ILIAS côté PHP.

HELP;

if (!empty($options['help'])) {
    echo $help;
    exit(0);
}

$zippath = trim((string) ($options['zip'] ?? ''));
$outputname = trim((string) ($options['output-name'] ?? ''));

if ($zippath === '' || $outputname === '') {
    cli_error(
        "--zip et --output-name sont obligatoires.\n\n"
        . $help
    );
}

$recoveries = [];
foreach ([
    'exercise-irss-recovery' => 'exercise_irss_recovery',
    'mediacast-media-recovery' => 'mediacast_media_recovery',
    'mediaobject-recovery' => 'mediaobject_recovery',
    'forum-attachment-recovery' => 'forum_attachment_recovery',
    'wiki-content-recovery' => 'wiki_content_recovery',
] as $option => $key) {
    $value = trim((string) ($options[$option] ?? ''));
    if ($value !== '') {
        $recoveries[$key] = $value;
    }
}

try {
    $preparer = new \local_iliasmigration\operator_package_preparer();
    $result = $preparer->prepare(
        $zippath,
        $outputname,
        $recoveries
    );

    echo json_encode(
        $result,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (Throwable $exception) {
    cli_error($exception->getMessage());
}

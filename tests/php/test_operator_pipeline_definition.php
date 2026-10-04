<?php

define('MOODLE_INTERNAL', true);

function get_string(string $key, string $component): string {
    return $component . ':' . $key;
}

require_once(
    __DIR__
    . '/../../moodle/local_iliasmigration/classes/operator_pipeline.php'
);

$steps = \local_iliasmigration\operator_pipeline::step_definitions();

$expected = [
    'structure',
    'resources',
    'scorm',
    'learning_modules',
    'questions_quiz',
    'content_pages',
    'glossaries',
    'wikis',
    'exercises',
    'forums',
    'mediacasts',
    'blogs',
    'media_pools',
    'item_groups',
];

if (array_keys($steps) !== $expected) {
    fwrite(STDERR, "Operator pipeline order regression failed.\n");
    exit(1);
}

foreach ($steps as $key => $definition) {
    if (trim((string) ($definition['label'] ?? '')) === '') {
        fwrite(STDERR, "Missing operator label for {$key}.\n");
        exit(1);
    }

    if (!is_array($definition['types'] ?? null)) {
        fwrite(STDERR, "Missing operator type map for {$key}.\n");
        exit(1);
    }
}

echo "OPERATOR_PIPELINE_DEFINITION_OK\n";

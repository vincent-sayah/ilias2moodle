<?php

$path = __DIR__
    . '/../../moodle/local_iliasmigration/classes/operator_run_manager.php';

$source = file_get_contents($path);

if ($source === false) {
    fwrite(STDERR, "Unable to read operator_run_manager.php\n");
    exit(1);
}

$checks = [
    'assert_source_unchanged($run);',
    'SOURCE_CHANGED_DURING_RUN',
    "hash_file('sha256', \$sourcepath)",
    'hash_equals($expected, strtolower($actual))',
];

foreach ($checks as $check) {
    if (!str_contains($source, $check)) {
        fwrite(
            STDERR,
            "Missing operator source integrity guard: {$check}\n"
        );
        exit(1);
    }
}

echo "OPERATOR_SOURCE_INTEGRITY_GUARD_OK\n";

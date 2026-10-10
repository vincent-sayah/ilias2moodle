<?php

$path = __DIR__
    . '/../../moodle/local_iliasmigration/classes/operator_run_manager.php';

$source = file_get_contents($path);

if ($source === false) {
    fwrite(STDERR, "Unable to read operator_run_manager.php\n");
    exit(1);
}

$checks = [
    'assert_source_not_already_migrated(',
    "'targettype' => 'course'",
    "record_exists('course', ['id' => \$targetid])",
    "'coursealreadymigrated'",
    "'courseorphanedmapping'",
];

foreach ($checks as $check) {
    if (!str_contains($source, $check)) {
        fwrite(
            STDERR,
            "Missing duplicate migration guard: {$check}\n"
        );
        exit(1);
    }
}

echo "OPERATOR_DUPLICATE_COURSE_GUARD_OK\n";

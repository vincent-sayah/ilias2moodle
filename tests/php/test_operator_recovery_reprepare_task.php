<?php

$root = dirname(__DIR__, 2);

$workerpath = $root
    . '/moodle/local_iliasmigration/classes/operator_recovery_reprepare_worker.php';
$taskpath = $root
    . '/moodle/local_iliasmigration/classes/task/recovery_reprepare_task.php';
$taskconfigpath = $root
    . '/moodle/local_iliasmigration/db/tasks.php';
$versionpath = $root
    . '/moodle/local_iliasmigration/version.php';

foreach ([
    $workerpath,
    $taskpath,
    $taskconfigpath,
    $versionpath,
] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
}

$worker = file_get_contents($workerpath);
$task = file_get_contents($taskpath);
$taskconfig = file_get_contents($taskconfigpath);
$version = file_get_contents($versionpath);

foreach ([
    "final class operator_recovery_reprepare_worker",
    "automatic_recovery_reprepare",
    "WAITING_BUNDLE",
    "SKIPPED_FAILED_SAME_INPUT",
    "_recovery.tar.gz",
    "previous_missing_count",
    "archive_bundle(",
    "processed",
    "reprepare-state",
    "hash_equals(",
    "operator_recovery_bundle_importer",
    "operator_recovery_queue",
] as $check) {
    if (!str_contains($worker, $check)) {
        fwrite(
            STDERR,
            "Missing automatic reprepare behavior: {$check}\n"
        );
        exit(1);
    }
}

foreach ([
    "extends \\core\\task\\scheduled_task",
    "operator_recovery_reprepare_worker",
    "failed_count",
    "mtrace(",
] as $check) {
    if (!str_contains($task, $check)) {
        fwrite(
            STDERR,
            "Missing scheduled task behavior: {$check}\n"
        );
        exit(1);
    }
}

foreach ([
    "recovery_reprepare_task",
    "'minute' => '*'",
    "'blocking' => 0",
] as $check) {
    if (!str_contains($taskconfig, $check)) {
        fwrite(
            STDERR,
            "Missing scheduled task configuration: {$check}\n"
        );
        exit(1);
    }
}

if (!str_contains(
    $version,
    "0.22.0-beta6"
)) {
    fwrite(
        STDERR,
        "Automatic reprepare task must bump beta version.\n"
    );
    exit(1);
}

echo "OPERATOR_RECOVERY_REPREPARE_TASK_OK\n";

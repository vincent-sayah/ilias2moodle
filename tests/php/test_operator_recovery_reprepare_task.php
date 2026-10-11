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

foreach ([$workerpath, $taskpath, $taskconfigpath, $versionpath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
}

$worker = file_get_contents($workerpath);
$task = file_get_contents($taskpath);
$taskconfig = file_get_contents($taskconfigpath);
$version = file_get_contents($versionpath);

if (!str_contains($worker, 'final class operator_recovery_reprepare_worker')
        || !str_contains($task, 'extends \\core\\task\\scheduled_task')) {
    fwrite(STDERR, "Historical automatic recovery implementation must remain available.\n");
    exit(1);
}

if (!str_contains($taskconfig, '$tasks = [];')) {
    fwrite(STDERR, "Automatic recovery scheduled task must be disabled.\n");
    exit(1);
}

if (str_contains($taskconfig, 'recovery_reprepare_task')) {
    fwrite(STDERR, "Automatic recovery task is still scheduled.\n");
    exit(1);
}

if (!str_contains($version, '0.22.0-beta9')) {
    fwrite(STDERR, "Manual operator workflow must use beta9.\n");
    exit(1);
}

echo "OPERATOR_RECOVERY_REPREPARE_DISABLED_OK\n";

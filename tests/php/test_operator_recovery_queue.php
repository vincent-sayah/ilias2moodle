<?php

$root = dirname(__DIR__, 2);

$queuepath = $root
    . '/moodle/local_iliasmigration/classes/operator_recovery_queue.php';
$preparepath = $root
    . '/moodle/local_iliasmigration/prepare.php';
$recoverpath = $root
    . '/moodle/local_iliasmigration/recover.php';
$versionpath = $root
    . '/moodle/local_iliasmigration/version.php';

foreach ([$queuepath, $preparepath, $recoverpath, $versionpath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
}

$queue = file_get_contents($queuepath);
$prepare = file_get_contents($preparepath);
$recover = file_get_contents($recoverpath);
$version = file_get_contents($versionpath);

if (!str_contains($queue, 'final class operator_recovery_queue')) {
    fwrite(STDERR, "Historical recovery queue implementation must remain available.\n");
    exit(1);
}

foreach (['operator_recovery_queue', 'recoveryqueued'] as $forbidden) {
    if (str_contains($prepare, $forbidden)
            || str_contains($recover, $forbidden)) {
        fwrite(STDERR, "Automatic recovery queue still wired into manual UI.\n");
        exit(1);
    }
}

if (!str_contains($version, '0.22.0-beta9')) {
    fwrite(STDERR, "Manual operator workflow must use beta9.\n");
    exit(1);
}

echo "OPERATOR_RECOVERY_QUEUE_DISABLED_OK\n";

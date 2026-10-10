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

foreach ([
    $queuepath,
    $preparepath,
    $recoverpath,
    $versionpath,
] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
}

$queue = file_get_contents($queuepath);
$prepare = file_get_contents($preparepath);
$recover = file_get_contents($recoverpath);
$version = file_get_contents($versionpath);

foreach ([
    "final class operator_recovery_queue",
    "REQUESTS_DIRECTORY",
    "public function sync(",
    "public function remove(",
    "plan_sha256",
    "hash_file(",
    "JSON_THROW_ON_ERROR",
    "rename(\$tmp, \$requestpath)",
    "unresolved_count",
] as $check) {
    if (!str_contains($queue, $check)) {
        fwrite(
            STDERR,
            "Missing recovery queue guard: {$check}\n"
        );
        exit(1);
    }
}

foreach ([
    "operator_recovery_queue",
    "->sync(",
    "recoveryqueued",
] as $check) {
    if (!str_contains($prepare, $check)) {
        fwrite(
            STDERR,
            "Missing prepare recovery queue behavior: {$check}\n"
        );
        exit(1);
    }
}

foreach ([
    "operator_recovery_queue",
    "->sync(",
] as $check) {
    if (!str_contains($recover, $check)) {
        fwrite(
            STDERR,
            "Missing recovery queue cleanup behavior: {$check}\n"
        );
        exit(1);
    }
}

if (!str_contains(
    $version,
    "0.22.0-beta4"
)) {
    fwrite(
        STDERR,
        "Automatic recovery queue must bump beta version.\n"
    );
    exit(1);
}

echo "OPERATOR_RECOVERY_QUEUE_OK\n";

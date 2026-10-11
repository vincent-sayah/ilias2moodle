<?php

$root = dirname(__DIR__, 2);

$queuepath = $root
    . '/moodle/local_iliasmigration/classes/operator_phase7_identity_queue.php';
$executorpath = $root
    . '/moodle/local_iliasmigration/classes/phase7_identity_executor.php';
$preparepath = $root
    . '/moodle/local_iliasmigration/prepare.php';
$recoverpath = $root
    . '/moodle/local_iliasmigration/recover.php';
$pipelinepath = $root
    . '/moodle/local_iliasmigration/classes/operator_pipeline.php';
$versionpath = $root
    . '/moodle/local_iliasmigration/version.php';

foreach ([
    $queuepath,
    $executorpath,
    $preparepath,
    $recoverpath,
    $pipelinepath,
    $versionpath,
] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
}

$queue = file_get_contents($queuepath);
$executor = file_get_contents($executorpath);
$prepare = file_get_contents($preparepath);
$recover = file_get_contents($recoverpath);
$pipeline = file_get_contents($pipelinepath);
$version = file_get_contents($versionpath);

if (!str_contains($queue, 'final class operator_phase7_identity_queue')
        || !str_contains($executor, 'final class phase7_identity_executor')) {
    fwrite(STDERR, "Historical Phase 7.1 implementation must remain available.\n");
    exit(1);
}

foreach ([
    "phase7_identities' =>",
    "require_identity_for_migration(",
] as $forbidden) {
    if (str_contains($pipeline, $forbidden)) {
        fwrite(STDERR, "Phase 7.1 must not be enabled in the manual default pipeline.\n");
        exit(1);
    }
}

foreach ([
    'operator_phase7_identity_queue',
    'phase7identityqueued',
    'phase7identitywaitmigration',
] as $forbidden) {
    if (str_contains($prepare, $forbidden)
            || str_contains($recover, $forbidden)) {
        fwrite(STDERR, "Phase 7.1 automatic queue still wired into manual UI.\n");
        exit(1);
    }
}

if (!str_contains($version, '0.22.0-beta9')) {
    fwrite(STDERR, "Manual operator workflow must use beta9.\n");
    exit(1);
}

echo "OPERATOR_PHASE7_IDENTITY_MANUAL_OK\n";

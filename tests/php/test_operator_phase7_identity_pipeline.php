<?php

$root = dirname(__DIR__, 2);

$queuepath = $root
    . '/moodle/local_iliasmigration/classes/operator_phase7_identity_queue.php';
$preparepath = $root
    . '/moodle/local_iliasmigration/prepare.php';
$recoverpath = $root
    . '/moodle/local_iliasmigration/recover.php';
$pipelinepath = $root
    . '/moodle/local_iliasmigration/classes/operator_pipeline.php';
$servicepath = $root
    . '/deploy/systemd/ilias2moodle-recovery-worker.service';
$workerpath = $root
    . '/tools/phase7-identity-worker.py';
$receiverpath = $root
    . '/tools/receive-recovery-bundle.sh';
$versionpath = $root
    . '/moodle/local_iliasmigration/version.php';

foreach ([
    $queuepath,
    $preparepath,
    $recoverpath,
    $pipelinepath,
    $servicepath,
    $workerpath,
    $receiverpath,
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
$pipeline = file_get_contents($pipelinepath);
$service = file_get_contents($servicepath);
$worker = file_get_contents($workerpath);
$receiver = file_get_contents($receiverpath);
$version = file_get_contents($versionpath);

foreach ([
    "final class operator_phase7_identity_queue",
    "identity-requests",
    "identities",
    "migration_sha256",
    "course_ref",
    "phase7_identity",
    "require_identity_for_migration(",
    "identity_is_valid(",
] as $check) {
    if (!str_contains($queue, $check)) {
        fwrite(
            STDERR,
            "Missing Phase 7.1 identity queue behavior: {$check}\n"
        );
        exit(1);
    }
}

foreach ([
    "operator_phase7_identity_queue",
    "->sync(",
    "phase7identityqueued",
    "phase7identityready",
    "phase7identitywaitmigration",
] as $check) {
    if (!str_contains($prepare, $check)) {
        fwrite(
            STDERR,
            "Missing Phase 7.1 prepare UI behavior: {$check}\n"
        );
        exit(1);
    }
}

foreach ([
    "operator_phase7_identity_queue",
    "phase7_identities",
    "phase7_identity_executor",
    "require_identity_for_migration(",
    "targettype' => 'course'",
] as $check) {
    if (!str_contains($pipeline, $check)) {
        fwrite(
            STDERR,
            "Missing Phase 7.1 operator pipeline behavior: {$check}\n"
        );
        exit(1);
    }
}

if (!str_contains(
    $recover,
    "operator_phase7_identity_queue"
)) {
    fwrite(
        STDERR,
        "Manual recovery must refresh Phase 7.1 identity state.\n"
    );
    exit(1);
}

foreach ([
    "phase7-identity-worker.py",
    "ILIAS_CLIENT_ID",
] as $check) {
    if (!str_contains($service, $check)) {
        fwrite(
            STDERR,
            "Systemd worker does not launch Phase 7.1 identity worker: {$check}\n"
        );
        exit(1);
    }
}

foreach ([
    "list_pending_identity_jobs",
    "ilias_course_identity_extract.php",
    "publish_identity_document",
    "course_ref",
    "client_id",
    "SKIPPED_FAILED_SAME_REQUEST",
] as $check) {
    if (!str_contains($worker, $check)) {
        fwrite(
            STDERR,
            "Missing Phase 7.1 source worker behavior: {$check}\n"
        );
        exit(1);
    }
}

foreach ([
    "publish-identity",
    "list-identities",
    "identity-requests",
    "list-phase7-identity-queue.py",
] as $check) {
    if (!str_contains($receiver, $check)) {
        fwrite(
            STDERR,
            "Missing Phase 7.1 forced-command behavior: {$check}\n"
        );
        exit(1);
    }
}

if (!str_contains(
    $version,
    "0.22.0-beta7"
)) {
    fwrite(
        STDERR,
        "Phase 7.1 operator integration must bump beta version.\n"
    );
    exit(1);
}

echo "OPERATOR_PHASE7_IDENTITY_PIPELINE_OK\n";

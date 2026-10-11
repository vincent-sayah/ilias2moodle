<?php

$root = dirname(__DIR__, 2);

$pipelinepath = $root
    . '/moodle/local_iliasmigration/classes/operator_pipeline.php';
$executorpath = $root
    . '/moodle/local_iliasmigration/classes/phase7_forum_contribution_executor.php';
$resolverpath = $root
    . '/moodle/local_iliasmigration/classes/phase7_forum_contribution_resolver.php';
$versionpath = $root
    . '/moodle/local_iliasmigration/version.php';

foreach ([
    $pipelinepath,
    $executorpath,
    $resolverpath,
    $versionpath,
] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
}

$pipeline = file_get_contents($pipelinepath);
$executor = file_get_contents($executorpath);
$resolver = file_get_contents($resolverpath);
$version = file_get_contents($versionpath);

foreach ([
    "start_delegated_transaction()",
    "forum_add_discussion(",
    "forum_add_new_post(",
    "'forumdiscussion'",
    "'forumpost'",
] as $check) {
    if (!str_contains($executor, $check)) {
        fwrite(STDERR, "Phase 7.4 executor regression: {$check}\n");
        exit(1);
    }
}

foreach ([
    "persistent_user_mapping_required",
    "notification_risk",
    "ready_for_apply",
] as $check) {
    if (!str_contains($resolver, $check)) {
        fwrite(STDERR, "Phase 7.4 resolver regression: {$check}\n");
        exit(1);
    }
}

if (str_contains($pipeline, "phase7_forum_contributions' =>")) {
    fwrite(STDERR, "Phase 7.4 must not be enabled in the manual default pipeline.\n");
    exit(1);
}

if (!str_contains($version, '0.22.0-beta9')) {
    fwrite(STDERR, "Manual operator workflow must use beta9.\n");
    exit(1);
}

echo "OPERATOR_PHASE7_FORUM_MANUAL_OK\n";

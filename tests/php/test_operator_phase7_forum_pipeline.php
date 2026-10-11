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
    "phase7_forum_contributions",
    "execute_phase7_forum_contributions(",
    "phase7_forum_contribution_executor",
    "collect_source_refs_by_type(",
    "'forum_count'",
    "'apply_counts'",
    "'forum_results'",
] as $check) {
    if (!str_contains($pipeline, $check)) {
        fwrite(
            STDERR,
            "Missing Phase 7.4 operator behavior: {$check}\n"
        );
        exit(1);
    }
}

foreach ([
    "start_delegated_transaction()",
    "forum_add_discussion(",
    "forum_add_new_post(",
    "sync_post_attachments(",
    "save_mapping(",
    "'forumdiscussion'",
    "'forumpost'",
    "'writes_performed'",
] as $check) {
    if (!str_contains($executor, $check)) {
        fwrite(
            STDERR,
            "Phase 7.4 executor regression: {$check}\n"
        );
        exit(1);
    }
}

foreach ([
    "persistent_user_mapping_required",
    "notification_risk",
    "ready_for_apply",
    "target_course_enrolment_required_for_historical_author",
] as $check) {
    if (!str_contains($resolver, $check)) {
        fwrite(
            STDERR,
            "Phase 7.4 resolver regression: {$check}\n"
        );
        exit(1);
    }
}

if (!str_contains(
    $version,
    "0.22.0-beta8"
)) {
    fwrite(
        STDERR,
        "Phase 7.4 integration must bump beta version.\n"
    );
    exit(1);
}

echo "OPERATOR_PHASE7_FORUM_PIPELINE_OK\n";

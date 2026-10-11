<?php

$root = dirname(__DIR__, 2);

$paths = [
    'index' => $root . '/moodle/local_iliasmigration/index.php',
    'prepare' => $root . '/moodle/local_iliasmigration/prepare.php',
    'recover' => $root . '/moodle/local_iliasmigration/recover.php',
    'artifacts' => $root . '/moodle/local_iliasmigration/classes/operator_artifact_manager.php',
    'upload' => $root . '/moodle/local_iliasmigration/upload_export.php',
    'delete_export' => $root . '/moodle/local_iliasmigration/delete_export.php',
    'delete_package' => $root . '/moodle/local_iliasmigration/delete_package.php',
];

foreach ($paths as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
}

$index = file_get_contents($paths['index']);
$prepare = file_get_contents($paths['prepare']);
$recover = file_get_contents($paths['recover']);
$artifacts = file_get_contents($paths['artifacts']);

foreach ([
    "operator_artifact_manager",
    "/local/iliasmigration/upload_export.php",
    "/local/iliasmigration/delete_export.php",
    "/local/iliasmigration/delete_package.php",
    "available_packages()",
    "packagename",
    "package_migration_path(",
    "exportsmanagement",
    "packagesmanagement",
] as $check) {
    if (!str_contains($index, $check)) {
        fwrite(STDERR, "Missing manual console behavior: {$check}\n");
        exit(1);
    }
}

foreach ([
    "recovery_required",
    "extractor_command_template",
    "manualrecoverycommands",
    "<ILIAS_ROOT>",
    "<ILIAS_CLIENT_ID>",
    "<RECOVERY_ROOT>",
    "/local/iliasmigration/recover.php",
    "recoverybundle",
    "'package' => $outputname",
] as $check) {
    if (!str_contains($prepare, $check)) {
        fwrite(STDERR, "Missing manual recovery UI behavior: {$check}\n");
        exit(1);
    }
}

foreach ([
    "operator_recovery_queue",
    "operator_phase7_identity_queue",
    "recoveryqueued",
    "phase7identityqueued",
] as $forbidden) {
    if (str_contains($prepare, $forbidden)
            || str_contains($recover, $forbidden)) {
        fwrite(STDERR, "Automatic workflow still wired in UI: {$forbidden}\n");
        exit(1);
    }
}

foreach ([
    "upload_export(",
    "delete_export(",
    "available_packages(",
    "package_migration_path(",
    "delete_package(",
    "is_uploaded_file(",
    "move_uploaded_file(",
    "PK\\x03\\x04",
    "local_iliasmigration_run",
] as $check) {
    if (!str_contains($artifacts, $check)) {
        fwrite(STDERR, "Missing artifact manager guard: {$check}\n");
        exit(1);
    }
}

echo "OPERATOR_PREPARE_UI_OK\n";

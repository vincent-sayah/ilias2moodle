<?php

$root = dirname(__DIR__, 2);

$servicepath = $root
    . '/moodle/local_iliasmigration/classes/operator_recovery_bundle_importer.php';
$recoverpath = $root
    . '/moodle/local_iliasmigration/recover.php';
$preparepath = $root
    . '/moodle/local_iliasmigration/prepare.php';
$settingspath = $root
    . '/moodle/local_iliasmigration/settings.php';
$versionpath = $root
    . '/moodle/local_iliasmigration/version.php';

foreach ([
    $servicepath,
    $recoverpath,
    $preparepath,
    $settingspath,
    $versionpath,
] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
}

$service = file_get_contents($servicepath);
$recover = file_get_contents($recoverpath);
$prepare = file_get_contents($preparepath);
$settings = file_get_contents($settingspath);
$version = file_get_contents($versionpath);

foreach ([
    "import_and_reprepare(",
    "operator_package_preparer",
    "'source_version'",
    "MAX_BUNDLE_BYTES",
    "MAX_ARCHIVE_ENTRIES",
    "expected_manifest",
    "recovery_option",
    "safe_extract_bundle(",
    "validate_archive_entry(",
    "'--no-same-owner'",
    "'--no-same-permissions'",
    "proc_open(",
    "previous_missing_count",
    "assert_package_not_in_use(",
    "lock_config::get_lock_factory",
    "Recovery bundle did not reduce",
    "rename($output, $backup)",
    "reprepared_existing",
] as $check) {
    if (!str_contains($service, $check)) {
        fwrite(STDERR, "Missing recovery bundle guard: {$check}\n");
        exit(1);
    }
}

foreach (["shell_exec(", "exec(", "system(", "passthru("] as $forbidden) {
    if (str_contains($service, $forbidden)) {
        fwrite(STDERR, "Recovery importer invokes shell string: {$forbidden}\n");
        exit(1);
    }
}

foreach ([
    "require_sesskey();",
    "$_FILES['recoverybundle']",
    "UPLOAD_ERR_OK",
    "is_uploaded_file($tmpname)",
    "operator_recovery_bundle_importer",
    "import_and_reprepare(",
    "'package' => $outputname",
    "showremainingcommands",
] as $check) {
    if (!str_contains($recover, $check)) {
        fwrite(STDERR, "Missing manual recovery upload behavior: {$check}\n");
        exit(1);
    }
}

foreach ([
    "/local/iliasmigration/recover.php",
    "'enctype' => 'multipart/form-data'",
    "'name' => 'recoverybundle'",
    "recoverybundleaction",
    "extractor_command_template",
    "manualrecoverycommands",
    "tar -C ",
] as $check) {
    if (!str_contains($prepare, $check)) {
        fwrite(STDERR, "Missing manual recovery preparation behavior: {$check}\n");
        exit(1);
    }
}

foreach ([
    "local_iliasmigration/recoveriesroot",
    "local_iliasmigration/sourceprojectroot",
    "local_iliasmigration/sourceiliasroot",
    "local_iliasmigration/sourceclient",
] as $check) {
    if (!str_contains($settings, $check)) {
        fwrite(STDERR, "Missing manual recovery setting: {$check}\n");
        exit(1);
    }
}

if (!str_contains($version, "0.22.0-beta9")) {
    fwrite(STDERR, "Manual recovery workflow must use beta9.\n");
    exit(1);
}

echo "OPERATOR_RECOVERY_BUNDLE_OK\n";

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

$servicechecks = [
    "available_server_bundles()",
    "SERVER_BUNDLES_DIRECTORY",
    "is_bundle_filename(",
    "is_link(\$candidate)",
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
    "non-regular archive entry",
    "parent path traversal",
    "symbolic link",
    "previous_missing_count",
    "assert_package_not_in_use(",
    "recovery_bundle_import_",
    "lock_config::get_lock_factory",
    "local_iliasmigration_run",
    "already referenced by an operator run",
    "Recovery bundle did not reduce",
    "rename(\$output, \$backup)",
    "\$preparedroot",
    "reprepared_existing",
];

foreach ($servicechecks as $check) {
    if (!str_contains($service, $check)) {
        fwrite(
            STDERR,
            "Missing recovery bundle guard: {$check}\n"
        );
        exit(1);
    }
}

foreach ([
    "shell_exec(",
    "exec(",
    "system(",
    "passthru(",
] as $forbidden) {
    if (str_contains($service, $forbidden)) {
        fwrite(
            STDERR,
            "Recovery bundle importer must not invoke shell strings: {$forbidden}\n"
        );
        exit(1);
    }
}

$recoverchecks = [
    "require_sesskey();",
    "\$_FILES['recoverybundle']",
    "optional_param(",
    "'serverbundle'",
    "PARAM_FILE",
    "UPLOAD_ERR_OK",
    "UPLOAD_ERR_NO_FILE",
    "is_uploaded_file(\$tmpname)",
    "available_server_bundles()",
    "array_key_exists(",
    "recoverybundlechooseone",
    "operator_recovery_bundle_importer",
    "import_and_reprepare(",
    "usepreparedpackage",
];

foreach ($recoverchecks as $check) {
    if (!str_contains($recover, $check)) {
        fwrite(
            STDERR,
            "Missing recovery upload behavior: {$check}\n"
        );
        exit(1);
    }
}

$preparechecks = [
    "/local/iliasmigration/recover.php",
    "'enctype' => 'multipart/form-data'",
    "'serverbundle',",
    "available_server_bundles()",
    "recoverybundleserver",
    "'name' => 'recoverybundle'",
    "recoverybundleaction",
    "'zipname' => \$zipname",
    "'outputname' => \$outputname",
];

foreach ($preparechecks as $check) {
    if (!str_contains($prepare, $check)) {
        fwrite(
            STDERR,
            "Missing recovery form behavior: {$check}\n"
        );
        exit(1);
    }
}

if (!str_contains(
    $settings,
    "local_iliasmigration/recoveriesroot"
)) {
    fwrite(
        STDERR,
        "Missing recoveriesroot setting.\n"
    );
    exit(1);
}

if (!str_contains(
    $version,
    "0.22.0-beta6"
)) {
    fwrite(
        STDERR,
        "Automatic reprepare task must retain the current plugin beta version.\n"
    );
    exit(1);
}

echo "OPERATOR_RECOVERY_BUNDLE_OK\n";

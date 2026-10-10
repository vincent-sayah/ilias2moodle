<?php

$root = dirname(__DIR__, 2);

$servicepath = $root
    . '/moodle/local_iliasmigration/classes/operator_package_preparer.php';
$clipath = $root
    . '/moodle/local_iliasmigration/cli/prepare_package.php';
$settingspath = $root
    . '/moodle/local_iliasmigration/settings.php';

foreach ([$servicepath, $clipath, $settingspath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
}

$service = file_get_contents($servicepath);
$cli = file_get_contents($clipath);
$settings = file_get_contents($settingspath);

$servicechecks = [
    "tools",
    "run-ilias2moodle.sh",
    "'prepare-export'",
    "'--zip'",
    "'--output'",
    "'--ilias-version'",
    "proc_open(",
    "is_inside(\$zip, \$importsroot)",
    "reuse_existing_package(",
    "'source_archive'",
    "'reused_existing' => true",
    "'recovery_required' => \$missingcount > 0",
    "Existing prepared package belongs to a different ILIAS ZIP",
    "Existing prepared package is incomplete",
    "Only native ILIAS ZIP exports are accepted",
    "Unsafe package output name",
    "migration.json",
    "recovery-plan.json",
    "recovery_required",
    "JSON_THROW_ON_ERROR",
];

foreach ($servicechecks as $check) {
    if (!str_contains($service, $check)) {
        fwrite(
            STDERR,
            "Missing package preparation guard: {$check}\n"
        );
        exit(1);
    }
}

if (str_contains($service, "Prepared package output already exists")) {
    fwrite(
        STDERR,
        "Existing valid prepared packages must be reusable by the operator console.\n"
    );
    exit(1);
}

if (str_contains($service, "shell_exec(")
        || str_contains($service, "exec(")
        || str_contains($service, "system(")) {
    fwrite(
        STDERR,
        "Package preparer must not invoke a shell command string.\n"
    );
    exit(1);
}

$clichecks = [
    "'zip' => null",
    "'output-name' => null",
    "operator_package_preparer",
    "--zip et --output-name sont obligatoires",
];

foreach ($clichecks as $check) {
    if (!str_contains($cli, $check)) {
        fwrite(
            STDERR,
            "Missing package preparation CLI behavior: {$check}\n"
        );
        exit(1);
    }
}

$settingchecks = [
    "local_iliasmigration/projectroot",
    "local_iliasmigration/importsroot",
    "local_iliasmigration/packagesroot",
    "local_iliasmigration/iliasversion",
];

foreach ($settingchecks as $check) {
    if (!str_contains($settings, $check)) {
        fwrite(
            STDERR,
            "Missing package preparation setting: {$check}\n"
        );
        exit(1);
    }
}

echo "OPERATOR_PACKAGE_PREPARER_OK\n";

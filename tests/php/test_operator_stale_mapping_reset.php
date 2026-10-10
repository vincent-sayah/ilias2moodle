<?php

$root = dirname(__DIR__, 2);

$servicepath = $root
    . '/moodle/local_iliasmigration/classes/operator_mapping_reset.php';
$resetpagepath = $root
    . '/moodle/local_iliasmigration/reset.php';
$indexpath = $root
    . '/moodle/local_iliasmigration/index.php';
$installpath = $root
    . '/moodle/local_iliasmigration/db/install.xml';
$upgradepath = $root
    . '/moodle/local_iliasmigration/db/upgrade.php';
$versionpath = $root
    . '/moodle/local_iliasmigration/version.php';

foreach ([
    $servicepath,
    $resetpagepath,
    $indexpath,
    $installpath,
    $upgradepath,
    $versionpath,
] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
}

$service = file_get_contents($servicepath);
$resetpage = file_get_contents($resetpagepath);
$index = file_get_contents($indexpath);
$install = file_get_contents($installpath);
$upgrade = file_get_contents($upgradepath);
$version = file_get_contents($versionpath);

$servicechecks = [
    "STATE_ORPHANED = 'ORPHANED'",
    "source_instance(\$document)",
    "unknown-ilias-instance",
    "sourceinstance = :sourceinstance",
    "sourceinstance = :legacyinstance",
    "OR sourceinstance = :legacyinstance",
    "delete_records_select(",
    "count_records_select(",

    "STATE_LIVE_TARGET = 'LIVE_TARGET'",
    "record_exists('course'",
    "start_delegated_transaction()",
    "'local_iliasmigration_reset'",
    "hash('sha256', \$snapshotjson)",
    "'local_iliasmigration_map'",
    "'sourcelms' => 'ILIAS'",
    "'sourceinstance' => \$sourceinstance",
    "'sourcecourse' => \$sourcecourse",
    "delete_records(",
    "count_records(",
];

foreach ($servicechecks as $check) {
    if (!str_contains($service, $check)) {
        fwrite(
            STDERR,
            "Missing stale-mapping reset guard: {$check}\n"
        );
        exit(1);
    }
}

$auditpos = strpos(
    $service,
    "\$DB->insert_record(\n                'local_iliasmigration_reset'"
);
$deletepos = strpos(
    $service,
    "\$DB->delete_records(\n                'local_iliasmigration_map'"
);

if ($auditpos === false || $deletepos === false || $auditpos >= $deletepos) {
    fwrite(
        STDERR,
        "Reset audit must be inserted before mapping deletion.\n"
    );
    exit(1);
}

$pagechecks = [
    "require_sesskey();",
    "reset_source(",
    "create_run(",
    "resetandrelaunch",
    "'name' => \$name",
    "'sourcepath' => \$sourcepath",
    "'categoryid' => \$categoryid",
    "'categorypath' => \$categorypath",
    "Validate the relaunch target before deleting any mapping.",
];

foreach ($pagechecks as $check) {
    if (!str_contains($resetpage, $check)) {
        fwrite(
            STDERR,
            "Missing reset confirmation page behavior: {$check}\n"
        );
        exit(1);
    }
}

$indexchecks = [
    "operator_mapping_reset",
    "STATE_ORPHANED",
    "/local/iliasmigration/reset.php",
];

foreach ($indexchecks as $check) {
    if (!str_contains($index, $check)) {
        fwrite(
            STDERR,
            "Missing operator reset routing: {$check}\n"
        );
        exit(1);
    }
}

if (!str_contains(
    $install,
    'TABLE NAME="local_iliasmigration_reset"'
)) {
    fwrite(STDERR, "Reset audit table missing from install.xml.\n");
    exit(1);
}

if (!str_contains($upgrade, "2026100701")
        || !str_contains(
            $upgrade,
            "new xmldb_table('local_iliasmigration_reset')"
        )) {
    fwrite(STDERR, "Reset audit upgrade step is missing.\n");
    exit(1);
}

if (!str_contains($version, '$plugin->version = 2026100701;')
        || !str_contains($version, "0.21.0-beta2")) {
    fwrite(STDERR, "Plugin beta2 version metadata is missing.\n");
    exit(1);
}

echo "OPERATOR_STALE_MAPPING_RESET_OK\n";

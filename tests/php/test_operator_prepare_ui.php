<?php

$root = dirname(__DIR__, 2);

$indexpath = $root
    . '/moodle/local_iliasmigration/index.php';
$preparepath = $root
    . '/moodle/local_iliasmigration/prepare.php';
$servicepath = $root
    . '/moodle/local_iliasmigration/classes/operator_package_preparer.php';

foreach ([$indexpath, $preparepath, $servicepath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: {$path}\n");
        exit(1);
    }
}

$index = file_get_contents($indexpath);
$prepare = file_get_contents($preparepath);
$service = file_get_contents($servicepath);

$indexchecks = [
    "operator_package_preparer",
    "available_imports()",
    "/local/iliasmigration/prepare.php",
    "prepareexport",
    "preparepackage",
    "optional_param('sourcepath'",
    "'value' => \$prefillsourcepath",
];

foreach ($indexchecks as $check) {
    if (!str_contains($index, $check)) {
        fwrite(
            STDERR,
            "Missing preparation UI behavior: {$check}\n"
        );
        exit(1);
    }
}

$preparechecks = [
    "require_sesskey();",
    "PARAM_FILE",
    "available_imports()",
    "array_key_exists(\$zipname, \$imports)",
    "\$preparer->prepare(",
    "recovery_required",
    "recovery_plan",
    "usepreparedpackage",
    "'sourcepath' =>",
];

foreach ($preparechecks as $check) {
    if (!str_contains($prepare, $check)) {
        fwrite(
            STDERR,
            "Missing preparation result behavior: {$check}\n"
        );
        exit(1);
    }
}

$servicechecks = [
    "public function available_imports()",
    "scandir(\$importsroot)",
    "basename(\$entry) !== \$entry",
    "PATHINFO_EXTENSION",
    "\$this->is_inside(",
    "SORT_NATURAL | SORT_FLAG_CASE",
];

foreach ($servicechecks as $check) {
    if (!str_contains($service, $check)) {
        fwrite(
            STDERR,
            "Missing safe imports listing behavior: {$check}\n"
        );
        exit(1);
    }
}

echo "OPERATOR_PREPARE_UI_OK\n";

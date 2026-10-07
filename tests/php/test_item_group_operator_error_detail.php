<?php

$path = __DIR__
    . '/../../moodle/local_iliasmigration/classes/phase65_item_group_executor.php';

$source = file_get_contents($path);

if ($source === false) {
    fwrite(STDERR, "Unable to read phase65_item_group_executor.php\n");
    exit(1);
}

$checks = [
    'Item Group package validation failed (',
    "\$validation['code']",
    "\$membervalidation['errors']",
    "' Members: '",
];

foreach ($checks as $check) {
    if (!str_contains($source, $check)) {
        fwrite(
            STDERR,
            "Missing Item Group operator validation detail: {$check}\n"
        );
        exit(1);
    }
}

echo "ITEM_GROUP_OPERATOR_ERROR_DETAIL_OK\n";

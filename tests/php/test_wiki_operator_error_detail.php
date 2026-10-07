<?php

$path = __DIR__
    . '/../../moodle/local_iliasmigration/classes/phase65_wiki_executor.php';

$source = file_get_contents($path);

if ($source === false) {
    fwrite(STDERR, "Unable to read phase65_wiki_executor.php\n");
    exit(1);
}

$checks = [
    "Wiki package validation failed (",
    "\$validation['code']",
    "\$validation['message']",
];

foreach ($checks as $check) {
    if (!str_contains($source, $check)) {
        fwrite(
            STDERR,
            "Missing operator-facing Wiki validation detail: {$check}\n"
        );
        exit(1);
    }
}

echo "WIKI_OPERATOR_ERROR_DETAIL_OK\n";

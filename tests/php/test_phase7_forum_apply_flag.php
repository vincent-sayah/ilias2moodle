<?php

$path =
    __DIR__
    . '/../../moodle/local_iliasmigration/classes/'
    . 'phase7_forum_contribution_resolver.php';

$source = file_get_contents($path);

if ($source === false) {
    fwrite(
        STDERR,
        "Unable to read Phase 7 Forum contribution resolver.\n"
    );
    exit(1);
}

$expected = "'apply_implemented' => true,";

if (!str_contains($source, $expected)) {
    fwrite(
        STDERR,
        "Phase 7 Forum apply_implemented regression failed.\n"
    );
    exit(1);
}

if (str_contains(
    $source,
    "'apply_implemented' => false,"
)) {
    fwrite(
        STDERR,
        "Phase 7 Forum resolver still reports apply_implemented=false.\n"
    );
    exit(1);
}

echo "PHASE7_FORUM_APPLY_FLAG_OK\n";

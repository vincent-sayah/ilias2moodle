<?php

$path = __DIR__
    . '/../../moodle/local_iliasmigration/classes/structure_executor.php';

$source = file_get_contents($path);

if ($source === false) {
    fwrite(STDERR, "Unable to read structure_executor.php\n");
    exit(1);
}

$checks = [
    "method_exists(\$sectionactions, 'move_at')",
    "\$sectionactions->move_at(",
    "move_section_to(",
    "Unable to move the Moodle section to the requested position.",
];

foreach ($checks as $check) {
    if (!str_contains($source, $check)) {
        fwrite(
            STDERR,
            "Missing Moodle section-move compatibility guard: {$check}\n"
        );
        exit(1);
    }
}

echo "STRUCTURE_SECTION_MOVE_COMPAT_OK\n";

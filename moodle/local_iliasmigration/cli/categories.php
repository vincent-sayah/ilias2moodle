<?php

// Read-only helper listing Moodle course categories.
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$categories = $DB->get_records(
    'course_categories',
    null,
    'sortorder ASC',
    'id,name,parent,visible,sortorder'
);

if (!$categories) {
    cli_error('No Moodle course categories found.');
}

echo "ID\tPARENT\tVISIBLE\tNAME" . PHP_EOL;
foreach ($categories as $category) {
    echo (int) $category->id . "\t"
        . (int) $category->parent . "\t"
        . (int) $category->visible . "\t"
        . (string) $category->name . PHP_EOL;
}

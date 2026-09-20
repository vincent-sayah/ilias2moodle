<?php

define('MOODLE_INTERNAL', true);

class coding_exception extends Exception {}

final class fake_db_blog_validator {
    public array $records = [];

    public function get_record(
        string $table,
        array $conditions,
        string $fields = '*'
    ): object|false {
        if ($table === 'modules' && ($conditions['name'] ?? '') === 'data') {
            return (object) ['id' => 1, 'name' => 'data', 'visible' => 1];
        }
        return false;
    }

    public function get_record_sql(string $sql, array $params): object|false {
        return false;
    }
}

require_once(__DIR__ . '/../classes/phase65_blog_package_validator.php');

echo "BLOG_VALIDATOR_SYNTAX_OK\n";

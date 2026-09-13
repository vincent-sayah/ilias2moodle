<?php

define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../../moodle/local_iliasmigration/classes/category_path_resolver.php');

use local_iliasmigration\category_path_resolver;

function fail_category_test(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assert_category_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fail_category_test($message . ' expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true));
    }
}

final class fake_category_db {
    /** @var array<int,array<int,object>> */
    private array $records;

    public function __construct(array $records) {
        $this->records = $records;
    }

    public function get_records(
        string $table,
        array $conditions,
        string $sort = '',
        string $fields = '*'
    ): array {
        if ($table !== 'course_categories') {
            fail_category_test('Unexpected table requested: ' . $table);
        }

        $parent = (int) ($conditions['parent'] ?? -1);
        $name = (string) ($conditions['name'] ?? '');
        $matches = [];

        foreach ($this->records[$parent] ?? [] as $record) {
            if ((string) $record->name === $name) {
                $matches[(int) $record->id] = clone $record;
            }
        }

        ksort($matches);
        return $matches;
    }
}

assert_category_same(
    ['Marine', 'Formation', 'PEM'],
    category_path_resolver::parse_path(' Marine > Formation > PEM '),
    'Preferred > separator must be normalized.'
);
assert_category_same(
    ['Marine', 'Formation', 'PEM'],
    category_path_resolver::parse_path('Marine/Formation/PEM'),
    'Slash separator must remain supported.'
);

$DB = new fake_category_db([
    0 => [
        (object) ['id' => 10, 'name' => 'Marine', 'parent' => 0, 'visible' => 1],
    ],
    10 => [
        (object) ['id' => 11, 'name' => 'Formation', 'parent' => 10, 'visible' => 0],
    ],
]);

$plan = (new category_path_resolver())->plan('Marine > Formation > PEM');
assert_category_same(true, $plan['ready'], 'Unique existing path prefix must be safe.');
assert_category_same(true, $plan['requires_creation'], 'Missing final category must require creation.');
assert_category_same(null, $plan['target_id'], 'Missing category path must not invent a Moodle id.');
assert_category_same(
    ['SELECT', 'SELECT', 'CREATE'],
    array_column($plan['operations'], 'action'),
    'Existing segments must be selected and only the missing segment created.'
);
assert_category_same(0, $plan['creation_visibility'], 'Automatically created categories must be hidden.');

$DB = new fake_category_db([
    0 => [
        (object) ['id' => 20, 'name' => 'Marine', 'parent' => 0, 'visible' => 1],
        (object) ['id' => 21, 'name' => 'Marine', 'parent' => 0, 'visible' => 1],
    ],
]);

$ambiguous = (new category_path_resolver())->plan('Marine > Formation');
assert_category_same(false, $ambiguous['ready'], 'Duplicate sibling names must block automatic resolution.');
assert_category_same(
    'CATEGORY_PATH_AMBIGUOUS',
    $ambiguous['blockers'][0]['code'] ?? '',
    'Ambiguous path must expose a stable blocker code.'
);
assert_category_same(
    [20, 21],
    $ambiguous['blockers'][0]['matching_ids'] ?? [],
    'Ambiguity report must expose matching Moodle category ids.'
);

fwrite(STDOUT, "OK: category path policy\n");

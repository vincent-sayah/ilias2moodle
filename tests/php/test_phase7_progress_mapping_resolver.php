<?php

define('MOODLE_INTERNAL', true);

class moodle_exception extends Exception {}

final class phase7_progress_fake_db {
    private array $rows;

    public function __construct(array $rows) {
        $this->rows = $rows;
    }

    public function get_records(
        string $table,
        array $conditions,
        string $sort = '',
        string $fields = '*'
    ): array {
        $result = [];
        foreach ($this->rows as $row) {
            $match = true;
            foreach ($conditions as $field => $value) {
                if ((string) ($row->{$field} ?? '') !== (string) $value) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $result[(int) $row->id] = clone $row;
            }
        }
        ksort($result);
        return $result;
    }

    public function get_records_select(
        string $table,
        string $select,
        array $params,
        string $sort = '',
        string $fields = '*'
    ): array {
        $result = [];

        if (count($params) === 4) {
            [$sourcelms, $sourceref, $sourcecourse, $sourcecourseref] = $params;
            foreach ($this->rows as $row) {
                if ((string) $row->sourcelms !== (string) $sourcelms
                        || (string) $row->sourceref !== (string) $sourceref
                        || !in_array(
                            (string) $row->sourcecourse,
                            [(string) $sourcecourse, (string) $sourcecourseref],
                            true
                        )) {
                    continue;
                }
                $result[(int) $row->id] = clone $row;
            }
        } else if (count($params) === 5) {
            [
                $sourcelms,
                $sourceobj,
                $childlike,
                $sourcecourse,
                $sourcecourseref,
            ] = $params;
            $prefix = rtrim((string) $childlike, '%');

            foreach ($this->rows as $row) {
                if ((string) $row->sourcelms !== (string) $sourcelms
                        || (string) $row->sourceobj !== (string) $sourceobj
                        || !str_starts_with((string) $row->sourceref, $prefix)
                        || !in_array(
                            (string) $row->sourcecourse,
                            [(string) $sourcecourse, (string) $sourcecourseref],
                            true
                        )) {
                    continue;
                }
                $result[(int) $row->id] = clone $row;
            }
        } else {
            throw new RuntimeException(
                'Unexpected get_records_select parameter count.'
            );
        }

        ksort($result);
        return $result;
    }

    public function sql_like(string $field, string $param): string {
        return $field . ' LIKE ' . $param;
    }

    public function sql_like_escape(string $value): string {
        return $value;
    }
}

function phase7_progress_mapping_row(
    int $id,
    string $sourceinstance,
    string $sourcecourse,
    string $sourceref,
    string $sourceobj,
    string $targettype,
    int $targetid
): stdClass {
    return (object) [
        'id' => $id,
        'sourcelms' => 'ILIAS',
        'sourceinstance' => $sourceinstance,
        'sourcecourse' => $sourcecourse,
        'sourceref' => $sourceref,
        'sourceobj' => $sourceobj,
        'targettype' => $targettype,
        'targetid' => $targetid,
        'status' => 'READY',
    ];
}

require_once(
    __DIR__
    . '/../../moodle/local_iliasmigration/classes/phase7_progress_resolver.php'
);

$rows = [
    phase7_progress_mapping_row(
        47, 'http://192.168.56.50', '128', '236', '713', 'quiz', 22
    ),
    phase7_progress_mapping_row(
        57, 'http://192.168.56.50', '128',
        '274:assignment:1', '806', 'assign', 26
    ),
    phase7_progress_mapping_row(
        58, 'http://192.168.56.50', '128',
        '274:assignment:2', '806', 'assign', 27
    ),
    phase7_progress_mapping_row(
        59, 'http://192.168.56.50', '128',
        '274:assignment:3', '806', 'assign', 28
    ),
    phase7_progress_mapping_row(
        60, 'http://192.168.56.50', '128',
        '274:assignment:4', '806', 'assign', 29
    ),
];

global $DB;
$DB = new phase7_progress_fake_db($rows);

$resolver = new \local_iliasmigration\phase7_progress_resolver();
$method = new ReflectionMethod(
    \local_iliasmigration\phase7_progress_resolver::class,
    'resolve_object_mappings'
);
$method->setAccessible(true);

$fallback = $method->invoke(
    $resolver, 'ilias10', '504', '128', '236', '713', false
);
$exercise = $method->invoke(
    $resolver, 'ilias10', '504', '128', '274', '806', true
);
$exerciseDisabled = $method->invoke(
    $resolver, 'ilias10', '504', '128', '274', '806', false
);

$checks = [
    ($fallback['resolution'] ?? '') === 'UNIQUE_FALLBACK_MAPPING',
    ($fallback['sourceinstance'] ?? '') === 'http://192.168.56.50',
    ($fallback['sourcecourse'] ?? '') === '128',
    empty($fallback['ambiguous']),
    count($fallback['mappings'] ?? []) === 1,
    (int) reset($fallback['mappings'])->targetid === 22,

    ($exercise['resolution'] ?? '') === 'UNIQUE_CHILD_PREFIX_MAPPING',
    ($exercise['sourceinstance'] ?? '') === 'http://192.168.56.50',
    ($exercise['sourcecourse'] ?? '') === '128',
    empty($exercise['ambiguous']),
    count($exercise['mappings'] ?? []) === 4,
    array_values(array_map(
        static fn($row): int => (int) $row->targetid,
        $exercise['mappings']
    )) === [26, 27, 28, 29],

    ($exerciseDisabled['resolution'] ?? '') === 'MISSING',
    count($exerciseDisabled['mappings'] ?? []) === 0,
];

foreach ($checks as $ok) {
    if (!$ok) {
        fwrite(STDERR, "Phase 7 progress mapping resolver regression failed.\n");
        exit(1);
    }
}

$ambiguousrows = $rows;
$ambiguousrows[] = phase7_progress_mapping_row(
    61,
    'http://another-ilias.example',
    '128',
    '274:assignment:5',
    '806',
    'assign',
    30
);
$DB = new phase7_progress_fake_db($ambiguousrows);

$ambiguous = $method->invoke(
    $resolver, 'ilias10', '504', '128', '274', '806', true
);

$ambiguouschecks = [
    ($ambiguous['resolution'] ?? '') === 'AMBIGUOUS_CHILD_PREFIX_MAPPING',
    !empty($ambiguous['ambiguous']),
    count($ambiguous['mappings'] ?? []) === 0,
    (int) ($ambiguous['candidate_count'] ?? 0) === 5,
];

foreach ($ambiguouschecks as $ok) {
    if (!$ok) {
        fwrite(STDERR, "Phase 7 ambiguous Exercise mapping regression failed.\n");
        exit(1);
    }
}

echo "PHASE7_PROGRESS_MAPPING_RESOLVER_OK\n";

$testclassifier = new ReflectionMethod(
    \local_iliasmigration\phase7_progress_resolver::class,
    'classify_test'
);
$testclassifier->setAccessible(true);

$scormclassifier = new ReflectionMethod(
    \local_iliasmigration\phase7_progress_resolver::class,
    'classify_scorm'
);
$scormclassifier->setAccessible(true);

$scoredtest = $testclassifier->invoke(
    $resolver,
    [
        'test' => [
            'object_id' => '1006',
            'ref_id' => '357',
            'title' => 'test validation du cours',
        ],
        'counts' => [
            'test_participants_with_active_id' => 2,
            'scored_participants' => 2,
        ],
    ]
);

$unscoredtest = $testclassifier->invoke(
    $resolver,
    [
        'test' => [
            'object_id' => '9999',
            'ref_id' => '9999',
            'title' => 'unfinished test',
        ],
        'counts' => [
            'test_participants_with_active_id' => 1,
            'scored_participants' => 0,
        ],
    ]
);

$emptytest = $testclassifier->invoke(
    $resolver,
    [
        'test' => [
            'object_id' => '947',
            'ref_id' => '331',
            'title' => 'Test global',
        ],
        'counts' => [
            'test_participants_with_active_id' => 0,
            'scored_participants' => 0,
        ],
    ]
);

$scormpartial = $scormclassifier->invoke(
    $resolver,
    [
        'scorm' => [
            'object_id' => '994',
            'ref_id' => '347',
            'title' => 'Gestion des incidents',
            'subtype' => 'scorm2004',
        ],
        'counts' => [
            'tracked_users_in_course' => 2,
            'users_with_sco_data' => 2,
            'total_attempts' => 3,
            'sco_tracking_records' => 2,
        ],
    ]
);

$classificationchecks = [
    ($scoredtest['classification'] ?? '') === 'HISTORY_ONLY',
    ($scoredtest['reason'] ?? '') ===
        'TEST_FINAL_RESULTS_AVAILABLE_WITHOUT_RECONSTRUCTIBLE_QUIZ_ATTEMPTS',

    ($unscoredtest['classification'] ?? '') === 'PARTIAL',
    ($unscoredtest['reason'] ?? '') ===
        'TEST_PARTICIPATION_PRESENT_WITHOUT_FINAL_SCORING',

    ($emptytest['classification'] ?? '') === 'NO_DATA',

    ($scormpartial['classification'] ?? '') === 'PARTIAL',
    ($scormpartial['reason'] ?? '') ===
        'SCORM_FINAL_RUNTIME_STATE_AVAILABLE_BUT_ATTEMPT_HISTORY_INCOMPLETE',
];

foreach ($classificationchecks as $ok) {
    if (!$ok) {
        fwrite(
            STDERR,
            "Phase 7 progress classification regression failed.\n"
        );
        exit(1);
    }
}

echo "PHASE7_PROGRESS_CLASSIFICATION_OK\n";

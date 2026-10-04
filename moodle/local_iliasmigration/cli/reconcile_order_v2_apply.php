<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'source' => '',
        'plan' => '',
        'expected-source-sha256' => '',
        'expected-plan-sha256' => '',
        'check-only' => false,
        'apply' => false,
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    cli_error(
        "Unknown options:\n  "
        . implode("\n  ", $unrecognized)
    );
}

$help = <<<EOF
ILIAS2Moodle - order reconciliation V2 guarded executor

Read-only preflight:
  php local/iliasmigration/cli/reconcile_order_v2_apply.php \\
      --source=/path/to/migration.json \\
      --plan=/path/to/order-plan.json \\
      --expected-source-sha256=HASH \\
      --expected-plan-sha256=HASH \\
      --check-only

Guarded apply:
  php local/iliasmigration/cli/reconcile_order_v2_apply.php \\
      --source=/path/to/migration.json \\
      --plan=/path/to/order-plan.json \\
      --expected-source-sha256=HASH \\
      --expected-plan-sha256=HASH \\
      --apply

Exactly one of --check-only or --apply is required.

EOF;

if ($options['help']) {
    echo $help;
    exit(0);
}

global $DB, $USER;

/**
 * Return exactly one migration mapping or false.
 *
 * Exact sourceinstance wins. Legacy empty-sourceinstance is accepted
 * only when the exact mapping does not exist.
 */
function v2_find_mapping(
    string $sourceinstance,
    string $sourcecourse,
    string $sourceref,
    string $targettype
): \stdClass|false {
    global $DB;

    $conditions = [
        'sourcelms' => 'ILIAS',
        'sourceinstance' => $sourceinstance,
        'sourcecourse' => $sourcecourse,
        'sourceref' => $sourceref,
        'targettype' => $targettype,
    ];

    $records = $DB->get_records(
        'local_iliasmigration_map',
        $conditions,
        'id ASC'
    );

    if (count($records) > 1) {
        throw new \coding_exception(
            "Duplicate exact mapping for {$sourceref}/{$targettype}."
        );
    }

    if ($records) {
        return reset($records);
    }

    if ($sourceinstance === '') {
        return false;
    }

    $conditions['sourceinstance'] = '';

    $records = $DB->get_records(
        'local_iliasmigration_map',
        $conditions,
        'id ASC'
    );

    if (count($records) > 1) {
        throw new \coding_exception(
            "Duplicate legacy mapping for {$sourceref}/{$targettype}."
        );
    }

    return $records
        ? reset($records)
        : false;
}

/**
 * Read one Moodle CM and its current section.
 */
function v2_get_cm(
    int $courseid,
    int $cmid
): array {
    global $DB;

    $cm = $DB->get_record_sql(
        'SELECT cm.id,
                cm.course,
                cm.instance,
                cm.section,
                m.name AS modulename
           FROM {course_modules} cm
           JOIN {modules} m
             ON m.id = cm.module
          WHERE cm.id = ?',
        [$cmid]
    );

    if (!$cm || (int) $cm->course !== $courseid) {
        throw new \coding_exception(
            "CMID {$cmid} is missing from target course {$courseid}."
        );
    }

    $section = $DB->get_record(
        'course_sections',
        [
            'id' => (int) $cm->section,
            'course' => $courseid,
        ],
        'id,section,name,component,itemid',
        MUST_EXIST
    );

    return [$cm, $section];
}

/**
 * Section sequence as integer CMIDs.
 */
function v2_sequence(int $sectionid): array {
    global $DB;

    $sequence = (string) $DB->get_field(
        'course_sections',
        'sequence',
        ['id' => $sectionid],
        MUST_EXIST
    );

    if (trim($sequence) === '') {
        return [];
    }

    return array_map(
        'intval',
        explode(',', $sequence)
    );
}

/**
 * Save one exact synthetic-section mapping.
 */
function v2_save_synthetic_mapping(
    string $sourceinstance,
    string $sourcecourse,
    string $sourceref,
    int $targetid
): void {
    global $DB;

    $existing = v2_find_mapping(
        $sourceinstance,
        $sourcecourse,
        $sourceref,
        'synthetic_section'
    );

    $now = time();

    if ($existing) {
        $existing->sourceinstance = $sourceinstance;
        $existing->targetid = $targetid;
        $existing->status = 'READY';
        $existing->timemodified = $now;

        $DB->update_record(
            'local_iliasmigration_map',
            $existing
        );

        return;
    }

    $DB->insert_record(
        'local_iliasmigration_map',
        (object) [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'sourceobj' => null,
            'sourceversion' => null,
            'targettype' => 'synthetic_section',
            'targetid' => $targetid,
            'status' => 'READY',
            'timecreated' => $now,
            'timemodified' => $now,
        ]
    );
}

/**
 * Move mapped CMIDs to one section in plan order.
 */
function v2_move_cmids(
    \stdClass $course,
    int $sectionid,
    array $cmids
): void {
    global $DB;

    if (!$cmids) {
        return;
    }

    $section = $DB->get_record(
        'course_sections',
        [
            'id' => $sectionid,
            'course' => (int) $course->id,
        ],
        '*',
        MUST_EXIST
    );

    foreach ($cmids as $cmid) {
        rebuild_course_cache(
            (int) $course->id,
            true
        );

        $cm = get_fast_modinfo(
            (int) $course->id
        )->get_cm(
            (int) $cmid
        );

        moveto_module(
            $cm,
            $section,
            null
        );
    }

    rebuild_course_cache(
        (int) $course->id,
        true
    );
}

/**
 * Moodle section snapshot.
 */
function v2_snapshot(int $courseid): array {
    global $DB;

    $result = [];

    foreach ($DB->get_records(
        'course_sections',
        ['course' => $courseid],
        'section ASC',
        'id,section,name,sequence,component,itemid'
    ) as $section) {
        $result[] = [
            'id' => (int) $section->id,
            'section' => (int) $section->section,
            'name' => $section->name,
            'component' => $section->component,
            'itemid' => $section->itemid,
            'sequence' =>
                trim((string) $section->sequence) === ''
                    ? []
                    : array_map(
                        'intval',
                        explode(
                            ',',
                            (string) $section->sequence
                        )
                    ),
        ];
    }

    return $result;
}

/*
 * ------------------------------------------------------------
 * Arguments and cryptographic contract.
 * ------------------------------------------------------------
 */

$sourceinput = trim(
    (string) $options['source']
);

$planinput = trim(
    (string) $options['plan']
);

$expectedsource = strtolower(
    trim(
        (string) $options[
            'expected-source-sha256'
        ]
    )
);

$expectedplan = strtolower(
    trim(
        (string) $options[
            'expected-plan-sha256'
        ]
    )
);

$checkonly = (bool) $options['check-only'];
$apply = (bool) $options['apply'];

if ($checkonly === $apply) {
    cli_error(
        "Choose exactly one of --check-only or --apply.\n\n"
        . $help
    );
}

$source = realpath($sourceinput);

if ($source === false || !is_file($source)) {
    cli_error(
        'Source migration.json is missing.'
    );
}

$planpath = realpath($planinput);

if ($planpath === false || !is_file($planpath)) {
    cli_error(
        'Order reconciliation plan is missing.'
    );
}

if (!preg_match(
    '/^[0-9a-f]{64}$/',
    $expectedsource
)) {
    cli_error(
        'Invalid --expected-source-sha256.'
    );
}

if (!preg_match(
    '/^[0-9a-f]{64}$/',
    $expectedplan
)) {
    cli_error(
        'Invalid --expected-plan-sha256.'
    );
}

$actualsource = strtolower(
    (string) hash_file(
        'sha256',
        $source
    )
);

$actualplan = strtolower(
    (string) hash_file(
        'sha256',
        $planpath
    )
);

if ($actualsource !== $expectedsource) {
    cli_error(
        'Source SHA256 changed. '
        . "Expected {$expectedsource}, "
        . "got {$actualsource}."
    );
}

if ($actualplan !== $expectedplan) {
    cli_error(
        'Plan SHA256 changed. '
        . "Expected {$expectedplan}, "
        . "got {$actualplan}."
    );
}

try {
    $plan = json_decode(
        (string) file_get_contents($planpath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (\Throwable $exception) {
    cli_error(
        'Unable to decode plan JSON: '
        . $exception->getMessage()
    );
}

if (!is_array($plan)) {
    cli_error(
        'Plan JSON must decode to an object.'
    );
}

$recordedsource = strtolower(
    trim(
        (string) (
            $plan['source']['sha256']
                ?? ''
        )
    )
);

if ($recordedsource !== $actualsource) {
    cli_error(
        'Plan/source SHA256 mismatch.'
    );
}

if (($plan['version'] ?? '')
        !== 'order-reconciler-v2-prototype') {
    cli_error(
        'Unexpected order plan version.'
    );
}

$order = $plan[
    'order_reconciliation_v2'
] ?? null;

if (!is_array($order)) {
    cli_error(
        'Missing order_reconciliation_v2.'
    );
}

if (empty($order['ready'])) {
    cli_error(
        'Order V2 plan is not READY.'
    );
}

if (!empty($order['blockers'])) {
    cli_error(
        'Order V2 plan contains blockers.'
    );
}

$courseid = (int) (
    $plan['course']['target_id']
        ?? 0
);

$sourceinstance = trim(
    (string) (
        $plan['source']['instance']
            ?? ''
    )
);

$sourcecourse = trim(
    (string) (
        $plan['source']['course_ref_id']
            ?? ''
    )
);

if ($courseid <= 0
        || !$DB->record_exists(
            'course',
            ['id' => $courseid]
        )) {
    cli_error(
        'Target Moodle course is invalid.'
    );
}

if ($sourcecourse === '') {
    cli_error(
        'Source course identity is empty.'
    );
}

$root = array_values(
    array_filter(
        (array) (
            $order['top_level'] ?? []
        ),
        'is_array'
    )
);

$delegated = array_values(
    array_filter(
        (array) (
            $order['delegated_sections']
                ?? []
        ),
        'is_array'
    )
);

if (!$root) {
    cli_error(
        'Order V2 root plan is empty.'
    );
}

/*
 * ------------------------------------------------------------
 * Strict preflight.
 * ------------------------------------------------------------
 */

usort(
    $root,
    static fn(array $a, array $b): int =>
        (int) (
            $a['desired_section_number']
                ?? 0
        )
        <=>
        (int) (
            $b['desired_section_number']
                ?? 0
        )
);

$expectedposition = 1;
$syntheticcreate = 0;
$allmanaged = [];
$preflightroot = [];

foreach ($root as $entry) {
    $kind = (string) (
        $entry['kind'] ?? ''
    );

    $desired = (int) (
        $entry['desired_section_number']
            ?? 0
    );

    if ($desired !== $expectedposition) {
        cli_error(
            'Root desired positions are not '
            . 'strictly contiguous from 1.'
        );
    }

    $expectedposition++;

    $ref = trim(
        (string) (
            $entry['source_ref_id']
                ?? $entry['synthetic_ref']
                ?? ''
        )
    );

    if ($ref === '') {
        cli_error(
            'Root entry has no source identity.'
        );
    }

    $targetid = (
        isset($entry['target_section_id'])
        && $entry['target_section_id'] !== null
    )
        ? (int) $entry['target_section_id']
        : null;

    $current = (
        isset($entry['current_section_number'])
        && $entry['current_section_number'] !== null
    )
        ? (int) $entry[
            'current_section_number'
        ]
        : null;

    if ($kind === 'synthetic_section') {
        $action = (string) (
            $entry['action'] ?? ''
        );

        $mapping = v2_find_mapping(
            $sourceinstance,
            $sourcecourse,
            $ref,
            'synthetic_section'
        );

        if ($action === 'CREATE') {
            if ($mapping) {
                cli_error(
                    "Synthetic {$ref} now has a mapping; "
                    . 'the frozen plan is stale.'
                );
            }

            if ($targetid !== null) {
                cli_error(
                    "Synthetic {$ref} CREATE unexpectedly "
                    . 'has a target section.'
                );
            }

            $syntheticcreate++;
        } else if ($action === 'REUSE') {
            if (!$mapping
                    || empty($mapping->targetid)) {
                cli_error(
                    "Synthetic {$ref} REUSE mapping "
                    . 'is missing.'
                );
            }

            if ((int) $mapping->targetid
                    !== $targetid) {
                cli_error(
                    "Synthetic {$ref} target changed."
                );
            }
        } else {
            cli_error(
                "Unsupported synthetic action {$action}."
            );
        }
    } else {
        if ($targetid === null
                || $targetid <= 0) {
            cli_error(
                "Root {$ref} has no stable target section."
            );
        }

        $mapping = v2_find_mapping(
            $sourceinstance,
            $sourcecourse,
            $ref,
            'section'
        );

        if (!$mapping
                || (int) $mapping->targetid
                    !== $targetid) {
            cli_error(
                "Root {$ref} section mapping changed."
            );
        }
    }

    if ($targetid !== null) {
        $section = $DB->get_record(
            'course_sections',
            [
                'id' => $targetid,
                'course' => $courseid,
            ],
            'id,section,name,component,itemid',
            IGNORE_MISSING
        );

        if (!$section
                || !empty($section->component)) {
            cli_error(
                "Root {$ref} target section is stale "
                . 'or delegated.'
            );
        }

        if ($current !== null
                && (int) $section->section
                    !== $current) {
            cli_error(
                "Root {$ref} moved since dry-run. "
                . "Expected {$current}, "
                . "current {$section->section}."
            );
        }
    }

    $cmids = [];

    foreach (
        (array) ($entry['items'] ?? [])
        as $item
    ) {
        if (!is_array($item)) {
            continue;
        }

        $cmid = (int) (
            $item['cmid'] ?? 0
        );

        if ($cmid <= 0) {
            cli_error(
                "Root {$ref} contains an invalid CMID."
            );
        }

        [$cm, $section] = v2_get_cm(
            $courseid,
            $cmid
        );

        $expectedmodule = trim(
            (string) (
                $item['module'] ?? ''
            )
        );

        if ($expectedmodule !== ''
                && (string) $cm->modulename
                    !== $expectedmodule) {
            cli_error(
                "CMID {$cmid} module type changed."
            );
        }

        $expectedcurrent = (
            isset(
                $item[
                    'current_section_number'
                ]
            )
            && $item[
                'current_section_number'
            ] !== null
        )
            ? (int) $item[
                'current_section_number'
            ]
            : null;

        if ($expectedcurrent !== null
                && (int) $section->section
                    !== $expectedcurrent) {
            cli_error(
                "CMID {$cmid} moved since dry-run."
            );
        }

        /*
         * Existing non-synthetic root structures already own
         * their CMIDs. Verify that before any write.
         */
        if ($kind !== 'synthetic_section'
                && $targetid !== null
                && (int) $cm->section
                    !== $targetid) {
            cli_error(
                "CMID {$cmid} no longer belongs "
                . "to root {$ref}."
            );
        }

        $cmids[] = $cmid;
        $allmanaged[$cmid] = true;
    }

    $preflightroot[] = [
        'kind' => $kind,
        'ref' => $ref,
        'desired_section_number' =>
            $desired,
        'target_section_id' =>
            $targetid,
        'current_section_number' =>
            $current,
        'planned_action' =>
            (string) (
                $entry['action'] ?? ''
            ),
        'cmids' => $cmids,
    ];
}

/*
 * Delegated structures are also stable-ID validated.
 */
$preflightdelegated = [];

foreach ($delegated as $entry) {
    $ref = trim(
        (string) (
            $entry['source_ref_id'] ?? ''
        )
    );

    $cmid = (int) (
        $entry['cmid'] ?? 0
    );

    $delegatedid = (int) (
        $entry['delegated_section_id']
            ?? 0
    );

    if ($ref === ''
            || $cmid <= 0
            || $delegatedid <= 0) {
        cli_error(
            'Invalid delegated plan entry.'
        );
    }

    $mapping = v2_find_mapping(
        $sourceinstance,
        $sourcecourse,
        $ref,
        'subsection'
    );

    if (!$mapping
            || (int) $mapping->targetid
                !== $cmid) {
        cli_error(
            "Subsection {$ref} mapping changed."
        );
    }

    [$cm, $parent] = v2_get_cm(
        $courseid,
        $cmid
    );

    if ((string) $cm->modulename
            !== 'subsection') {
        cli_error(
            "Mapped subsection {$ref} is no longer "
            . 'mod_subsection.'
        );
    }

    $delegatedsection = $DB->get_record(
        'course_sections',
        [
            'id' => $delegatedid,
            'course' => $courseid,
            'component' => 'mod_subsection',
            'itemid' => (int) $cm->instance,
        ],
        'id,section,name,component,itemid',
        IGNORE_MISSING
    );

    if (!$delegatedsection) {
        cli_error(
            "Delegated section for {$ref} changed."
        );
    }

    $cmids = [];

    foreach (
        (array) ($entry['items'] ?? [])
        as $item
    ) {
        if (!is_array($item)) {
            continue;
        }

        $childcmid = (int) (
            $item['cmid'] ?? 0
        );

        if ($childcmid <= 0) {
            cli_error(
                "Delegated {$ref} has invalid CMID."
            );
        }

        [$childcm, $childsection] =
            v2_get_cm(
                $courseid,
                $childcmid
            );

        if ((int) $childcm->section
                !== $delegatedid) {
            cli_error(
                "CMID {$childcmid} no longer belongs "
                . "to delegated {$ref}."
            );
        }

        $expectedcurrent = (
            isset(
                $item[
                    'current_section_number'
                ]
            )
            && $item[
                'current_section_number'
            ] !== null
        )
            ? (int) $item[
                'current_section_number'
            ]
            : null;

        if ($expectedcurrent !== null
                && (int) $childsection->section
                    !== $expectedcurrent) {
            cli_error(
                "Delegated CMID {$childcmid} moved "
                . 'since dry-run.'
            );
        }

        $cmids[] = $childcmid;
        $allmanaged[$childcmid] = true;
    }

    /*
     * The subsection CM itself is managed at root level.
     */
    $allmanaged[$cmid] = true;

    $preflightdelegated[] = [
        'ref' => $ref,
        'cmid' => $cmid,
        'parent_section_id' =>
            (int) $cm->section,
        'delegated_section_id' =>
            $delegatedid,
        'current_delegated_number' =>
            (int) $delegatedsection->section,
        'cmids' => $cmids,
    ];
}

$preflight = [
    'mode' => 'check-only',
    'writes_performed' => false,
    'contract' => [
        'source_sha256' =>
            $actualsource,
        'plan_sha256' =>
            $actualplan,
        'source_sha_match' => true,
        'plan_sha_match' => true,
    ],
    'course' => [
        'target_id' => $courseid,
        'source_ref_id' => $sourcecourse,
        'source_instance' => $sourceinstance,
    ],
    'ready' => true,
    'root_count' =>
        count($preflightroot),
    'delegated_count' =>
        count($preflightdelegated),
    'synthetic_create_count' =>
        $syntheticcreate,
    'managed_cmid_count' =>
        count($allmanaged),
    'root' => $preflightroot,
    'delegated' => $preflightdelegated,
    'current' => v2_snapshot(
        $courseid
    ),
];

if ($checkonly) {
    echo json_encode(
        $preflight,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    );
    echo PHP_EOL;
    exit(0);
}

/*
 * ------------------------------------------------------------
 * Guarded transaction.
 * ------------------------------------------------------------
 */

$course = $DB->get_record(
    'course',
    ['id' => $courseid],
    '*',
    MUST_EXIST
);

$originaluser = $USER;

\core\session\manager::set_user(
    get_admin()
);

$rootresult = [];
$delegatedresult = [];

try {
    $transaction =
        $DB->start_delegated_transaction();

    try {
        /*
         * 1. Materialise missing synthetic root sections.
         */
        foreach ($root as &$entry) {
            if (($entry['kind'] ?? '')
                    !== 'synthetic_section') {
                continue;
            }

            $ref = (string) (
                $entry['synthetic_ref'] ?? ''
            );

            $desired = (int) (
                $entry['desired_section_number']
                    ?? 0
            );

            $action = (string) (
                $entry['action'] ?? ''
            );

            if ($action === 'CREATE') {
                /*
                 * Re-check immediately before write.
                 */
                if (v2_find_mapping(
                    $sourceinstance,
                    $sourcecourse,
                    $ref,
                    'synthetic_section'
                )) {
                    throw new \coding_exception(
                        "Synthetic {$ref} mapping appeared "
                        . 'after preflight.'
                    );
                }

                $created = course_create_section(
                    $course,
                    $desired
                );

                $sectionid =
                    (int) $created->id;

                v2_save_synthetic_mapping(
                    $sourceinstance,
                    $sourcecourse,
                    $ref,
                    $sectionid
                );

                $entry[
                    'target_section_id'
                ] = $sectionid;

                $performed = 'CREATED';
            } else {
                $sectionid = (int) (
                    $entry[
                        'target_section_id'
                    ] ?? 0
                );

                $performed = 'REUSED';
            }

            rebuild_course_cache(
                $courseid,
                true
            );

            $info = get_fast_modinfo(
                $courseid
            )->get_section_info_by_id(
                $sectionid
            );

            if (!$info) {
                throw new \coding_exception(
                    "Unable to resolve synthetic {$ref}."
                );
            }

            \core_courseformat\formatactions
                ::section($course)
                ->update(
                    $info,
                    [
                        'name' => (string) (
                            $entry['title']
                                ?? ''
                        ),
                    ]
                );

            $rootresult[] = [
                'ref' => $ref,
                'kind' => 'synthetic_section',
                'action' => $performed,
                'target_section_id' =>
                    $sectionid,
                'desired_section_number' =>
                    $desired,
            ];
        }
        unset($entry);

        /*
         * 2. Ensure every regular root section is at the
         *    exact final position 1..N.
         */
        foreach ($root as &$entry) {
            $kind = (string) (
                $entry['kind'] ?? ''
            );

            $ref = (string) (
                $entry['source_ref_id']
                    ?? $entry['synthetic_ref']
                    ?? ''
            );

            $sectionid = (int) (
                $entry[
                    'target_section_id'
                ] ?? 0
            );

            $desired = (int) (
                $entry[
                    'desired_section_number'
                ] ?? 0
            );

            if ($sectionid <= 0
                    || $desired <= 0) {
                throw new \coding_exception(
                    "Root {$ref} has invalid runtime identity."
                );
            }

            rebuild_course_cache(
                $courseid,
                true
            );

            $info = get_fast_modinfo(
                $courseid
            )->get_section_info_by_id(
                $sectionid
            );

            if (!$info) {
                throw new \coding_exception(
                    "Unable to resolve root {$ref}."
                );
            }

            $before =
                (int) $info->section;

            if ($before !== $desired) {
                \core_courseformat\formatactions
                    ::section($course)
                    ->move_at(
                        $info,
                        $desired
                    );

                rebuild_course_cache(
                    $courseid,
                    true
                );
            }

            $after = $DB->get_record(
                'course_sections',
                [
                    'id' => $sectionid,
                    'course' => $courseid,
                ],
                'id,section,name,component,itemid',
                MUST_EXIST
            );

            if ((int) $after->section
                    !== $desired) {
                throw new \coding_exception(
                    "Root {$ref} failed to reach "
                    . "section {$desired}."
                );
            }

            $cmids = [];

            foreach (
                (array) (
                    $entry['items'] ?? []
                )
                as $item
            ) {
                if (is_array($item)
                        && !empty($item['cmid'])) {
                    $cmids[] =
                        (int) $item['cmid'];
                }
            }

            v2_move_cmids(
                $course,
                $sectionid,
                $cmids
            );

            /*
             * Synthetic result may already exist from step 1.
             * Source structures are recorded here.
             */
            if ($kind !== 'synthetic_section') {
                $rootresult[] = [
                    'ref' => $ref,
                    'kind' => $kind,
                    'action' =>
                        $before === $desired
                            ? 'REUSED'
                            : 'REPOSITIONED',
                    'target_section_id' =>
                        $sectionid,
                    'runtime_before_section_number' =>
                        $before,
                    'runtime_after_section_number' =>
                        $desired,
                    'cmids' => $cmids,
                ];
            } else {
                foreach (
                    $rootresult as &$rr
                ) {
                    if (($rr['ref'] ?? '')
                            === $ref) {
                        $rr[
                            'runtime_after_section_number'
                        ] = $desired;

                        $rr['cmids'] = $cmids;
                    }
                }
                unset($rr);
            }
        }
        unset($entry);

        /*
         * 3. Reconcile exact managed order inside delegated
         *    subsection sections by stable section ID.
         */
        foreach ($delegated as $entry) {
            $ref = (string) (
                $entry[
                    'source_ref_id'
                ] ?? ''
            );

            $delegatedid = (int) (
                $entry[
                    'delegated_section_id'
                ] ?? 0
            );

            $cmids = [];

            foreach (
                (array) (
                    $entry['items'] ?? []
                )
                as $item
            ) {
                if (is_array($item)
                        && !empty($item['cmid'])) {
                    $cmids[] =
                        (int) $item['cmid'];
                }
            }

            v2_move_cmids(
                $course,
                $delegatedid,
                $cmids
            );

            $delegatedresult[] = [
                'ref' => $ref,
                'subsection_cmid' =>
                    (int) (
                        $entry['cmid'] ?? 0
                    ),
                'delegated_section_id' =>
                    $delegatedid,
                'cmids' => $cmids,
            ];
        }

        /*
         * 4. Final validation before COMMIT.
         */
        rebuild_course_cache(
            $courseid,
            true
        );

        $managedset = $allmanaged;

        foreach ($root as $entry) {
            $ref = (string) (
                $entry['source_ref_id']
                    ?? $entry['synthetic_ref']
                    ?? ''
            );

            $sectionid = (int) (
                $entry[
                    'target_section_id'
                ] ?? 0
            );

            $desiredsection = (int) (
                $entry[
                    'desired_section_number'
                ] ?? 0
            );

            $section = $DB->get_record(
                'course_sections',
                [
                    'id' => $sectionid,
                    'course' => $courseid,
                ],
                'id,section,name,sequence,component,itemid',
                MUST_EXIST
            );

            if (!empty($section->component)) {
                throw new \coding_exception(
                    "Root {$ref} became delegated."
                );
            }

            if ((int) $section->section
                    !== $desiredsection) {
                throw new \coding_exception(
                    "Final root position invalid for {$ref}."
                );
            }

            $expectedcmids = [];

            foreach (
                (array) (
                    $entry['items'] ?? []
                )
                as $item
            ) {
                if (is_array($item)
                        && !empty($item['cmid'])) {
                    $expectedcmids[] =
                        (int) $item['cmid'];
                }
            }

            foreach ($expectedcmids as $cmid) {
                [$cm, ] = v2_get_cm(
                    $courseid,
                    $cmid
                );

                if ((int) $cm->section
                        !== $sectionid) {
                    throw new \coding_exception(
                        "Final root ownership invalid "
                        . "for CMID {$cmid}."
                    );
                }
            }

            $actualmanaged = array_values(
                array_filter(
                    v2_sequence($sectionid),
                    static fn(int $cmid): bool =>
                        isset($managedset[$cmid])
                )
            );

            if ($actualmanaged
                    !== $expectedcmids) {
                throw new \coding_exception(
                    "Final managed sequence mismatch "
                    . "for root {$ref}: expected "
                    . json_encode($expectedcmids)
                    . ', got '
                    . json_encode($actualmanaged)
                );
            }
        }

        foreach ($delegated as $entry) {
            $ref = (string) (
                $entry[
                    'source_ref_id'
                ] ?? ''
            );

            $delegatedid = (int) (
                $entry[
                    'delegated_section_id'
                ] ?? 0
            );

            $expectedcmids = [];

            foreach (
                (array) (
                    $entry['items'] ?? []
                )
                as $item
            ) {
                if (is_array($item)
                        && !empty($item['cmid'])) {
                    $expectedcmids[] =
                        (int) $item['cmid'];
                }
            }

            foreach ($expectedcmids as $cmid) {
                [$cm, ] = v2_get_cm(
                    $courseid,
                    $cmid
                );

                if ((int) $cm->section
                        !== $delegatedid) {
                    throw new \coding_exception(
                        "Final delegated ownership invalid "
                        . "for CMID {$cmid}."
                    );
                }
            }

            $actualmanaged = array_values(
                array_filter(
                    v2_sequence($delegatedid),
                    static fn(int $cmid): bool =>
                        isset($managedset[$cmid])
                )
            );

            if ($actualmanaged
                    !== $expectedcmids) {
                throw new \coding_exception(
                    "Final delegated sequence mismatch "
                    . "for {$ref}: expected "
                    . json_encode($expectedcmids)
                    . ', got '
                    . json_encode($actualmanaged)
                );
            }
        }

        /*
         * 5. Synthetic mappings must now be unique and exact.
         */
        foreach ($root as $entry) {
            if (($entry['kind'] ?? '')
                    !== 'synthetic_section') {
                continue;
            }

            $ref = (string) (
                $entry['synthetic_ref'] ?? ''
            );

            $targetid = (int) (
                $entry[
                    'target_section_id'
                ] ?? 0
            );

            $mapping = v2_find_mapping(
                $sourceinstance,
                $sourcecourse,
                $ref,
                'synthetic_section'
            );

            if (!$mapping
                    || (int) $mapping->targetid
                        !== $targetid) {
                throw new \coding_exception(
                    "Final synthetic mapping invalid "
                    . "for {$ref}."
                );
            }
        }

        $transaction->allow_commit();
    } catch (\Throwable $exception) {
        $transaction->rollback(
            $exception
        );
    }
} finally {
    if ($originaluser instanceof \stdClass) {
        \core\session\manager::set_user(
            $originaluser
        );
    }
}

$result = [
    'mode' => 'apply',
    'phase' => 'order-reconciliation-v2',
    'writes_performed' => true,
    'contract' => [
        'source_sha256' =>
            $actualsource,
        'plan_sha256' =>
            $actualplan,
    ],
    'course' => [
        'target_id' => $courseid,
        'source_ref_id' =>
            $sourcecourse,
    ],
    'root_apply' =>
        $rootresult,
    'delegated_apply' =>
        $delegatedresult,
    'current_after_apply' =>
        v2_snapshot($courseid),
];

echo json_encode(
    $result,
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
);

echo PHP_EOL;

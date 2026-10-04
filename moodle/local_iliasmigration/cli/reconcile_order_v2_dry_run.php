<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'source' => '',
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

if ($options['help']) {
    echo "ILIAS2Moodle order reconciliation V2 dry-run\n\n";
    echo "php local/iliasmigration/cli/reconcile_order_v2_dry_run.php \\\n";
    echo "  --source=/path/to/migration.json\n";
    exit(0);
}

$source = trim((string) $options['source']);

if ($source === '') {
    cli_error('Missing --source.');
}

$reader = new \local_iliasmigration\migration_reader();
$document = $reader->read($source);

global $DB;

/*
 * ------------------------------------------------------------
 * Source identity.
 * ------------------------------------------------------------
 */

$metadata = is_array(
    $document['course']['metadata'] ?? null
)
    ? $document['course']['metadata']
    : [];

$installationurl = trim(
    (string) ($metadata['installation_url'] ?? '')
);

$installationid = trim(
    (string) ($metadata['installation_id'] ?? '')
);

if ($installationurl !== '') {
    $sourceinstance = rtrim(
        $installationurl,
        '/'
    );
} else if ($installationid !== '') {
    $sourceinstance =
        'installation-id:' . $installationid;
} else {
    $sourceinstance =
        'unknown-ilias-instance';
}

$sourcecourse = (string) (
    $document['course']['source_id'] ?? ''
);

$blockers = [];
$ignored = [];
$itemindex = [];
$groupowners = [];

/*
 * ------------------------------------------------------------
 * Mapping helpers.
 * ------------------------------------------------------------
 */

$findmapping = function(
    string $ref,
    string $targettype
) use (
    $DB,
    $sourceinstance,
    $sourcecourse
) {
    $conditions = [
        'sourcelms' => 'ILIAS',
        'sourceinstance' => $sourceinstance,
        'sourcecourse' => $sourcecourse,
        'sourceref' => $ref,
        'targettype' => $targettype,
    ];

    $mapping = $DB->get_record(
        'local_iliasmigration_map',
        $conditions
    );

    if ($mapping) {
        return $mapping;
    }

    if ($sourceinstance === '') {
        return false;
    }

    $conditions['sourceinstance'] = '';

    return $DB->get_record(
        'local_iliasmigration_map',
        $conditions
    );
};

/*
 * ------------------------------------------------------------
 * Index every neutral item and Item Group membership.
 * ------------------------------------------------------------
 */

$indexwalk = null;

$indexwalk = function(
    array $items
) use (
    &$indexwalk,
    &$itemindex,
    &$groupowners,
    &$blockers
): void {
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $ref = trim(
            (string) ($item['source_id'] ?? '')
        );

        if ($ref !== '') {
            $itemindex[$ref] = $item;
        }

        if (($item['type'] ?? '') === 'itgr') {
            $md = is_array(
                $item['metadata'] ?? null
            )
                ? $item['metadata']
                : [];

            $unresolved = is_array(
                $md['item_group_unresolved_obj_ids'] ?? null
            )
                ? $md['item_group_unresolved_obj_ids']
                : [];

            if ($unresolved) {
                $blockers[] = [
                    'code' =>
                        'ITEM_GROUP_UNRESOLVED_MEMBERS',
                    'source_ref_id' => $ref,
                    'unresolved_obj_ids' =>
                        array_values($unresolved),
                ];
            }

            $members = is_array(
                $md['item_group_member_ref_ids'] ?? null
            )
                ? $md['item_group_member_ref_ids']
                : [];

            foreach ($members as $memberref) {
                $memberref = trim(
                    (string) $memberref
                );

                if ($memberref === '') {
                    continue;
                }

                if (isset($groupowners[$memberref])
                        && $groupowners[$memberref] !== $ref) {
                    $blockers[] = [
                        'code' =>
                            'ITEM_GROUP_MEMBER_MULTIPLE_OWNERS',
                        'source_ref_id' =>
                            $memberref,
                        'owners' => [
                            $groupowners[$memberref],
                            $ref,
                        ],
                    ];
                    continue;
                }

                $groupowners[$memberref] = $ref;
            }
        }

        $children = is_array(
            $item['items'] ?? null
        )
            ? $item['items']
            : [];

        $indexwalk($children);
    }
};

$indexwalk(
    $document['course']['items'] ?? []
);

/*
 * ------------------------------------------------------------
 * Resolve Moodle course.
 * ------------------------------------------------------------
 */

$coursemapping = $findmapping(
    $sourcecourse,
    'course'
);

$courseid = $coursemapping
    ? (int) $coursemapping->targetid
    : 0;

if ($courseid <= 0
        || !$DB->record_exists(
            'course',
            ['id' => $courseid]
        )) {
    $blockers[] = [
        'code' => 'COURSE_MAPPING_INVALID',
        'source_ref_id' => $sourcecourse,
    ];
}

/*
 * ------------------------------------------------------------
 * Utility: source order.
 * ------------------------------------------------------------
 */

$sourceorder = static function(
    array $items
): array {
    $items = array_values(
        array_filter(
            $items,
            'is_array'
        )
    );

    usort(
        $items,
        static fn(array $a, array $b): int =>
            (int) ($a['position'] ?? 0)
            <=>
            (int) ($b['position'] ?? 0)
    );

    return $items;
};

/*
 * ------------------------------------------------------------
 * Resolve one mapped activity.
 * ------------------------------------------------------------
 */

$typemap = [
    'file' => 'file',
    'url' => 'url',
    'html_module' => 'html_module',
    'scorm' => 'scorm',
    'learning_module' => 'book',
    'test' => 'quiz',
    'content_page' => 'page',
    'wiki' => 'wiki',
    'blog' => 'data',
    'mediacast' => 'data',
    'media_pool' => 'data',
    'forum' => 'forum',
    'glossary' => 'glossary',
];

$resolveone = function(
    array $item
) use (
    $DB,
    $courseid,
    $findmapping,
    &$blockers,
    $typemap
): ?array {
    $type = (string) (
        $item['type'] ?? ''
    );

    $ref = (string) (
        $item['source_id'] ?? ''
    );

    if (!isset($typemap[$type])) {
        $blockers[] = [
            'code' =>
                'VISIBLE_TYPE_ORDER_UNSUPPORTED',
            'source_ref_id' => $ref,
            'type' => $type,
        ];

        return null;
    }

    $targettype = $typemap[$type];

    $mapping = $findmapping(
        $ref,
        $targettype
    );

    if (!$mapping
            || empty($mapping->targetid)) {
        $blockers[] = [
            'code' =>
                'ACTIVITY_MAPPING_MISSING',
            'source_ref_id' => $ref,
            'type' => $type,
            'target_type' => $targettype,
        ];

        return null;
    }

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
        [(int) $mapping->targetid]
    );

    if (!$cm
            || (int) $cm->course !== $courseid) {
        $blockers[] = [
            'code' =>
                'ACTIVITY_MAPPING_STALE',
            'source_ref_id' => $ref,
            'target_id' =>
                (int) $mapping->targetid,
        ];

        return null;
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

    return [
        'source_ref_id' => $ref,
        'type' => $type,
        'target_type' => $targettype,
        'cmid' => (int) $cm->id,
        'module' =>
            (string) $cm->modulename,
        'title' =>
            (string) ($item['title'] ?? ''),
        'source_position' =>
            (int) ($item['position'] ?? 0),
        'current_section_id' =>
            (int) $section->id,
        'current_section_number' =>
            (int) $section->section,
    ];
};

/*
 * ------------------------------------------------------------
 * Exercise is one ILIAS object -> N Moodle Assign activities.
 * ------------------------------------------------------------
 */

$resolveexercise = function(
    array $item
) use (
    $DB,
    $courseid,
    $sourceinstance,
    $sourcecourse,
    &$blockers
): array {
    $ref = (string) (
        $item['source_id'] ?? ''
    );

    $select =
        'sourcelms = :lms
         AND sourceinstance = :instance
         AND sourcecourse = :course
         AND targettype = :targettype
         AND '
         . $DB->sql_like(
             'sourceref',
             ':pattern',
             false
         );

    $params = [
        'lms' => 'ILIAS',
        'instance' => $sourceinstance,
        'course' => $sourcecourse,
        'targettype' => 'assign',
        'pattern' =>
            $ref . ':assignment:%',
    ];

    $maps = $DB->get_records_select(
        'local_iliasmigration_map',
        $select,
        $params,
        '',
        'id,sourceref,targetid'
    );

    if (!$maps && $sourceinstance !== '') {
        $params['instance'] = '';

        $maps = $DB->get_records_select(
            'local_iliasmigration_map',
            $select,
            $params,
            '',
            'id,sourceref,targetid'
        );
    }

    $maps = array_values($maps);

    usort(
        $maps,
        static function(
            \stdClass $a,
            \stdClass $b
        ): int {
            preg_match(
                '/:assignment:(\d+)$/',
                (string) $a->sourceref,
                $ma
            );

            preg_match(
                '/:assignment:(\d+)$/',
                (string) $b->sourceref,
                $mb
            );

            return (int) ($ma[1] ?? 0)
                <=>
                (int) ($mb[1] ?? 0);
        }
    );

    if (!$maps) {
        $blockers[] = [
            'code' =>
                'EXERCISE_ASSIGNMENT_MAPPING_MISSING',
            'source_ref_id' => $ref,
        ];

        return [];
    }

    $result = [];

    foreach ($maps as $map) {
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
            [(int) $map->targetid]
        );

        if (!$cm
                || (int) $cm->course !== $courseid
                || (string) $cm->modulename !== 'assign') {
            $blockers[] = [
                'code' =>
                    'EXERCISE_ASSIGNMENT_MAPPING_STALE',
                'source_ref_id' =>
                    (string) $map->sourceref,
                'target_id' =>
                    (int) $map->targetid,
            ];

            continue;
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

        $result[] = [
            'source_ref_id' =>
                (string) $map->sourceref,
            'source_parent_ref_id' => $ref,
            'type' =>
                'exercise_assignment',
            'target_type' => 'assign',
            'cmid' => (int) $cm->id,
            'module' => 'assign',
            'title' =>
                (string) ($item['title'] ?? ''),
            'source_position' =>
                (int) ($item['position'] ?? 0),
            'current_section_id' =>
                (int) $section->id,
            'current_section_number' =>
                (int) $section->section,
        ];
    }

    return $result;
};

/*
 * ------------------------------------------------------------
 * Resolve one source item to 0..N Moodle activities.
 * ------------------------------------------------------------
 */

$resolveentries = function(
    array $item
) use (
    $resolveone,
    $resolveexercise,
    &$ignored
): array {
    $type = (string) (
        $item['type'] ?? ''
    );

    $ref = (string) (
        $item['source_id'] ?? ''
    );

    if ($type === 'question_pool') {
        $ignored[] = [
            'source_ref_id' => $ref,
            'type' => $type,
            'reason' =>
                'Moodle qbank is non-displayable.',
        ];

        return [];
    }

    if ($type === 'exercise') {
        return $resolveexercise($item);
    }

    $supported = [
        'file',
        'url',
        'html_module',
        'scorm',
        'learning_module',
        'test',
        'content_page',
        'wiki',
        'blog',
        'mediacast',
        'media_pool',
        'forum',
        'glossary',
    ];

    if (!in_array(
        $type,
        $supported,
        true
    )) {
        $ignored[] = [
            'source_ref_id' => $ref,
            'type' => $type,
            'reason' =>
                'Item is not a visible activity in V2.',
        ];

        return [];
    }

    $resolved = $resolveone($item);

    return $resolved
        ? [$resolved]
        : [];
};

/*
 * ------------------------------------------------------------
 * Resolve Item Group members in authoritative member order.
 * ------------------------------------------------------------
 */

$resolvegroupmembers = function(
    array $group
) use (
    &$itemindex,
    $resolveentries,
    &$blockers
): array {
    $ref = (string) (
        $group['source_id'] ?? ''
    );

    $md = is_array(
        $group['metadata'] ?? null
    )
        ? $group['metadata']
        : [];

    $memberrefs = is_array(
        $md['item_group_member_ref_ids'] ?? null
    )
        ? $md['item_group_member_ref_ids']
        : [];

    $result = [];

    foreach ($memberrefs as $memberref) {
        $memberref = (string) $memberref;

        if (!isset($itemindex[$memberref])) {
            $blockers[] = [
                'code' =>
                    'ITEM_GROUP_MEMBER_SOURCE_MISSING',
                'source_ref_id' => $memberref,
                'group_ref_id' => $ref,
            ];

            continue;
        }

        foreach (
            $resolveentries(
                $itemindex[$memberref]
            ) as $entry
        ) {
            $entry['item_group_ref_id'] = $ref;
            $result[] = $entry;
        }
    }

    return $result;
};

/*
 * ------------------------------------------------------------
 * Resolve regular section mapping.
 * ------------------------------------------------------------
 */

$resolvesection = function(
    string $ref,
    string $title,
    string $kind,
    array $items
) use (
    $DB,
    $courseid,
    $findmapping,
    &$blockers
): ?array {
    $mapping = $findmapping(
        $ref,
        'section'
    );

    if (!$mapping
            || empty($mapping->targetid)) {
        $blockers[] = [
            'code' =>
                'SECTION_MAPPING_MISSING',
            'source_ref_id' => $ref,
            'kind' => $kind,
        ];

        return null;
    }

    $section = $DB->get_record(
        'course_sections',
        [
            'id' => (int) $mapping->targetid,
            'course' => $courseid,
        ],
        'id,section,name,component,itemid',
        IGNORE_MISSING
    );

    if (!$section || !empty($section->component)) {
        $blockers[] = [
            'code' =>
                'SECTION_MAPPING_STALE',
            'source_ref_id' => $ref,
            'target_id' =>
                (int) $mapping->targetid,
        ];

        return null;
    }

    return [
        'kind' => $kind,
        'source_ref_id' => $ref,
        'title' => $title,
        'target_section_id' =>
            (int) $section->id,
        'current_section_number' =>
            (int) $section->section,
        'action' => 'REUSE',
        'items' => $items,
    ];
};

/*
 * ------------------------------------------------------------
 * Resolve one Moodle subsection and delegated section.
 * ------------------------------------------------------------
 */

$resolvesubsectionrecord = function(
    string $ref,
    string $title,
    string $kind,
    array $items
) use (
    $DB,
    $courseid,
    $findmapping,
    &$blockers
): ?array {
    $mapping = $findmapping(
        $ref,
        'subsection'
    );

    if (!$mapping
            || empty($mapping->targetid)) {
        $blockers[] = [
            'code' =>
                'SUBSECTION_MAPPING_MISSING',
            'source_ref_id' => $ref,
            'kind' => $kind,
        ];

        return null;
    }

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
        [(int) $mapping->targetid]
    );

    if (!$cm
            || (int) $cm->course !== $courseid
            || (string) $cm->modulename !== 'subsection') {
        $blockers[] = [
            'code' =>
                'SUBSECTION_MAPPING_STALE',
            'source_ref_id' => $ref,
            'target_id' =>
                (int) $mapping->targetid,
        ];

        return null;
    }

    $delegated = $DB->get_record(
        'course_sections',
        [
            'course' => $courseid,
            'component' => 'mod_subsection',
            'itemid' => (int) $cm->instance,
        ],
        'id,section,name,component,itemid',
        IGNORE_MISSING
    );

    if (!$delegated) {
        $blockers[] = [
            'code' =>
                'DELEGATED_SECTION_MISSING',
            'source_ref_id' => $ref,
            'subsection_cmid' =>
                (int) $cm->id,
        ];

        return null;
    }

    return [
        'source_ref_id' => $ref,
        'kind' => $kind,
        'cmid' => (int) $cm->id,
        'title' => $title,
        'parent_section_id' =>
            (int) $cm->section,
        'delegated_section_id' =>
            (int) $delegated->id,
        'delegated_section_number' =>
            (int) $delegated->section,
        'items' => $items,
    ];
};

/*
 * ------------------------------------------------------------
 * Build a folder subsection.
 *
 * IMPORTANT: children are NOT re-sorted here.
 * folder_flattener already produced their authoritative Moodle order.
 * ------------------------------------------------------------
 */

$buildfoldersubsection = null;

$buildfoldersubsection = function(
    array $folder
) use (
    &$buildfoldersubsection,
    &$groupowners,
    $resolveentries,
    $resolvegroupmembers,
    $resolvesubsectionrecord,
    &$ignored
): ?array {
    $items = [];

    foreach (
        (array) ($folder['items'] ?? [])
        as $child
    ) {
        if (!is_array($child)) {
            continue;
        }

        $ref = (string) (
            $child['source_id'] ?? ''
        );

        $type = (string) (
            $child['type'] ?? ''
        );

        if (isset($groupowners[$ref])) {
            $ignored[] = [
                'source_ref_id' => $ref,
                'type' => $type,
                'reason' =>
                    'Owned by Item Group '
                    . $groupowners[$ref],
            ];

            continue;
        }

        if ($type === 'folder') {
            $nested =
                $buildfoldersubsection($child);

            if ($nested) {
                $items[] = [
                    'source_ref_id' =>
                        $nested['source_ref_id'],
                    'type' => 'folder',
                    'cmid' =>
                        $nested['cmid'],
                    'title' =>
                        $nested['title'],
                ];
            }

            continue;
        }

        if ($type === 'itgr') {
            $groupitems =
                $resolvegroupmembers($child);

            $group =
                $resolvesubsectionrecord(
                    $ref,
                    (string) (
                        $child['title'] ?? ''
                    ),
                    'item_group_subsection',
                    $groupitems
                );

            if ($group) {
                $items[] = [
                    'source_ref_id' => $ref,
                    'type' => 'itgr',
                    'cmid' =>
                        $group['cmid'],
                    'title' =>
                        $group['title'],
                ];
            }

            continue;
        }

        foreach (
            $resolveentries($child)
            as $entry
        ) {
            $items[] = $entry;
        }
    }

    return $resolvesubsectionrecord(
        (string) (
            $folder['source_id'] ?? ''
        ),
        (string) (
            $folder['title'] ?? ''
        ),
        'folder_subsection',
        $items
    );
};

/*
 * ------------------------------------------------------------
 * Build first-level source folder.
 * ------------------------------------------------------------
 */

$delegated = [];

$buildfoldersection = function(
    array $folder
) use (
    &$groupowners,
    $resolveentries,
    $resolvegroupmembers,
    $resolvesection,
    $resolvesubsectionrecord,
    $buildfoldersubsection,
    &$delegated,
    &$ignored
): ?array {
    $items = [];

    /*
     * Preserve array order from folder_flattener.
     * Do NOT source_order() this array.
     */
    foreach (
        (array) ($folder['items'] ?? [])
        as $child
    ) {
        if (!is_array($child)) {
            continue;
        }

        $ref = (string) (
            $child['source_id'] ?? ''
        );

        $type = (string) (
            $child['type'] ?? ''
        );

        if (isset($groupowners[$ref])) {
            $ignored[] = [
                'source_ref_id' => $ref,
                'type' => $type,
                'reason' =>
                    'Owned by Item Group '
                    . $groupowners[$ref],
            ];

            continue;
        }

        if ($type === 'folder') {
            $sub =
                $buildfoldersubsection($child);

            if ($sub) {
                $delegated[] = $sub;

                $items[] = [
                    'source_ref_id' =>
                        $sub['source_ref_id'],
                    'type' => 'folder',
                    'cmid' => $sub['cmid'],
                    'title' => $sub['title'],
                ];
            }

            continue;
        }

        if ($type === 'itgr') {
            $groupitems =
                $resolvegroupmembers($child);

            $group =
                $resolvesubsectionrecord(
                    $ref,
                    (string) (
                        $child['title'] ?? ''
                    ),
                    'item_group_subsection',
                    $groupitems
                );

            if ($group) {
                $delegated[] = $group;

                $items[] = [
                    'source_ref_id' => $ref,
                    'type' => 'itgr',
                    'cmid' =>
                        $group['cmid'],
                    'title' =>
                        $group['title'],
                ];
            }

            continue;
        }

        foreach (
            $resolveentries($child)
            as $entry
        ) {
            $items[] = $entry;
        }
    }

    return $resolvesection(
        (string) (
            $folder['source_id'] ?? ''
        ),
        (string) (
            $folder['title'] ?? ''
        ),
        'source_section',
        $items
    );
};

/*
 * ------------------------------------------------------------
 * Root traversal.
 * ------------------------------------------------------------
 */

$toplevel = [];
$sectionzero = [];
$segment = [];
$seenstructure = false;
$segmentindex = 0;

$flushsegment = function()
    use (
        &$segment,
        &$sectionzero,
        &$toplevel,
        &$segmentindex,
        &$seenstructure,
        $findmapping,
        $DB,
        $courseid
    ): void {
    if (!$segment) {
        return;
    }

    if (!$seenstructure) {
        $sectionzero = array_merge(
            $sectionzero,
            $segment
        );

        $segment = [];
        return;
    }

    $segmentindex++;

    $syntheticref =
        '__root_segment_' . $segmentindex;

    $mapping = $findmapping(
        $syntheticref,
        'synthetic_section'
    );

    $targetid = null;
    $current = null;
    $action = 'CREATE';

    if ($mapping
            && !empty($mapping->targetid)) {
        $section = $DB->get_record(
            'course_sections',
            [
                'id' =>
                    (int) $mapping->targetid,
                'course' => $courseid,
            ],
            'id,section,component,itemid',
            IGNORE_MISSING
        );

        if ($section
                && empty($section->component)) {
            $targetid =
                (int) $section->id;

            $current =
                (int) $section->section;

            $action = 'REUSE';
        }
    }

    $toplevel[] = [
        'kind' => 'synthetic_section',
        'synthetic_ref' => $syntheticref,
        'title' =>
            $segmentindex === 1
                ? 'Contenu'
                : 'Contenu ' . $segmentindex,
        'target_section_id' =>
            $targetid,
        'current_section_number' =>
            $current,
        'action' => $action,
        'items' => $segment,
    ];

    $segment = [];
};

foreach (
    $sourceorder(
        $document['course']['items'] ?? []
    )
    as $item
) {
    $ref = (string) (
        $item['source_id'] ?? ''
    );

    $type = (string) (
        $item['type'] ?? ''
    );

    /*
     * Item Group members are emitted only from their group.
     */
    if (isset($groupowners[$ref])) {
        $ignored[] = [
            'source_ref_id' => $ref,
            'type' => $type,
            'reason' =>
                'Owned by Item Group '
                . $groupowners[$ref],
        ];

        continue;
    }

    if ($type === 'folder') {
        $flushsegment();

        $section =
            $buildfoldersection($item);

        if ($section) {
            $toplevel[] = $section;
        }

        $seenstructure = true;
        continue;
    }

    if ($type === 'itgr') {
        $flushsegment();

        $groupitems =
            $resolvegroupmembers($item);

        $section = $resolvesection(
            $ref,
            (string) (
                $item['title'] ?? ''
            ),
            'item_group_section',
            $groupitems
        );

        if ($section) {
            $toplevel[] = $section;
        }

        $seenstructure = true;
        continue;
    }

    foreach (
        $resolveentries($item)
        as $entry
    ) {
        $segment[] = $entry;
    }
}

$flushsegment();

/*
 * ------------------------------------------------------------
 * Desired top-level regular section numbers.
 * ------------------------------------------------------------
 */

$desiredposition = 0;

foreach ($toplevel as &$entry) {
    $desiredposition++;

    $entry['desired_section_number'] =
        $desiredposition;
}
unset($entry);

/*
 * ------------------------------------------------------------
 * Snapshot current Moodle structure.
 * ------------------------------------------------------------
 */

$current = [];

if ($courseid > 0) {
    foreach (
        $DB->get_records(
            'course_sections',
            ['course' => $courseid],
            'section ASC',
            'id,section,name,sequence,component,itemid'
        )
        as $section
    ) {
        $current[] = [
            'id' =>
                (int) $section->id,
            'section' =>
                (int) $section->section,
            'name' =>
                $section->name,
            'component' =>
                $section->component,
            'itemid' =>
                $section->itemid,
            'sequence' =>
                trim(
                    (string) $section->sequence
                ) === ''
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
}

$result = [
    'mode' => 'dry-run',
    'writes_performed' => false,
    'version' =>
        'order-reconciler-v2-prototype',
    'source' => [
        'instance' => $sourceinstance,
        'course_ref_id' => $sourcecourse,
        'migration_json' =>
            realpath($source) ?: $source,
        'sha256' =>
            hash_file(
                'sha256',
                realpath($source) ?: $source
            ),
    ],
    'course' => [
        'target_id' =>
            $courseid ?: null,
    ],
    'order_reconciliation_v2' => [
        'ready' => empty($blockers),
        'apply_implemented' => false,
        'section_zero' => [
            'items' => $sectionzero,
        ],
        'top_level' => $toplevel,
        'delegated_sections' =>
            $delegated,
        'item_group_owners' =>
            $groupowners,
        'ignored' => $ignored,
        'blockers' => $blockers,
        'current' => $current,
        'policy' => [
            'item_group_members' =>
                'EMIT_ONLY_INSIDE_OWNER_GROUP',
            'folder_flatten_order' =>
                'PRESERVE_NORMALIZED_ARRAY_ORDER',
            'root_activity_segments' =>
                'SYNTHETIC_SECTION_PLAN_ONLY',
            'apply' =>
                'DISABLED',
        ],
    ],
];

echo json_encode(
    $result,
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
);

echo PHP_EOL;

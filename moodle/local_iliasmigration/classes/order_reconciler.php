<?php

namespace local_iliasmigration;

use core_courseformat\formatactions;

defined('MOODLE_INTERNAL') || die();

/**
 * Reconciles the visible Moodle course order with the neutral ILIAS tree.
 *
 * This is intentionally separate from the phase executors. Phases 2-6 create
 * and validate their own objects; this final structure pass uses the persistent
 * mappings to restore the cross-phase order without recreating content.
 *
 * Unmanaged Moodle activities are never moved. Non-displayable mapped modules
 * such as mod_qbank remain in section 0 as required by Moodle.
 */
final class order_reconciler {
    /** @var string Absolute migration.json path. */
    private string $migrationjson;

    /** @var string Stable ILIAS source instance identity. */
    private string $sourceinstance = '';

    /** @var string ILIAS course ref_id. */
    private string $sourcecourseid = '';

    /** @var int Moodle target course id. */
    private int $courseid = 0;

    /** @var array Blocking diagnostics accumulated while building the plan. */
    private array $blockers = [];

    /** @var array Informational entries excluded from visible ordering. */
    private array $ignored = [];

    /**
     * @param string $migrationjson Absolute migration.json path.
     */
    public function __construct(string $migrationjson) {
        $file = realpath($migrationjson);
        if ($file === false || !is_file($file)) {
            throw new \coding_exception('Unable to resolve migration.json for order reconciliation.');
        }
        $this->migrationjson = $file;
    }

    /**
     * Build a read-only reconciliation plan.
     *
     * @param array $document Validated neutral migration document.
     * @return array
     */
    public function build(array $document): array {
        global $DB;

        $this->blockers = [];
        $this->ignored = [];
        $this->sourceinstance = $this->source_instance($document);
        $this->sourcecourseid = (string) ($document['course']['source_id'] ?? '');
        if ($this->sourcecourseid === '') {
            throw new \coding_exception('Migration document has no source course id.');
        }

        $coursemapping = $this->find_mapping($this->sourcecourseid, 'course');
        if (!$coursemapping || empty($coursemapping->targetid)) {
            $this->blockers[] = [
                'code' => 'COURSE_MAPPING_MISSING',
                'source_ref_id' => $this->sourcecourseid,
            ];
            $this->courseid = 0;
        } else {
            $this->courseid = (int) $coursemapping->targetid;
            if (!$DB->record_exists('course', ['id' => $this->courseid])) {
                $this->blockers[] = [
                    'code' => 'COURSE_MAPPING_STALE',
                    'target_id' => $this->courseid,
                ];
            }
        }

        $sectionzero = [];
        $toplevel = [];
        $delegated = [];
        $segment = [];
        $seenfolder = false;
        $segmentindex = 0;

        $rootitems = $this->source_order($document['course']['items'] ?? []);
        foreach ($rootitems as $item) {
            $type = (string) ($item['type'] ?? '');

            if ($type === 'folder') {
                if ($segment) {
                    if ($seenfolder) {
                        $segmentindex++;
                        $toplevel[] = $this->build_synthetic_segment_plan($segmentindex, $segment);
                    } else {
                        $sectionzero = array_merge($sectionzero, $this->resolve_visible_items($segment));
                    }
                    $segment = [];
                }

                $folderplan = $this->build_level_one_folder_plan($item, $delegated);
                if ($folderplan !== null) {
                    $toplevel[] = $folderplan;
                }
                $seenfolder = true;
                continue;
            }

            if ($this->is_visible_activity_type($type)) {
                $segment[] = $item;
                continue;
            }

            $this->ignore_nonvisible_or_unknown($item, 0);
        }

        if ($segment) {
            if ($seenfolder) {
                $segmentindex++;
                $toplevel[] = $this->build_synthetic_segment_plan($segmentindex, $segment);
            } else {
                $sectionzero = array_merge($sectionzero, $this->resolve_visible_items($segment));
            }
        }

        $this->validate_existing_regular_section_order($toplevel);

        $current = $this->snapshot_current_course();

        return [
            'mode' => 'dry-run',
            'writes_performed' => false,
            'source' => [
                'instance' => $this->sourceinstance,
                'course_ref_id' => $this->sourcecourseid,
                'migration_json' => $this->migrationjson,
            ],
            'course' => [
                'target_id' => $this->courseid ?: null,
            ],
            'order_reconciliation' => [
                'ready' => empty($this->blockers),
                'section_zero' => [
                    'desired_migrated_cmids' => array_column($sectionzero, 'cmid'),
                    'items' => $sectionzero,
                ],
                'top_level' => $toplevel,
                'delegated_sections' => $delegated,
                'ignored' => $this->ignored,
                'blockers' => $this->blockers,
                'current' => $current,
                'policy' => [
                    'unmanaged_modules' => 'preserve_relative_order',
                    'mapped_non_displayable_modules' => 'leave_in_section_0',
                    'root_prefix_before_first_folder' => 'section_0',
                    'root_activity_runs_after_a_folder' => 'synthetic_section',
                    'synthetic_section_title' => 'Contenu / Contenu N',
                ],
            ],
        ];
    }

    /**
     * Apply a previously derivable safe order reconciliation.
     *
     * @param array $document Validated neutral migration document.
     * @return array
     */
    public function execute(array $document): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        $plan = $this->build($document);
        if (empty($plan['order_reconciliation']['ready'])) {
            throw new \coding_exception('Order reconciliation plan is blocked; apply is refused.');
        }

        $course = $DB->get_record('course', ['id' => $this->courseid], '*', MUST_EXIST);
        $originaluser = $USER;
        \core\session\manager::set_user(get_admin());

        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $top = $plan['order_reconciliation']['top_level'];

                // Synthetic sections are created only after the complete source
                // segment is mapped. They are plugin-owned structural helpers.
                foreach ($top as $index => $entry) {
                    if (($entry['kind'] ?? '') !== 'synthetic_section') {
                        continue;
                    }
                    if (!empty($entry['target_section_id'])) {
                        continue;
                    }

                    $created = course_create_section($course, 0);
                    rebuild_course_cache($course->id, true);
                    $sectioninfo = get_fast_modinfo($course->id)->get_section_info_by_id((int) $created->id);
                    if (!$sectioninfo) {
                        throw new \coding_exception('Unable to resolve newly created synthetic section.');
                    }
                    formatactions::section($course)->update(
                        $sectioninfo,
                        ['name' => (string) $entry['title']]
                    );
                    $this->save_synthetic_mapping(
                        (string) $entry['synthetic_ref'],
                        (int) $created->id
                    );
                    $top[$index]['target_section_id'] = (int) $created->id;
                    $top[$index]['action'] = 'CREATED';
                }

                // The first implementation is intentionally guarded: existing
                // source sections must already be in source order. Synthetic
                // sections can be appended after the final source folder, which
                // covers the validated v5 POC without risking delegated sections.
                $this->assert_runtime_regular_section_order($top);

                // Move tail/root segments first so section 0 contains only the
                // source prefix plus unmanaged/non-displayable Moodle modules.
                foreach ($top as $entry) {
                    if (($entry['kind'] ?? '') !== 'synthetic_section') {
                        continue;
                    }
                    $sectionid = (int) ($entry['target_section_id'] ?? 0);
                    $this->reorder_section_managed_cmids(
                        $course,
                        $sectionid,
                        array_column($entry['items'] ?? [], 'cmid')
                    );
                }

                // Restore exact order inside source folder sections.
                foreach ($top as $entry) {
                    if (($entry['kind'] ?? '') !== 'source_section') {
                        continue;
                    }
                    $this->reorder_section_managed_cmids(
                        $course,
                        (int) $entry['target_section_id'],
                        array_column($entry['items'] ?? [], 'cmid')
                    );
                }

                // Restore exact order inside delegated subsection sections.
                foreach ($plan['order_reconciliation']['delegated_sections'] as $entry) {
                    $this->reorder_section_managed_cmids(
                        $course,
                        (int) $entry['target_section_id'],
                        array_column($entry['items'] ?? [], 'cmid')
                    );
                }

                // Finally restore the visible source prefix in Moodle section 0.
                $sectionzero = $DB->get_record(
                    'course_sections',
                    ['course' => $course->id, 'section' => 0],
                    'id,course,section,sequence',
                    MUST_EXIST
                );
                $this->reorder_section_managed_cmids(
                    $course,
                    (int) $sectionzero->id,
                    $plan['order_reconciliation']['section_zero']['desired_migrated_cmids']
                );

                rebuild_course_cache($course->id, true);
                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
        } finally {
            if ($originaluser instanceof \stdClass) {
                \core\session\manager::set_user($originaluser);
            }
        }

        $result = $this->build($document);
        $result['mode'] = 'apply';
        $result['writes_performed'] = true;
        $result['order_reconciliation']['current_after_apply'] = $this->snapshot_current_course();
        return $result;
    }

    /** Build one first-level source folder plan. */
    private function build_level_one_folder_plan(array $item, array &$delegated): ?array {
        global $DB;

        $ref = (string) ($item['source_id'] ?? '');
        $mapping = $this->find_mapping($ref, 'section');
        if (!$mapping || empty($mapping->targetid)) {
            $this->blockers[] = [
                'code' => 'SECTION_MAPPING_MISSING',
                'source_ref_id' => $ref,
            ];
            return null;
        }

        $section = $DB->get_record(
            'course_sections',
            ['id' => (int) $mapping->targetid, 'course' => $this->courseid],
            'id,course,section,name,component,itemid',
            IGNORE_MISSING
        );
        if (!$section) {
            $this->blockers[] = [
                'code' => 'SECTION_MAPPING_STALE',
                'source_ref_id' => $ref,
                'target_id' => (int) $mapping->targetid,
            ];
            return null;
        }

        $items = [];
        foreach ($this->source_order($item['items'] ?? []) as $child) {
            $type = (string) ($child['type'] ?? '');
            if ($type === 'folder') {
                $sub = $this->resolve_subsection($child, $delegated);
                if ($sub !== null) {
                    $items[] = $sub;
                }
                continue;
            }
            if ($this->is_visible_activity_type($type)) {
                $resolved = $this->resolve_visible_item($child);
                if ($resolved !== null) {
                    $items[] = $resolved;
                }
                continue;
            }
            $this->ignore_nonvisible_or_unknown($child, 1);
        }

        return [
            'kind' => 'source_section',
            'source_ref_id' => $ref,
            'title' => (string) ($item['title'] ?? ''),
            'source_position' => (int) ($item['position'] ?? 0),
            'target_section_id' => (int) $section->id,
            'current_section_number' => (int) $section->section,
            'action' => 'REUSE',
            'items' => $items,
        ];
    }

    /** Resolve a level-two source folder and its delegated Moodle section. */
    private function resolve_subsection(array $item, array &$delegated): ?array {
        global $DB;

        $ref = (string) ($item['source_id'] ?? '');
        $mapping = $this->find_mapping($ref, 'subsection');
        if (!$mapping || empty($mapping->targetid)) {
            $this->blockers[] = [
                'code' => 'SUBSECTION_MAPPING_MISSING',
                'source_ref_id' => $ref,
            ];
            return null;
        }

        $cm = $DB->get_record(
            'course_modules',
            ['id' => (int) $mapping->targetid, 'course' => $this->courseid],
            'id,course,instance,section',
            IGNORE_MISSING
        );
        if (!$cm) {
            $this->blockers[] = [
                'code' => 'SUBSECTION_MAPPING_STALE',
                'source_ref_id' => $ref,
                'target_id' => (int) $mapping->targetid,
            ];
            return null;
        }

        $delegatedsection = $DB->get_record(
            'course_sections',
            [
                'course' => $this->courseid,
                'component' => 'mod_subsection',
                'itemid' => (int) $cm->instance,
            ],
            'id,course,section,name',
            IGNORE_MISSING
        );
        if (!$delegatedsection) {
            $this->blockers[] = [
                'code' => 'DELEGATED_SECTION_MISSING',
                'source_ref_id' => $ref,
                'subsection_cmid' => (int) $cm->id,
            ];
        } else {
            $children = [];
            foreach ($this->source_order($item['items'] ?? []) as $child) {
                $type = (string) ($child['type'] ?? '');
                if ($type === 'folder') {
                    $this->blockers[] = [
                        'code' => 'FOLDER_DEPTH_GT_2_ORDER_UNRESOLVED',
                        'source_ref_id' => (string) ($child['source_id'] ?? ''),
                        'parent_source_ref_id' => $ref,
                    ];
                    continue;
                }
                if ($this->is_visible_activity_type($type)) {
                    $resolved = $this->resolve_visible_item($child);
                    if ($resolved !== null) {
                        $children[] = $resolved;
                    }
                    continue;
                }
                $this->ignore_nonvisible_or_unknown($child, 2);
            }
            $delegated[] = [
                'kind' => 'delegated_section',
                'source_ref_id' => $ref,
                'target_section_id' => (int) $delegatedsection->id,
                'current_section_number' => (int) $delegatedsection->section,
                'items' => $children,
            ];
        }

        return [
            'source_ref_id' => $ref,
            'type' => 'folder',
            'target_type' => 'subsection',
            'cmid' => (int) $cm->id,
            'title' => (string) ($item['title'] ?? ''),
            'source_position' => (int) ($item['position'] ?? 0),
        ];
    }

    /** Build a synthetic top-level section for one root activity run. */
    private function build_synthetic_segment_plan(int $index, array $items): array {
        global $DB;

        $syntheticref = '__root_segment_' . $index;
        $mapping = $this->find_mapping($syntheticref, 'synthetic_section');
        $sectionid = null;
        $sectionnumber = null;
        $action = 'CREATE';

        if ($mapping && !empty($mapping->targetid)) {
            $section = $DB->get_record(
                'course_sections',
                ['id' => (int) $mapping->targetid, 'course' => $this->courseid],
                'id,section,component,itemid',
                IGNORE_MISSING
            );
            if (!$section) {
                $this->blockers[] = [
                    'code' => 'SYNTHETIC_SECTION_MAPPING_STALE',
                    'synthetic_ref' => $syntheticref,
                    'target_id' => (int) $mapping->targetid,
                ];
            } else if (!empty($section->component)) {
                $this->blockers[] = [
                    'code' => 'SYNTHETIC_SECTION_IS_DELEGATED',
                    'synthetic_ref' => $syntheticref,
                    'target_id' => (int) $mapping->targetid,
                ];
            } else {
                $sectionid = (int) $section->id;
                $sectionnumber = (int) $section->section;
                $action = 'REUSE';
            }
        }

        return [
            'kind' => 'synthetic_section',
            'synthetic_ref' => $syntheticref,
            'title' => $index === 1 ? 'Contenu' : 'Contenu ' . $index,
            'target_section_id' => $sectionid,
            'current_section_number' => $sectionnumber,
            'action' => $action,
            'items' => $this->resolve_visible_items($items),
        ];
    }

    /** Resolve several visible activities preserving source order. */
    private function resolve_visible_items(array $items): array {
        $result = [];
        foreach ($this->source_order($items) as $item) {
            $resolved = $this->resolve_visible_item($item);
            if ($resolved !== null) {
                $result[] = $resolved;
            }
        }
        return $result;
    }

    /** Resolve one source activity to its mapped Moodle CMID. */
    private function resolve_visible_item(array $item): ?array {
        global $DB;

        $type = (string) ($item['type'] ?? '');
        $ref = (string) ($item['source_id'] ?? '');
        $targettype = $this->mapping_target_type($type);
        if ($targettype === null) {
            $this->blockers[] = [
                'code' => 'VISIBLE_TYPE_ORDER_UNSUPPORTED',
                'source_ref_id' => $ref,
                'type' => $type,
            ];
            return null;
        }

        $mapping = $this->find_mapping($ref, $targettype);
        if (!$mapping || empty($mapping->targetid)) {
            $this->blockers[] = [
                'code' => 'ACTIVITY_MAPPING_MISSING',
                'source_ref_id' => $ref,
                'type' => $type,
                'target_type' => $targettype,
            ];
            return null;
        }

        $cm = $DB->get_record(
            'course_modules',
            ['id' => (int) $mapping->targetid, 'course' => $this->courseid],
            'id,course,module,instance,section',
            IGNORE_MISSING
        );
        if (!$cm) {
            $this->blockers[] = [
                'code' => 'ACTIVITY_MAPPING_STALE',
                'source_ref_id' => $ref,
                'target_id' => (int) $mapping->targetid,
            ];
            return null;
        }

        return [
            'source_ref_id' => $ref,
            'type' => $type,
            'target_type' => $targettype,
            'cmid' => (int) $cm->id,
            'title' => (string) ($item['title'] ?? ''),
            'source_position' => (int) ($item['position'] ?? 0),
        ];
    }

    /**
     * Reorder only mapped migration CMIDs in a target section.
     *
     * moveto_module() is a Moodle core course API. Calling it in source order
     * moves each managed module to the section end, so unmanaged modules retain
     * their own relative order while migrated modules become contiguous and
     * source-ordered.
     */
    private function reorder_section_managed_cmids(\stdClass $course, int $sectionid, array $cmids): void {
        global $DB;

        if (!$cmids) {
            return;
        }
        $section = $DB->get_record(
            'course_sections',
            ['id' => $sectionid, 'course' => $course->id],
            '*',
            MUST_EXIST
        );

        foreach ($cmids as $cmid) {
            $cmid = (int) $cmid;
            $cm = get_fast_modinfo($course->id)->get_cm($cmid);
            moveto_module($cm, $section, null);
            rebuild_course_cache($course->id, true);
        }
    }

    /**
     * Guard the first release against moving existing regular source sections.
     *
     * The current v5 POC has one source section and one synthetic tail segment.
     * This validates the riskier activity reordering and synthetic-section model
     * before support for alternating multiple first-level source folders is enabled.
     */
    private function validate_existing_regular_section_order(array $toplevel): void {
        global $DB;

        if ($this->courseid <= 0) {
            return;
        }

        $expected = [];
        foreach ($toplevel as $entry) {
            if (($entry['kind'] ?? '') === 'source_section' && !empty($entry['target_section_id'])) {
                $expected[] = (int) $entry['target_section_id'];
            }
        }
        if (!$expected) {
            return;
        }

        $records = $DB->get_records_select(
            'course_sections',
            'course = :course AND section > 0 AND (component IS NULL OR component = :empty)',
            ['course' => $this->courseid, 'empty' => ''],
            'section ASC',
            'id,section,component,itemid'
        );
        $actualsource = [];
        foreach ($records as $record) {
            if (in_array((int) $record->id, $expected, true)) {
                $actualsource[] = (int) $record->id;
            }
        }
        if ($actualsource !== $expected) {
            $this->blockers[] = [
                'code' => 'TOP_LEVEL_SOURCE_SECTION_REORDER_NOT_ENABLED',
                'expected_section_ids' => $expected,
                'current_section_ids' => $actualsource,
            ];
        }

        $seencreate = false;
        foreach ($toplevel as $entry) {
            if (($entry['kind'] ?? '') === 'synthetic_section') {
                $seencreate = true;
                continue;
            }
            if ($seencreate && ($entry['kind'] ?? '') === 'source_section') {
                $this->blockers[] = [
                    'code' => 'SYNTHETIC_SEGMENT_BETWEEN_SOURCE_SECTIONS_NOT_ENABLED',
                    'message' => 'First release only supports synthetic root segments after the final source folder.',
                ];
                break;
            }
        }
    }

    /** Runtime equivalent of the guarded regular-section policy. */
    private function assert_runtime_regular_section_order(array $toplevel): void {
        $before = count($this->blockers);
        $this->validate_existing_regular_section_order($toplevel);
        if (count($this->blockers) > $before) {
            throw new \coding_exception('Top-level source section order changed during reconciliation; apply aborted.');
        }
    }

    /** Save/reuse a plugin-owned synthetic section mapping. */
    private function save_synthetic_mapping(string $syntheticref, int $sectionid): void {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $this->sourcecourseid,
            'sourceref' => $syntheticref,
            'targettype' => 'synthetic_section',
        ];
        $existing = $DB->get_record('local_iliasmigration_map', $conditions);
        $now = time();
        $record = (object) ($conditions + [
            'sourceversion' => null,
            'sourceobj' => null,
            'targetid' => $sectionid,
            'status' => 'READY',
            'timemodified' => $now,
        ]);
        if ($existing) {
            $record->id = (int) $existing->id;
            $record->timecreated = (int) $existing->timecreated;
            $DB->update_record('local_iliasmigration_map', $record);
            return;
        }
        $record->timecreated = $now;
        $DB->insert_record('local_iliasmigration_map', $record);
    }

    /** Find a mapping for the current source instance, with legacy fallback. */
    private function find_mapping(string $sourceref, string $targettype): \stdClass|false {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $this->sourcecourseid,
            'sourceref' => $sourceref,
            'targettype' => $targettype,
        ];
        $mapping = $DB->get_record('local_iliasmigration_map', $conditions);
        if ($mapping) {
            return $mapping;
        }
        $conditions['sourceinstance'] = '';
        return $DB->get_record('local_iliasmigration_map', $conditions);
    }

    /** Return the plugin mapping target type for a visible neutral item. */
    private function mapping_target_type(string $type): ?string {
        return match ($type) {
            'file' => 'file',
            'url' => 'url',
            'html_module' => 'html_module',
            'scorm' => 'scorm',
            'learning_module' => 'book',
            'test' => 'quiz',
            default => null,
        };
    }

    /** Whether a neutral item is displayed as an activity on the course page. */
    private function is_visible_activity_type(string $type): bool {
        return in_array(
            $type,
            ['file', 'url', 'html_module', 'scorm', 'learning_module', 'test'],
            true
        );
    }

    /** Record qbank/unknown items without trying to move them. */
    private function ignore_nonvisible_or_unknown(array $item, int $depth): void {
        $type = (string) ($item['type'] ?? '');
        $ref = (string) ($item['source_id'] ?? '');
        if ($type === 'question_pool') {
            $mapping = $this->find_mapping($ref, 'qbank');
            $this->ignored[] = [
                'source_ref_id' => $ref,
                'type' => $type,
                'reason' => 'Moodle qbank is non-displayable and must remain in section 0.',
                'target_id' => $mapping && !empty($mapping->targetid) ? (int) $mapping->targetid : null,
                'depth' => $depth,
            ];
            return;
        }

        $this->ignored[] = [
            'source_ref_id' => $ref,
            'type' => $type,
            'reason' => 'Item does not participate in the current visible-order policy.',
            'depth' => $depth,
        ];
    }

    /** Stable source instance identity, matching planner/executor policy. */
    private function source_instance(array $document): string {
        $metadata = is_array($document['course']['metadata'] ?? null)
            ? $document['course']['metadata']
            : [];
        $installationurl = trim((string) ($metadata['installation_url'] ?? ''));
        if ($installationurl !== '') {
            return rtrim($installationurl, '/');
        }
        $installationid = trim((string) ($metadata['installation_id'] ?? ''));
        if ($installationid !== '') {
            return 'installation-id:' . $installationid;
        }
        return 'unknown-ilias-instance';
    }

    /** Stable order using the neutral position field. */
    private function source_order(array $items): array {
        $ordered = array_values(array_filter($items, 'is_array'));
        usort($ordered, static function(array $left, array $right): int {
            return (int) ($left['position'] ?? 0) <=> (int) ($right['position'] ?? 0);
        });
        return $ordered;
    }

    /** Read-only snapshot useful for POC validation. */
    private function snapshot_current_course(): array {
        global $DB;

        if ($this->courseid <= 0 || !$DB->record_exists('course', ['id' => $this->courseid])) {
            return [];
        }
        $result = [];
        $sections = $DB->get_records(
            'course_sections',
            ['course' => $this->courseid],
            'section ASC',
            'id,section,name,sequence,component,itemid'
        );
        foreach ($sections as $section) {
            $result[] = [
                'id' => (int) $section->id,
                'section' => (int) $section->section,
                'name' => $section->name,
                'component' => $section->component,
                'itemid' => $section->itemid,
                'sequence' => $section->sequence === '' ? [] : array_map('intval', explode(',', $section->sequence)),
            ];
        }
        return $result;
    }
}

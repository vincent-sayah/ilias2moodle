<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only validator for ILIAS Item Group -> Moodle structural containers.
 *
 * Policy:
 * - root ILIAS Item Group    -> Moodle regular section;
 * - Item Group under a level-one ILIAS folder -> Moodle mod_subsection;
 * - existing migrated activities are reused, never recreated;
 * - ILIAS HideTitle/Behaviour are preserved as metadata only;
 * - this class performs no Moodle writes.
 */
final class phase65_item_group_package_validator {
    /** @var array<string,array> */
    private array $itemindex = [];

    /** @var array<string,string> */
    private array $parentindex = [];

    /** @var array<string,string> */
    private array $memberowners = [];

    private array $document = [];
    private string $sourceinstance = '';
    private string $sourcecourse = '';
    private int $targetcourseid = 0;

    public function __construct(string $migrationjson) {
        $file = realpath($migrationjson);
        if ($file === false || !is_file($file)) {
            throw new \coding_exception(
                'Unable to resolve migration.json for Item Group validation.'
            );
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \coding_exception(
                'Unable to read migration.json for Item Group validation.'
            );
        }

        try {
            $document = json_decode(
                $raw,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \coding_exception(
                'Invalid migration.json for Item Group validation: '
                . $exception->getMessage()
            );
        }

        if (!is_array($document)) {
            throw new \coding_exception(
                'migration.json must contain a JSON object.'
            );
        }

        $this->document = $document;

        $items = $document['course']['items'] ?? [];
        $this->index_items(
            is_array($items) ? $items : [],
            ''
        );
    }

    public function validate(array $plan): array {
        $this->sourceinstance = (string) (
            $plan['source']['instance'] ?? ''
        );

        $this->sourcecourse = (string) (
            $this->document['course']['source_id'] ?? ''
        );

        $this->targetcourseid = (int) (
            $plan['operations'][0]['target_id'] ?? 0
        );

        $discovered = 0;
        $checked = 0;
        $blocked = 0;
        $creates = 0;
        $updates = 0;
        $rootsections = 0;
        $subsections = 0;
        $members = 0;

        foreach ($plan['operations'] as &$operation) {
            if ((string) ($operation['kind'] ?? '') !== 'itgr') {
                continue;
            }

            $discovered++;
            $checked++;

            $sourceref = (string) (
                $operation['source_ref_id'] ?? ''
            );

            $operation['phase'] = '6.5.9';

            $sourceitem = $this->itemindex[$sourceref] ?? null;
            if (!is_array($sourceitem)) {
                $this->block(
                    $operation,
                    'ITEM_GROUP_SOURCE_NOT_FOUND',
                    'Item Group is missing from migration.json.'
                );
                $blocked++;
                continue;
            }

            $metadata = is_array(
                $sourceitem['metadata'] ?? null
            ) ? $sourceitem['metadata'] : [];

            if (empty($metadata['item_group_export_available'])) {
                $this->block(
                    $operation,
                    'ITEM_GROUP_EXPORT_MISSING',
                    'ILIAS ItemGroup export metadata is unavailable.'
                );
                $blocked++;
                continue;
            }

            $schema = trim((string) (
                $metadata['item_group_schema_version'] ?? ''
            ));

            if ($schema === '') {
                $this->block(
                    $operation,
                    'ITEM_GROUP_SCHEMA_MISSING',
                    'ILIAS ItemGroup schema version is missing.'
                );
                $blocked++;
                continue;
            }

            $unresolved = is_array(
                $metadata['item_group_unresolved_obj_ids'] ?? null
            ) ? $metadata['item_group_unresolved_obj_ids'] : [];

            if ($unresolved) {
                $this->block(
                    $operation,
                    'ITEM_GROUP_UNRESOLVED_MEMBERS',
                    'Item Group contains unresolved ILIAS object ids.'
                );
                $blocked++;
                continue;
            }

            $memberobjids = is_array(
                $metadata['item_group_member_obj_ids'] ?? null
            ) ? array_values($metadata['item_group_member_obj_ids']) : [];

            $memberrefs = is_array(
                $metadata['item_group_member_ref_ids'] ?? null
            ) ? array_values($metadata['item_group_member_ref_ids']) : [];

            $declaredcount = (int) (
                $metadata['item_group_member_count'] ?? -1
            );

            if (!$memberrefs
                    || count($memberrefs) !== count($memberobjids)
                    || count($memberrefs) !== $declaredcount
                    || count(array_unique($memberrefs)) !== count($memberrefs)) {
                $this->block(
                    $operation,
                    'ITEM_GROUP_MEMBER_IDENTITY_INVALID',
                    'Item Group member ids/refs/count are inconsistent.'
                );
                $blocked++;
                continue;
            }

            $parent = $this->validate_parent($operation);
            $operation['item_group_parent_validation'] = $parent;

            if (empty($parent['ready'])) {
                $this->block(
                    $operation,
                    'ITEM_GROUP_PARENT_INVALID',
                    (string) (
                        $parent['message']
                        ?? 'Invalid Item Group parent.'
                    )
                );
                $blocked++;
                continue;
            }

            $targetkind = ($parent['kind'] ?? '') === 'course_root'
                ? 'section'
                : 'subsection';

            $mapping = $this->resolve_group_action(
                $sourceref,
                $targetkind,
                $parent
            );

            if (str_starts_with(
                (string) ($mapping['action'] ?? ''),
                'ERROR_'
            )) {
                $this->block(
                    $operation,
                    'ITEM_GROUP_MAPPING_INVALID',
                    (string) ($mapping['action'] ?? '')
                );
                $blocked++;
                continue;
            }

            $membervalidation = $this->validate_members(
                $sourceref,
                $memberrefs
            );

            if (empty($membervalidation['ready'])) {
                $operation['item_group_member_validation'] =
                    $membervalidation;

                $this->block(
                    $operation,
                    'ITEM_GROUP_MEMBER_MAPPING_INVALID',
                    'At least one Item Group member has no valid existing Moodle mapping.'
                );
                $blocked++;
                continue;
            }

            $operation['action'] = $mapping['action'];
            $operation['target_id'] = $mapping['target_id'];
            $operation['target_structure'] = $targetkind;
            $operation['item_group_member_validation'] =
                $membervalidation;

            $validation = [
                'status' => 'READY',
                'code' => 'ITEM_GROUP_READY',
                'schema_version' => $schema,
                'hide_title' => (int) (
                    $metadata['item_group_hide_title'] ?? 0
                ),
                'behaviour' => (int) (
                    $metadata['item_group_behaviour'] ?? 0
                ),
                'target_structure' => $targetkind,
                'member_count' => count($memberrefs),
                'member_ref_ids' => $memberrefs,
                'member_obj_ids' => $memberobjids,
                'member_cmids' => array_values(array_map(
                    static fn(array $member): int =>
                        (int) $member['target_id'],
                    $membervalidation['members']
                )),
                'collapse_policy' =>
                    'PRESERVE_SOURCE_METADATA_NO_EXACT_MOODLE_MAPPING',
            ];

            if ($targetkind === 'section') {
                $validation['desired_section_position'] =
                    $this->root_structural_position($sourceref);
                $rootsections++;
            } else {
                $validation['parent_section_id'] = (int) (
                    $parent['targetid'] ?? 0
                );
                $validation['current_parent_section_number'] = (int) (
                    $parent['current_section_number'] ?? 0
                );
                $validation['desired_parent_section_number'] = (int) (
                    $parent['desired_section_number'] ?? 0
                );
                $validation['source_position'] = (int) (
                    $sourceitem['position'] ?? 0
                );
                $subsections++;
            }

            $operation['item_group_validation'] = $validation;

            $members += count($memberrefs);

            if ($mapping['action'] === 'CREATE') {
                $creates++;
            } else if ($mapping['action'] === 'UPDATE') {
                $updates++;
            }
        }
        unset($operation);

        if ($checked === 0) {
            $plan['warnings'][] = [
                'code' => 'NO_ITEM_GROUP_FOUND',
                'message' =>
                    'No ILIAS Item Group was found in this package.',
            ];
        }

        if ($checked > 0) {
            $plan['warnings'][] = [
                'code' => 'ITEM_GROUP_BEHAVIOUR_METADATA_ONLY',
                'message' =>
                    'ILIAS HideTitle/Behaviour are preserved in the migration plan, '
                    . 'but Phase 6.5.9 does not attempt an exact Moodle collapse/expand equivalent.',
            ];
        }

        $rootstructure = $this->build_root_structure_plan();

        $rootstructureready = !array_filter(
            $rootstructure,
            static fn(array $entry): bool =>
                in_array(
                    (string) ($entry['action'] ?? ''),
                    [
                        'MISSING_MAPPING',
                        'INVALID_MAPPING',
                        'MISSING_MAPPING_FINAL_ORDER',
                        'FINAL_ORDER_RELATIVE_MISMATCH',
                    ],
                    true
                )
        );

        $ready = $checked > 0
            && $blocked === 0
            && $this->targetcourseid > 0
            && $rootstructureready;

        $plan['phase65_item_group_package'] = [
            'discovered_item_groups' => $discovered,
            'checked_item_groups' => $checked,
            'blocked_item_groups' => $blocked,
            'item_group_create_count' => $creates,
            'item_group_update_count' => $updates,
            'root_section_count' => $rootsections,
            'subsection_count' => $subsections,
            'member_count' => $members,
            'root_structure_ready' => $rootstructureready,
            'root_order_reconciled' =>
                $this->has_reconciled_root_order(),
            'root_structure_plan' => $rootstructure,
            'structure_policy' =>
                'ROOT_ITGR_TO_SECTION_PARENTED_ITGR_TO_SUBSECTION',
            'member_policy' =>
                'MOVE_EXISTING_MAPPED_ACTIVITIES_ONLY',
            'collapse_policy' =>
                'PRESERVE_SOURCE_METADATA_NO_EXACT_MOODLE_MAPPING',
            'ready' => $ready,

            'apply_implemented' => true,
            'apply_ready' => $ready,
        ];

        return $plan;
    }

    /**
     * Index all neutral items and their structural parent refs.
     */
    private function index_items(
        array $items,
        string $parentref
    ): void {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $ref = trim((string) (
                $item['source_id'] ?? ''
            ));

            if ($ref !== '') {
                $this->itemindex[$ref] = $item;
                $this->parentindex[$ref] = $parentref;
            }

            $children = is_array($item['items'] ?? null)
                ? $item['items']
                : [];

            $this->index_items(
                $children,
                $ref !== '' ? $ref : $parentref
            );
        }
    }

    /**
     * Root Item Group -> course root.
     * Parented Item Group POC -> an existing first-level Moodle section.
     */
    private function validate_parent(array $operation): array {
        global $DB;

        $parentref = trim((string) (
            $operation['parent_source_ref_id'] ?? ''
        ));

        if ($parentref === '') {
            return [
                'ready' => true,
                'kind' => 'course_root',
                'parent_ref' => '',
                'section_number' => 0,
            ];
        }

        $mapping = $this->find_mapping(
            $parentref,
            'section'
        );

        if (!$mapping) {
            return [
                'ready' => false,
                'kind' => 'unsupported_parent',
                'parent_ref' => $parentref,
                'message' =>
                    "No first-level Moodle section mapping exists for Item Group parent {$parentref}.",
            ];
        }

        $section = $DB->get_record(
            'course_sections',
            [
                'id' => (int) $mapping->targetid,
                'course' => $this->targetcourseid,
            ],
            'id,course,section,name,component,itemid'
        );

        if (!$section || !empty($section->component)) {
            return [
                'ready' => false,
                'kind' => 'invalid_section',
                'parent_ref' => $parentref,
                'message' =>
                    "Mapped Item Group parent {$parentref} is not a regular Moodle section.",
            ];
        }

        return [
            'ready' => true,
            'kind' => 'section',
            'parent_ref' => $parentref,
            'targettype' => 'section',
            'targetid' => (int) $section->id,
            'current_section_number' => (int) $section->section,
            'desired_section_number' =>
                $this->root_structural_position($parentref),
            'title' => (string) $section->name,
        ];
    }

    /**
     * Resolve CREATE/UPDATE for the Moodle structural target.
     */
    private function resolve_group_action(
        string $sourceref,
        string $targetkind,
        array $parent
    ): array {
        global $DB;

        $mapping = $this->find_mapping(
            $sourceref,
            $targetkind
        );

        if (!$mapping) {
            return [
                'action' => 'CREATE',
                'target_id' => null,
            ];
        }

        $targetid = (int) ($mapping->targetid ?? 0);
        if ($targetid <= 0) {
            return [
                'action' => 'ERROR_STALE_MAPPING',
                'target_id' => null,
            ];
        }

        if ($targetkind === 'section') {
            $section = $DB->get_record(
                'course_sections',
                [
                    'id' => $targetid,
                    'course' => $this->targetcourseid,
                ],
                'id,course,section,component'
            );

            if (!$section || !empty($section->component)) {
                return [
                    'action' => 'ERROR_MAPPING_TYPE',
                    'target_id' => $targetid,
                ];
            }

            return [
                'action' => 'UPDATE',
                'target_id' => $targetid,
            ];
        }

        $cm = $DB->get_record_sql(
            'SELECT cm.id, cm.course, cm.instance, cm.section,
                    m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = ?',
            [$targetid]
        );

        if (!$cm
                || (int) $cm->course !== $this->targetcourseid
                || (string) $cm->modulename !== 'subsection') {
            return [
                'action' => 'ERROR_MAPPING_TYPE',
                'target_id' => $targetid,
            ];
        }

        if (!empty($parent['targetid'])
                && (int) $cm->section !== (int) $parent['targetid']) {
            return [
                'action' => 'ERROR_WRONG_PARENT',
                'target_id' => $targetid,
            ];
        }

        $delegated = $DB->get_record(
            'course_sections',
            [
                'course' => $this->targetcourseid,
                'component' => 'mod_subsection',
                'itemid' => (int) $cm->instance,
            ],
            'id,section'
        );

        if (!$delegated) {
            return [
                'action' => 'ERROR_DELEGATED_SECTION_MISSING',
                'target_id' => $targetid,
            ];
        }

        return [
            'action' => 'UPDATE',
            'target_id' => $targetid,
            'delegated_section_id' => (int) $delegated->id,
            'delegated_section_number' => (int) $delegated->section,
        ];
    }

    /**
     * Validate that every group member already exists as a Moodle activity.
     */
    private function validate_members(
        string $groupref,
        array $memberrefs
    ): array {
        global $DB;

        $result = [];
        $errors = [];

        foreach ($memberrefs as $memberref) {
            $memberref = trim((string) $memberref);

            if ($memberref === '') {
                $errors[] = [
                    'source_ref_id' => '',
                    'code' => 'EMPTY_MEMBER_REF',
                ];
                continue;
            }

            if (isset($this->memberowners[$memberref])
                    && $this->memberowners[$memberref] !== $groupref) {
                $errors[] = [
                    'source_ref_id' => $memberref,
                    'code' => 'MEMBER_IN_MULTIPLE_ITEM_GROUPS',
                    'other_group' =>
                        $this->memberowners[$memberref],
                ];
                continue;
            }

            $this->memberowners[$memberref] = $groupref;

            $sourceitem = $this->itemindex[$memberref] ?? null;
            if (!is_array($sourceitem)) {
                $errors[] = [
                    'source_ref_id' => $memberref,
                    'code' => 'MEMBER_SOURCE_NOT_FOUND',
                ];
                continue;
            }

            $kind = (string) (
                $sourceitem['type'] ?? ''
            );

            $targettype =
                $this->mapping_target_type($kind);

            $expectedmodule =
                $this->expected_module($kind);

            if ($targettype === null
                    || $expectedmodule === null) {
                $errors[] = [
                    'source_ref_id' => $memberref,
                    'source_type' => $kind,
                    'code' =>
                        'MEMBER_TYPE_NOT_SINGLE_ACTIVITY',
                ];
                continue;
            }

            $mapping = $this->find_mapping(
                $memberref,
                $targettype
            );

            if (!$mapping || empty($mapping->targetid)) {
                $errors[] = [
                    'source_ref_id' => $memberref,
                    'source_type' => $kind,
                    'target_type' => $targettype,
                    'code' => 'MEMBER_MAPPING_MISSING',
                ];
                continue;
            }

            $cmid = (int) $mapping->targetid;

            $cm = $DB->get_record_sql(
                'SELECT cm.id, cm.course, cm.instance, cm.section,
                        m.name AS modulename
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module
                  WHERE cm.id = ?',
                [$cmid]
            );

            if (!$cm) {
                $errors[] = [
                    'source_ref_id' => $memberref,
                    'target_id' => $cmid,
                    'code' => 'MEMBER_MAPPING_STALE',
                ];
                continue;
            }

            if ((int) $cm->course !== $this->targetcourseid
                    || (string) $cm->modulename !== $expectedmodule) {
                $errors[] = [
                    'source_ref_id' => $memberref,
                    'target_id' => $cmid,
                    'expected_module' => $expectedmodule,
                    'actual_module' =>
                        (string) $cm->modulename,
                    'code' => 'MEMBER_MAPPING_TYPE_INVALID',
                ];
                continue;
            }

            $section = $DB->get_record(
                'course_sections',
                [
                    'id' => (int) $cm->section,
                    'course' => $this->targetcourseid,
                ],
                'id,section,name,component,itemid',
                MUST_EXIST
            );

            $result[] = [
                'source_ref_id' => $memberref,
                'source_obj_id' => (string) (
                    $sourceitem['metadata']['obj_id']
                    ?? ''
                ),
                'source_type' => $kind,
                'title' => (string) (
                    $sourceitem['title'] ?? ''
                ),
                'target_type' => $targettype,
                'target_id' => $cmid,
                'moodle_module' =>
                    (string) $cm->modulename,
                'instance_id' => (int) $cm->instance,
                'current_section_id' =>
                    (int) $section->id,
                'current_section_number' =>
                    (int) $section->section,
            ];
        }

        return [
            'ready' => !$errors
                && count($result) === count($memberrefs),
            'member_count' => count($memberrefs),
            'members' => $result,
            'errors' => $errors,
        ];
    }

    private function mapping_target_type(
        string $kind
    ): ?string {
        return match ($kind) {
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
            default => null,
        };
    }

    private function expected_module(
        string $kind
    ): ?string {
        return match ($kind) {
            'file', 'html_module' => 'resource',
            'url' => 'url',
            'scorm' => 'scorm',
            'learning_module' => 'book',
            'test' => 'quiz',
            'content_page' => 'page',
            'wiki' => 'wiki',
            'blog', 'mediacast', 'media_pool' => 'data',
            'forum' => 'forum',
            'glossary' => 'glossary',
            default => null,
        };
    }

    /**
     * Build the complete desired root section layout.
     *
     * Existing ILIAS folders reuse their mapped Moodle section.
     * Root Item Groups create/reuse regular Moodle sections.
     * Stable Moodle section ids are kept separate from mutable section numbers.
     */
    private function build_root_structure_plan(): array {
        global $DB;

        $items = is_array(
            $this->document['course']['items'] ?? null
        ) ? $this->document['course']['items'] : [];

        usort(
            $items,
            static fn(array $left, array $right): int =>
                (int) ($left['position'] ?? 0)
                <=>
                (int) ($right['position'] ?? 0)
        );

        $result = [];
        $desiredposition = 0;
        $lastcurrentposition = 0;

        /*
         * Before global order reconciliation, root structural objects are
         * positioned contiguously from 1.
         *
         * Once at least one valid synthetic_section mapping exists, the
         * global reconciler owns absolute Moodle section numbers. Phase
         * 6.5.9 must then preserve those numbers and validate only the
         * relative source structural order.
         */
        $finalorder =
            $this->has_reconciled_root_order();

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = (string) (
                $item['type'] ?? ''
            );

            if (!in_array(
                $type,
                ['folder', 'itgr'],
                true
            )) {
                continue;
            }

            $desiredposition++;

            $ref = (string) (
                $item['source_id'] ?? ''
            );

            $mapping = $this->find_mapping(
                $ref,
                'section'
            );

            $targetid = null;
            $currentposition = null;

            $effectiveposition =
                $desiredposition;

            $positionpolicy =
                $finalorder
                    ? 'PRESERVE_RECONCILED_ORDER'
                    : 'STRUCTURAL_POSITION';

            $action = 'CREATE';

            if ($mapping && !empty($mapping->targetid)) {
                $targetid =
                    (int) $mapping->targetid;

                $section = $DB->get_record(
                    'course_sections',
                    [
                        'id' => $targetid,
                        'course' =>
                            $this->targetcourseid,
                    ],
                    'id,section,name,component,itemid'
                );

                if (!$section
                        || !empty($section->component)) {
                    $action =
                        'INVALID_MAPPING';
                } else {
                    $currentposition =
                        (int) $section->section;

                    if ($finalorder) {
                        /*
                         * Synthetic sections may legitimately sit between
                         * source structural sections. Absolute positions
                         * therefore belong to the final order reconciler.
                         */
                        $effectiveposition =
                            $currentposition;

                        if ($currentposition
                                <= $lastcurrentposition) {
                            $action =
                                'FINAL_ORDER_RELATIVE_MISMATCH';
                        } else {
                            $action = 'REUSE';
                        }

                        $lastcurrentposition =
                            max(
                                $lastcurrentposition,
                                $currentposition
                            );
                    } else {
                        $action = (
                            $currentposition
                                === $desiredposition
                        )
                            ? 'REUSE'
                            : 'REPOSITION';
                    }
                }
            } else if ($type === 'folder') {
                $action =
                    'MISSING_MAPPING';
            } else if ($finalorder) {
                /*
                 * Once global ordering has been reconciled, a missing root
                 * Item Group mapping is unsafe to create because inserting
                 * a new regular section would invalidate final ordering.
                 */
                $action =
                    'MISSING_MAPPING_FINAL_ORDER';
            }

            $result[] = [
                'source_ref_id' => $ref,

                'source_obj_id' => (string) (
                    $item['metadata']['obj_id'] ?? ''
                ),

                'source_type' => $type,

                'title' => (string) (
                    $item['title'] ?? ''
                ),

                'source_position' => (int) (
                    $item['position'] ?? 0
                ),

                'source_structural_position' =>
                    $desiredposition,

                'target_type' => 'section',

                'target_id' =>
                    $targetid,

                'current_section_number' =>
                    $currentposition,

                'desired_section_number' =>
                    $effectiveposition,

                'position_policy' =>
                    $positionpolicy,

                'action' => $action,
            ];
        }

        return $result;
    }

    /**
     * Desired regular-section position among root structural objects.
     */
    private function root_structural_position(
        string $sourceref
    ): int {
        global $DB;

        /*
         * After the final order reconciler has created synthetic sections,
         * absolute section numbers no longer match the compact folder/itgr
         * ordinal. Use the stable mapping and current Moodle section number.
         */
        if ($this->has_reconciled_root_order()) {
            $mapping = $this->find_mapping(
                $sourceref,
                'section'
            );

            if (!$mapping
                    || empty($mapping->targetid)
                    || $this->targetcourseid <= 0) {
                return 0;
            }

            $section = $DB->get_record(
                'course_sections',
                [
                    'id' =>
                        (int) $mapping->targetid,
                    'course' =>
                        $this->targetcourseid,
                ],
                'id,section,component,itemid',
                IGNORE_MISSING
            );

            if (!$section
                    || !empty($section->component)) {
                return 0;
            }

            return (int) $section->section;
        }

        $items = is_array(
            $this->document['course']['items'] ?? null
        ) ? $this->document['course']['items'] : [];

        usort(
            $items,
            static fn(array $left, array $right): int =>
                (int) ($left['position'] ?? 0)
                <=>
                (int) ($right['position'] ?? 0)
        );

        $position = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = (string) (
                $item['type'] ?? ''
            );

            if (in_array(
                $type,
                ['folder', 'itgr'],
                true
            )) {
                $position++;
            }

            if ((string) (
                    $item['source_id'] ?? ''
                ) === $sourceref) {
                return $position;
            }
        }

        return 0;
    }

    /**
     * Whether global root-order reconciliation has already materialised
     * at least one valid synthetic regular Moodle section.
     */
    private function has_reconciled_root_order(): bool {
        global $DB;

        if ($this->targetcourseid <= 0) {
            return false;
        }

        $instances = [
            $this->sourceinstance,
        ];

        if ($this->sourceinstance !== '') {
            $instances[] = '';
        }

        foreach (
            array_unique($instances)
            as $instance
        ) {
            $mappings = $DB->get_records(
                'local_iliasmigration_map',
                [
                    'sourcelms' => 'ILIAS',
                    'sourceinstance' =>
                        $instance,
                    'sourcecourse' =>
                        $this->sourcecourse,
                    'targettype' =>
                        'synthetic_section',
                ],
                'id ASC',
                'id,targetid'
            );

            foreach ($mappings as $mapping) {
                if (empty($mapping->targetid)) {
                    continue;
                }

                $section = $DB->get_record(
                    'course_sections',
                    [
                        'id' =>
                            (int) $mapping->targetid,
                        'course' =>
                            $this->targetcourseid,
                    ],
                    'id,component,itemid',
                    IGNORE_MISSING
                );

                if ($section
                        && empty($section->component)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Current source-instance mapping with legacy fallback.
     */
    private function find_mapping(
        string $sourceref,
        string $targettype
    ): \stdClass|false {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $this->sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => $targettype,
        ];

        $mapping = $DB->get_record(
            'local_iliasmigration_map',
            $conditions
        );

        if ($mapping) {
            return $mapping;
        }

        if ($this->sourceinstance === '') {
            return false;
        }

        $conditions['sourceinstance'] = '';

        return $DB->get_record(
            'local_iliasmigration_map',
            $conditions
        );
    }

    private function block(
        array &$operation,
        string $code,
        string $message
    ): void {
        $operation['action'] = 'BLOCKED';
        $operation['target_id'] = null;
        $operation['reason'] = $code;
        $operation['item_group_validation'] = [
            'status' => 'BLOCKED',
            'code' => $code,
            'message' => $message,
        ];
    }
}

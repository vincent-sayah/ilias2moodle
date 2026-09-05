<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds a read-only Moodle import plan from migration.json.
 */
final class plan_builder {
    /** @var int Moodle target course category id. */
    private int $categoryid;

    /** @var int Requested migration phase. */
    private int $phase;

    /** @var string Stable identity of the ILIAS source instance. */
    private string $sourceinstance = '';

    /**
     * @param int $categoryid Moodle target course category id.
     * @param int $phase Requested migration phase.
     */
    public function __construct(int $categoryid, int $phase = 2) {
        $this->categoryid = $categoryid;
        $this->phase = $phase;
    }

    /**
     * Build a dry-run plan without creating Moodle content.
     *
     * @param array $document Validated migration document.
     * @return array Import plan.
     */
    public function build(array $document): array {
        global $CFG, $DB;

        if (!in_array($this->phase, [2, 3], true)) {
            throw new \coding_exception('Only migration phases 2 and 3 are supported by this plugin version.');
        }

        $category = $DB->get_record(
            'course_categories',
            ['id' => $this->categoryid],
            'id,name,parent,visible',
            MUST_EXIST
        );

        $subsection = $DB->get_record('modules', ['name' => 'subsection'], 'id,name,visible');
        $subsectionavailable = $subsection && (int) $subsection->visible === 1;

        $this->sourceinstance = $this->source_instance($document);

        $course = $document['course'];
        $sourcecourseid = (string) $course['source_id'];
        $shortname = 'ILIAS-' . $sourcecourseid;
        $courseaction = $this->resolve_action(
            $sourcecourseid,
            $sourcecourseid,
            'course',
            'course'
        );

        if ($courseaction['action'] === 'CREATE'
                && $DB->record_exists('course', ['shortname' => $shortname])) {
            $courseaction = [
                'action' => 'CONFLICT',
                'reason' => 'A Moodle course already uses shortname ' . $shortname,
                'target_id' => null,
                'legacy_mapping' => false,
            ];
        }

        $courseoperation = [
            'kind' => 'course',
            'action' => $courseaction['action'],
            'source_ref_id' => $sourcecourseid,
            'source_obj_id' => (string) ($course['metadata']['obj_id'] ?? ''),
            'target_id' => $courseaction['target_id'],
            'fullname' => (string) $course['title'],
            'shortname' => $shortname,
            'category_id' => (int) $category->id,
        ];
        if (!empty($courseaction['legacy_mapping'])) {
            $courseoperation['legacy_sourceinstance_mapping'] = true;
        }

        $operations = [$courseoperation];
        $warnings = [];
        foreach ($course['items'] as $item) {
            if (is_array($item)) {
                $this->append_item_plan(
                    $item,
                    $sourcecourseid,
                    1,
                    null,
                    $subsectionavailable,
                    $operations,
                    $warnings
                );
            }
        }

        if (!$subsectionavailable) {
            $warnings[] = [
                'code' => 'SUBSECTION_DISABLED',
                'message' => 'Moodle subsection activity is missing or disabled.',
            ];
        }

        if ($this->phase >= 3) {
            $this->append_root_order_warning($course['items'], $warnings);
        }

        $source = $document['source'];
        $source['instance'] = $this->sourceinstance;

        return [
            'mode' => 'dry-run',
            'phase' => $this->phase,
            'writes_performed' => false,
            'moodle' => [
                'release' => (string) $CFG->release,
                'version' => (string) $CFG->version,
                'category' => [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                    'visible' => (int) $category->visible,
                ],
                'subsection_available' => $subsectionavailable,
            ],
            'source' => $source,
            'course' => [
                'source_id' => $sourcecourseid,
                'title' => (string) $course['title'],
                'shortname' => $shortname,
            ],
            'operations' => $operations,
            'warnings' => $warnings,
        ];
    }

    /**
     * Add one ILIAS item and its children to the dry-run plan.
     *
     * @param array $item Migration item.
     * @param string $sourcecourseid ILIAS course ref_id.
     * @param int $depth Folder depth, starting at 1.
     * @param string|null $parentsourceref Parent ILIAS ref_id.
     * @param bool $subsectionavailable Whether mod_subsection is enabled.
     * @param array $operations Collected operations.
     * @param array $warnings Collected warnings.
     */
    private function append_item_plan(
        array $item,
        string $sourcecourseid,
        int $depth,
        ?string $parentsourceref,
        bool $subsectionavailable,
        array &$operations,
        array &$warnings
    ): void {
        $sourceid = (string) ($item['source_id'] ?? '');
        $type = (string) ($item['type'] ?? 'unknown');
        $title = (string) ($item['title'] ?? '');
        $description = (string) ($item['description'] ?? '');
        $position = (int) ($item['position'] ?? 0);
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $objid = (string) ($metadata['obj_id'] ?? '');

        if ($type === 'folder' && $depth === 1) {
            $mapping = $this->resolve_action($sourcecourseid, $sourceid, 'section', 'course_sections');
            $operation = [
                'kind' => 'section',
                'action' => $mapping['action'],
                'source_ref_id' => $sourceid,
                'source_obj_id' => $objid,
                'parent_source_ref_id' => $parentsourceref,
                'target_id' => $mapping['target_id'],
                'title' => $title,
                'position' => $position,
            ];
            if (!empty($mapping['legacy_mapping'])) {
                $operation['legacy_sourceinstance_mapping'] = true;
            }
            $operations[] = $operation;
        } else if ($type === 'folder' && $depth === 2) {
            $mapping = $this->resolve_action(
                $sourcecourseid,
                $sourceid,
                'subsection',
                'course_modules'
            );
            $operation = [
                'kind' => 'subsection',
                'action' => $subsectionavailable ? $mapping['action'] : 'BLOCKED',
                'source_ref_id' => $sourceid,
                'source_obj_id' => $objid,
                'parent_source_ref_id' => $parentsourceref,
                'target_id' => $mapping['target_id'],
                'title' => $title,
                'position' => $position,
            ];
            if (!empty($mapping['legacy_mapping'])) {
                $operation['legacy_sourceinstance_mapping'] = true;
            }
            $operations[] = $operation;
        } else if ($type === 'folder') {
            $operations[] = [
                'kind' => 'folder',
                'action' => 'FLATTEN_REQUIRED',
                'source_ref_id' => $sourceid,
                'source_obj_id' => $objid,
                'parent_source_ref_id' => $parentsourceref,
                'target_id' => null,
                'title' => $title,
                'position' => $position,
                'depth' => $depth,
            ];
            $warnings[] = [
                'code' => 'FOLDER_DEPTH_GT_2',
                'source_ref_id' => $sourceid,
                'message' => 'Moodle subsections cannot be nested recursively; flattening is required.',
            ];
        } else {
            $itemphase = $this->phase_for_type($type);
            $mapping = null;
            $action = 'DEFER';
            $targetid = null;

            if ($this->phase >= 3 && in_array($type, ['file', 'url', 'html_module'], true)) {
                $mapping = $this->resolve_action(
                    $sourcecourseid,
                    $sourceid,
                    $type,
                    'course_modules'
                );
                $action = $mapping['action'];
                $targetid = $mapping['target_id'];
            }

            $operation = [
                'kind' => $type,
                'action' => $action,
                'phase' => $itemphase,
                'source_ref_id' => $sourceid,
                'source_obj_id' => $objid,
                'parent_source_ref_id' => $parentsourceref,
                'target_id' => $targetid,
                'title' => $title,
                'description' => $description,
                'position' => $position,
            ];

            if ($type === 'url') {
                $operation['moodle_module'] = 'url';
                $operation['source_url'] = (string) ($metadata['url'] ?? '');
            } else if ($type === 'file') {
                $operation['moodle_module'] = 'resource';
                $operation['migration_path'] = (string) ($metadata['migration_path'] ?? '');
                $operation['mime_type'] = (string) ($metadata['mime_type'] ?? '');
                $operation['size'] = (int) ($metadata['size'] ?? 0);
            } else if ($type === 'html_module') {
                $operation['moodle_module'] = 'resource';
                $operation['migration_content_dir'] = (string) ($metadata['migration_content_dir'] ?? '');
                $operation['migration_start_file'] = (string) ($metadata['migration_start_file'] ?? '');
            }

            if ($mapping && !empty($mapping['legacy_mapping'])) {
                $operation['legacy_sourceinstance_mapping'] = true;
            }

            $operations[] = $operation;
        }

        foreach (($item['items'] ?? []) as $child) {
            if (is_array($child)) {
                $childdepth = $type === 'folder' ? $depth + 1 : $depth;
                $this->append_item_plan(
                    $child,
                    $sourcecourseid,
                    $childdepth,
                    $type === 'folder' ? $sourceid : $parentsourceref,
                    $subsectionavailable,
                    $operations,
                    $warnings
                );
            }
        }
    }

    /**
     * Resolve CREATE/UPDATE state from the persistent mapping table.
     *
     * Existing Phase 2 mappings created before sourceinstance existed are accepted
     * as legacy mappings. They can be upgraded to the current source instance on a
     * later write operation.
     *
     * @param string $sourcecourseid ILIAS course ref_id.
     * @param string $sourceref ILIAS object ref_id.
     * @param string $targettype Mapping target type.
     * @param string $targettable Moodle table containing the mapped target.
     * @return array Action descriptor.
     */
    private function resolve_action(
        string $sourcecourseid,
        string $sourceref,
        string $targettype,
        string $targettable
    ): array {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $sourcecourseid,
            'sourceref' => $sourceref,
            'targettype' => $targettype,
        ];
        $mapping = $DB->get_record('local_iliasmigration_map', $conditions);
        $legacy = false;

        if (!$mapping && $this->sourceinstance !== '') {
            $legacyconditions = $conditions;
            $legacyconditions['sourceinstance'] = '';
            $mapping = $DB->get_record('local_iliasmigration_map', $legacyconditions);
            $legacy = (bool) $mapping;
        }

        if (!$mapping) {
            return [
                'action' => 'CREATE',
                'target_id' => null,
                'legacy_mapping' => false,
            ];
        }

        if (!empty($mapping->targetid) && $DB->record_exists($targettable, ['id' => $mapping->targetid])) {
            return [
                'action' => 'UPDATE',
                'target_id' => (int) $mapping->targetid,
                'legacy_mapping' => $legacy,
            ];
        }

        return [
            'action' => 'ERROR_STALE_MAPPING',
            'target_id' => $mapping->targetid ?: null,
            'legacy_mapping' => $legacy,
        ];
    }

    /**
     * Return a stable source-instance identity from ILIAS manifest metadata.
     *
     * @param array $document Migration document.
     * @return string Source instance identity.
     */
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

    /**
     * Warn when the ILIAS root order cannot be represented exactly with the
     * current mapping of first-level folders to Moodle sections.
     *
     * Moodle activities in section 0 are displayed before regular sections.
     * Therefore a root-level resource positioned after an ILIAS folder cannot
     * remain visually after that folder without introducing a synthetic section.
     *
     * @param array $items Root ILIAS course items.
     * @param array $warnings Collected warnings.
     */
    private function append_root_order_warning(array $items, array &$warnings): void {
        $seenfolder = false;
        $affected = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? 'unknown');
            if ($type === 'folder') {
                $seenfolder = true;
                continue;
            }
            if ($seenfolder && in_array($type, ['file', 'url', 'html_module'], true)) {
                $affected[] = (string) ($item['source_id'] ?? '');
            }
        }

        if ($affected) {
            $warnings[] = [
                'code' => 'ROOT_RESOURCE_ORDER_APPROXIMATION',
                'source_ref_ids' => $affected,
                'message' => 'Root-level Moodle resources live in section 0 and therefore display before regular sections. '
                    . 'Exact interleaving with ILIAS first-level folders requires a later ordering policy.',
            ];
        }
    }

    /**
     * Return the project phase responsible for a deferred resource type.
     *
     * @param string $type Neutral migration item type.
     * @return int Project phase number.
     */
    private function phase_for_type(string $type): int {
        return match ($type) {
            'file', 'url', 'html_module' => 3,
            'scorm' => 4,
            'learning_module' => 5,
            'test', 'question_pool' => 6,
            default => 3,
        };
    }
}

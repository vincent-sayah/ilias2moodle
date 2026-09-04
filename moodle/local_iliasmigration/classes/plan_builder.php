<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds a read-only Moodle import plan from migration.json.
 */
final class plan_builder {
    /** @var int Moodle target course category id. */
    private int $categoryid;

    /**
     * @param int $categoryid Moodle target course category id.
     */
    public function __construct(int $categoryid) {
        $this->categoryid = $categoryid;
    }

    /**
     * Build a dry-run plan without creating Moodle content.
     *
     * @param array $document Validated migration document.
     * @return array Import plan.
     */
    public function build(array $document): array {
        global $CFG, $DB;

        $category = $DB->get_record(
            'course_categories',
            ['id' => $this->categoryid],
            'id,name,parent,visible',
            MUST_EXIST
        );

        $subsection = $DB->get_record('modules', ['name' => 'subsection'], 'id,name,visible');
        $subsectionavailable = $subsection && (int) $subsection->visible === 1;

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
            ];
        }

        $operations = [[
            'kind' => 'course',
            'action' => $courseaction['action'],
            'source_ref_id' => $sourcecourseid,
            'source_obj_id' => (string) ($course['metadata']['obj_id'] ?? ''),
            'target_id' => $courseaction['target_id'],
            'fullname' => (string) $course['title'],
            'shortname' => $shortname,
            'category_id' => (int) $category->id,
        ]];

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

        return [
            'mode' => 'dry-run',
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
            'source' => $document['source'],
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
        $position = (int) ($item['position'] ?? 0);
        $objid = (string) ($item['metadata']['obj_id'] ?? '');

        if ($type === 'folder' && $depth === 1) {
            $mapping = $this->resolve_action($sourcecourseid, $sourceid, 'section', 'course_sections');
            $operations[] = [
                'kind' => 'section',
                'action' => $mapping['action'],
                'source_ref_id' => $sourceid,
                'source_obj_id' => $objid,
                'parent_source_ref_id' => $parentsourceref,
                'target_id' => $mapping['target_id'],
                'title' => $title,
                'position' => $position,
            ];
        } else if ($type === 'folder' && $depth === 2) {
            $mapping = $this->resolve_action(
                $sourcecourseid,
                $sourceid,
                'subsection',
                'course_modules'
            );
            $operations[] = [
                'kind' => 'subsection',
                'action' => $subsectionavailable ? $mapping['action'] : 'BLOCKED',
                'source_ref_id' => $sourceid,
                'source_obj_id' => $objid,
                'parent_source_ref_id' => $parentsourceref,
                'target_id' => $mapping['target_id'],
                'title' => $title,
                'position' => $position,
            ];
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
            $operations[] = [
                'kind' => $type,
                'action' => 'DEFER',
                'phase' => $this->phase_for_type($type),
                'source_ref_id' => $sourceid,
                'source_obj_id' => $objid,
                'parent_source_ref_id' => $parentsourceref,
                'target_id' => null,
                'title' => $title,
                'position' => $position,
            ];
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

        $mapping = $DB->get_record('local_iliasmigration_map', [
            'sourcelms' => 'ILIAS',
            'sourcecourse' => $sourcecourseid,
            'sourceref' => $sourceref,
            'targettype' => $targettype,
        ]);

        if (!$mapping) {
            return ['action' => 'CREATE', 'target_id' => null];
        }

        if (!empty($mapping->targetid) && $DB->record_exists($targettable, ['id' => $mapping->targetid])) {
            return ['action' => 'UPDATE', 'target_id' => (int) $mapping->targetid];
        }

        return ['action' => 'ERROR_STALE_MAPPING', 'target_id' => $mapping->targetid ?: null];
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

<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the Phase 6.5 Content Page -> Moodle Page dry-run plan.
 *
 * This planner is read-only and extends the Phase 6 plan so every previously
 * validated migration object must already resolve to a stable Moodle target.
 */
final class phase65_plan_builder {
    /** @var int Moodle target category id. */
    private int $categoryid;

    /**
     * @param int $categoryid Moodle target category id.
     */
    public function __construct(int $categoryid) {
        $this->categoryid = $categoryid;
    }

    /**
     * Build a read-only Phase 6.5 Content Page plan.
     *
     * @param array $document Validated migration document.
     * @return array Import plan.
     */
    public function build(array $document): array {
        global $DB;

        $plan = (new phase6_plan_builder($this->categoryid))->build($document);
        $plan['phase'] = '6.5';

        $pagemodule = $DB->get_record('modules', ['name' => 'page'], 'id,name,visible');
        $pageavailable = $pagemodule && (int) $pagemodule->visible === 1;
        $plan['moodle']['page_available'] = $pageavailable;

        $sourcecourseid = (string) ($document['course']['source_id'] ?? '');
        $sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $targetcourseid = (int) ($plan['operations'][0]['target_id'] ?? 0);

        $metadataindex = [];
        $this->index_items($document['course']['items'] ?? [], $metadataindex);

        $pending = [];
        foreach ($plan['operations'] as &$operation) {
            $kind = (string) ($operation['kind'] ?? '');
            $action = (string) ($operation['action'] ?? '');
            $sourceref = (string) ($operation['source_ref_id'] ?? '');

            if ($kind === 'content_page') {
                $metadata = $metadataindex[$sourceref] ?? [];
                $mapping = $this->resolve_page_action(
                    $sourceinstance,
                    $sourcecourseid,
                    $sourceref,
                    $targetcourseid
                );

                $operation['phase'] = '6.5';
                $operation['action'] = $pageavailable ? $mapping['action'] : 'BLOCKED';
                $operation['target_id'] = $mapping['target_id'];
                $operation['moodle_module'] = 'page';
                $operation['migration_structure_path'] = (string) (
                    $metadata['migration_structure_path'] ?? ''
                );
                $operation['content_page_schema_version'] = (string) (
                    $metadata['content_page_schema_version'] ?? ''
                );
                $operation['content_page_media_count'] = (int) (
                    $metadata['content_page_media_count'] ?? 0
                );
                $operation['content_page_file_count'] = (int) (
                    $metadata['content_page_file_count'] ?? 0
                );
                $operation['content_page_unsupported_count'] = (int) (
                    $metadata['content_page_unsupported_count'] ?? 0
                );
                $operation['migration_media_file_count'] = (int) (
                    $metadata['migration_media_file_count'] ?? 0
                );
                $operation['migration_embedded_file_count'] = (int) (
                    $metadata['migration_embedded_file_count'] ?? 0
                );

                if (!empty($mapping['legacy_mapping'])) {
                    $operation['legacy_sourceinstance_mapping'] = true;
                }
                if (!$pageavailable) {
                    $operation['reason'] = 'PAGE_MODULE_DISABLED';
                }
                continue;
            }

            // Phase 6.5 is intentionally downstream from Phases 2-6. Every
            // previously supported object in this export must already be mapped
            // to an existing Moodle object before Content Page can be applied.
            if (in_array(
                $kind,
                [
                    'course',
                    'section',
                    'subsection',
                    'file',
                    'url',
                    'html_module',
                    'scorm',
                    'learning_module',
                    'question_pool',
                    'test',
                ],
                true
            ) && $action !== 'UPDATE') {
                $pending[] = [
                    'kind' => $kind,
                    'source_ref_id' => $sourceref,
                    'action' => $action,
                    'target_id' => $operation['target_id'] ?? null,
                ];
            }
        }
        unset($operation);

        if (!$pageavailable) {
            $plan['warnings'][] = [
                'code' => 'PAGE_MODULE_DISABLED',
                'message' => 'Moodle mod_page is missing or disabled; Content Page migration cannot run.',
            ];
        }

        if ($pending) {
            $plan['warnings'][] = [
                'code' => 'PHASE6_PREREQUISITES_PENDING',
                'source_ref_ids' => array_values(array_map(
                    static fn(array $item): string => (string) $item['source_ref_id'],
                    $pending
                )),
                'message' => 'This export contains Phase 2-6 objects that must already be synchronized before Content Page.',
            ];
        }

        $plan['phase65_prerequisites'] = [
            'pending_operations' => $pending,
            'pending_count' => count($pending),
            'page_available' => $pageavailable,
            'ready' => $pageavailable && count($pending) === 0,
        ];

        return $plan;
    }

    /** Index neutral item metadata by source ref_id. */
    private function index_items(array $items, array &$index): void {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sourceid = (string) ($item['source_id'] ?? '');
            if ($sourceid !== '') {
                $index[$sourceid] = is_array($item['metadata'] ?? null)
                    ? $item['metadata']
                    : [];
            }
            $this->index_items(is_array($item['items'] ?? null) ? $item['items'] : [], $index);
        }
    }

    /** Resolve mod_page CREATE/UPDATE state from the persistent mapping table. */
    private function resolve_page_action(
        string $sourceinstance,
        string $sourcecourse,
        string $sourceref,
        int $targetcourseid
    ): array {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => 'page',
        ];
        $mapping = $DB->get_record('local_iliasmigration_map', $conditions);
        $legacy = false;

        if (!$mapping && $sourceinstance !== '') {
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

        $cmid = (int) ($mapping->targetid ?? 0);
        if ($cmid <= 0) {
            return [
                'action' => 'ERROR_STALE_MAPPING',
                'target_id' => null,
                'legacy_mapping' => $legacy,
            ];
        }

        $record = $DB->get_record_sql(
            'SELECT cm.id, cm.course, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = ?',
            [$cmid]
        );
        if (!$record) {
            return [
                'action' => 'ERROR_STALE_MAPPING',
                'target_id' => $cmid,
                'legacy_mapping' => $legacy,
            ];
        }
        if ((string) $record->modulename !== 'page'
                || ($targetcourseid > 0 && (int) $record->course !== $targetcourseid)) {
            return [
                'action' => 'ERROR_MAPPING_TYPE',
                'target_id' => $cmid,
                'legacy_mapping' => $legacy,
            ];
        }

        return [
            'action' => 'UPDATE',
            'target_id' => $cmid,
            'legacy_mapping' => $legacy,
        ];
    }
}

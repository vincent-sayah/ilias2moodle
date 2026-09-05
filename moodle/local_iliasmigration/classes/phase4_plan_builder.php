<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the Phase 4 SCORM preview on top of the validated Phase 3 structure/resource plan.
 */
final class phase4_plan_builder {
    /** @var int Moodle target course category id. */
    private int $categoryid;

    /**
     * @param int $categoryid Moodle target category id.
     */
    public function __construct(int $categoryid) {
        $this->categoryid = $categoryid;
    }

    /**
     * Build a read-only Phase 4 plan.
     *
     * Earlier phases must already be applied before a future Phase 4 write is
     * allowed. The plan still exposes pending Phase 3 work so the operator can
     * reconcile a newer export before importing SCORM packages.
     *
     * @param array $document Validated migration document.
     * @return array Import plan.
     */
    public function build(array $document): array {
        global $DB;

        $plan = (new plan_builder($this->categoryid, 3))->build($document);
        $plan['phase'] = 4;

        $scormmodule = $DB->get_record('modules', ['name' => 'scorm'], 'id,name,visible');
        $scormavailable = $scormmodule && (int) $scormmodule->visible === 1;
        $plan['moodle']['scorm_available'] = $scormavailable;

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

            if ($kind === 'scorm') {
                $metadata = $metadataindex[$sourceref] ?? [];
                $mapping = $this->resolve_scorm_action(
                    $sourceinstance,
                    $sourcecourseid,
                    $sourceref,
                    $targetcourseid
                );

                $operation['action'] = $scormavailable ? $mapping['action'] : 'BLOCKED';
                $operation['target_id'] = $mapping['target_id'];
                $operation['moodle_module'] = 'scorm';
                $operation['migration_package_path'] = (string) ($metadata['migration_package_path'] ?? '');
                $operation['source_package_path'] = (string) ($metadata['package_path'] ?? '');
                $operation['scorm_subtype'] = (string) ($metadata['scorm_subtype'] ?? '');
                $operation['tries'] = (int) ($metadata['tries'] ?? 0);
                $operation['width'] = (int) ($metadata['width'] ?? 0);
                $operation['height'] = (int) ($metadata['height'] ?? 0);

                if (!empty($mapping['legacy_mapping'])) {
                    $operation['legacy_sourceinstance_mapping'] = true;
                }
                if (!$scormavailable) {
                    $operation['reason'] = 'SCORM_MODULE_DISABLED';
                }
                continue;
            }

            // Phase 4 may only write after all earlier structural/simple-resource
            // operations are already mapped to real Moodle objects.
            if (in_array($kind, ['course', 'section', 'subsection', 'file', 'url', 'html_module'], true)
                    && $action !== 'UPDATE') {
                $pending[] = [
                    'kind' => $kind,
                    'source_ref_id' => $sourceref,
                    'action' => $action,
                    'target_id' => $operation['target_id'] ?? null,
                ];
            }
        }
        unset($operation);

        if (!$scormavailable) {
            $plan['warnings'][] = [
                'code' => 'SCORM_MODULE_DISABLED',
                'message' => 'Moodle mod_scorm is missing or disabled; Phase 4 cannot be applied.',
            ];
        }

        if ($pending) {
            $plan['warnings'][] = [
                'code' => 'PHASE3_PREREQUISITES_PENDING',
                'source_ref_ids' => array_values(array_map(
                    static fn(array $item): string => (string) $item['source_ref_id'],
                    $pending
                )),
                'message' => 'This export contains structural or simple-resource changes that must be applied before SCORM.',
            ];
        }

        $this->append_root_scorm_order_warning($document['course']['items'] ?? [], $plan['warnings']);

        $plan['phase4_prerequisites'] = [
            'pending_operations' => $pending,
            'pending_count' => count($pending),
            'ready' => $scormavailable && count($pending) === 0,
        ];

        return $plan;
    }

    /**
     * Index neutral item metadata by source ref_id.
     *
     * @param array $items Migration items.
     * @param array $index Output metadata index.
     */
    private function index_items(array $items, array &$index): void {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sourceid = (string) ($item['source_id'] ?? '');
            if ($sourceid !== '') {
                $index[$sourceid] = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
            }
            $this->index_items(is_array($item['items'] ?? null) ? $item['items'] : [], $index);
        }
    }

    /**
     * Resolve SCORM CREATE/UPDATE state from the persistent mapping table.
     *
     * @return array Action descriptor.
     */
    private function resolve_scorm_action(
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
            'targettype' => 'scorm',
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
        if ((string) $record->modulename !== 'scorm'
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

    /**
     * Warn when a root SCORM follows a first-level ILIAS folder.
     *
     * With the current course model, root activities are placed in Moodle
     * section 0 and therefore display before regular sections.
     */
    private function append_root_scorm_order_warning(array $items, array &$warnings): void {
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
            if ($seenfolder && $type === 'scorm') {
                $affected[] = (string) ($item['source_id'] ?? '');
            }
        }

        if ($affected) {
            $warnings[] = [
                'code' => 'ROOT_SCORM_ORDER_APPROXIMATION',
                'source_ref_ids' => $affected,
                'message' => 'Root SCORM activities live in Moodle section 0 and cannot currently be interleaved after regular sections.',
            ];
        }
    }
}

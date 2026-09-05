<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the Phase 5 Learning Module -> Moodle Book preview.
 *
 * The planner is deliberately read-only. It extends the Phase 4 plan so every
 * earlier structural/resource/SCORM operation is resolved before a Book write
 * can later be enabled.
 */
final class phase5_plan_builder {
    /** @var int Moodle target course category id. */
    private int $categoryid;

    /**
     * @param int $categoryid Moodle target category id.
     */
    public function __construct(int $categoryid) {
        $this->categoryid = $categoryid;
    }

    /**
     * Build a read-only Phase 5 plan.
     *
     * @param array $document Validated migration document.
     * @return array Import plan.
     */
    public function build(array $document): array {
        global $CFG, $DB;

        $plan = (new phase4_plan_builder($this->categoryid))->build($document);
        $plan['phase'] = 5;

        $bookmodule = $DB->get_record('modules', ['name' => 'book'], 'id,name,visible');
        $bookavailable = $bookmodule && (int) $bookmodule->visible === 1;
        $importhtmlavailable = is_readable($CFG->dirroot . '/mod/book/tool/importhtml/locallib.php');
        $plan['moodle']['book_available'] = $bookavailable;
        $plan['moodle']['book_importhtml_available'] = $importhtmlavailable;

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

            if ($kind === 'learning_module') {
                $metadata = $metadataindex[$sourceref] ?? [];
                $mapping = $this->resolve_book_action(
                    $sourceinstance,
                    $sourcecourseid,
                    $sourceref,
                    $targetcourseid
                );

                $operation['action'] = ($bookavailable && $importhtmlavailable)
                    ? $mapping['action']
                    : 'BLOCKED';
                $operation['target_id'] = $mapping['target_id'];
                $operation['moodle_module'] = 'book';
                $operation['migration_structure_path'] = (string) (
                    $metadata['migration_structure_path'] ?? ''
                );
                $operation['learning_module_schema_version'] = (string) (
                    $metadata['learning_module_schema_version'] ?? ''
                );
                $operation['learning_module_node_count'] = (int) (
                    $metadata['learning_module_node_count'] ?? 0
                );
                $operation['learning_module_chapter_count'] = (int) (
                    $metadata['learning_module_chapter_count'] ?? 0
                );
                $operation['learning_module_page_count'] = (int) (
                    $metadata['learning_module_page_count'] ?? 0
                );
                $operation['learning_module_media_count'] = (int) (
                    $metadata['learning_module_media_count'] ?? 0
                );
                $operation['learning_module_file_count'] = (int) (
                    $metadata['learning_module_file_count'] ?? 0
                );
                $operation['learning_module_unsupported_count'] = (int) (
                    $metadata['learning_module_unsupported_count'] ?? 0
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
                if (!$bookavailable) {
                    $operation['reason'] = 'BOOK_MODULE_DISABLED';
                } else if (!$importhtmlavailable) {
                    $operation['reason'] = 'BOOK_IMPORTHTML_UNAVAILABLE';
                }
                continue;
            }

            // A future Phase 5 apply may only run when all earlier phases are
            // already represented by stable Moodle objects.
            if (in_array(
                $kind,
                ['course', 'section', 'subsection', 'file', 'url', 'html_module', 'scorm'],
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

        if (!$bookavailable) {
            $plan['warnings'][] = [
                'code' => 'BOOK_MODULE_DISABLED',
                'message' => 'Moodle mod_book is missing or disabled; Phase 5 cannot be applied.',
            ];
        }

        if (!$importhtmlavailable) {
            $plan['warnings'][] = [
                'code' => 'BOOK_IMPORTHTML_UNAVAILABLE',
                'message' => 'Moodle core booktool_importhtml is unavailable; safe Phase 5 chapter creation cannot run.',
            ];
        }

        if ($pending) {
            $plan['warnings'][] = [
                'code' => 'PHASE4_PREREQUISITES_PENDING',
                'source_ref_ids' => array_values(array_map(
                    static fn(array $item): string => (string) $item['source_ref_id'],
                    $pending
                )),
                'message' => 'This export contains Phase 2/3/4 changes that must be synchronized before Moodle Book.',
            ];
        }

        $this->append_root_book_order_warning(
            $document['course']['items'] ?? [],
            $plan['warnings']
        );

        $plan['phase5_prerequisites'] = [
            'pending_operations' => $pending,
            'pending_count' => count($pending),
            'book_available' => $bookavailable,
            'book_importhtml_available' => $importhtmlavailable,
            'ready' => $bookavailable && $importhtmlavailable && count($pending) === 0,
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
                $index[$sourceid] = is_array($item['metadata'] ?? null)
                    ? $item['metadata']
                    : [];
            }
            $this->index_items(is_array($item['items'] ?? null) ? $item['items'] : [], $index);
        }
    }

    /**
     * Resolve Moodle Book CREATE/UPDATE state from the persistent mapping table.
     *
     * @return array Action descriptor.
     */
    private function resolve_book_action(
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
            'targettype' => 'book',
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
        if ((string) $record->modulename !== 'book'
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
     * Warn when a root Learning Module follows an ILIAS first-level folder.
     */
    private function append_root_book_order_warning(array $items, array &$warnings): void {
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
            if ($seenfolder && $type === 'learning_module') {
                $affected[] = (string) ($item['source_id'] ?? '');
            }
        }

        if ($affected) {
            $warnings[] = [
                'code' => 'ROOT_BOOK_ORDER_APPROXIMATION',
                'source_ref_ids' => $affected,
                'message' => 'Root Moodle Book activities live in section 0 and cannot currently be interleaved after regular sections.',
            ];
        }
    }
}

<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only validator for ILIAS Media Pool -> Moodle mod_data.
 */
final class phase65_media_pool_package_validator {
    private string $packageroot;
    private string $sourceinstance = '';
    private string $sourcecourse = '';
    private int $targetcourseid = 0;

    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        if ($root === false || !is_dir($root)) {
            throw new \coding_exception(
                'Unable to resolve the migration package directory.'
            );
        }
        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
    }

    public function validate(array $plan): array {
        global $DB;

        $module = $DB->get_record(
            'modules',
            ['name' => 'data'],
            'id,name,visible'
        );
        $available = $module && (int) $module->visible === 1;
        $plan['moodle']['data_available'] = $available;

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $this->sourcecourse = (string) ($plan['course']['source_id'] ?? '');
        $this->targetcourseid = (int) ($plan['operations'][0]['target_id'] ?? 0);

        $discovered = 0;
        $checked = 0;
        $skipped = 0;
        $blocked = 0;
        $creates = 0;
        $updates = 0;
        $records = 0;
        $assets = 0;
        $images = 0;
        $videos = 0;
        $pages = 0;
        $folders = 0;

        foreach ($plan['operations'] as &$operation) {
            if ((string) ($operation['kind'] ?? '') !== 'media_pool') {
                continue;
            }

            $discovered++;
            $sourceref = (string) ($operation['source_ref_id'] ?? '');
            $operation['phase'] = '6.5.8';
            $operation['moodle_module'] = 'data';
            $operation['migration_structure_path'] =
                'media_pools/' . $sourceref . '/structure.json';

            if ($this->resolve_relative_file(
                (string) $operation['migration_structure_path']
            ) === null) {
                $operation['action'] = 'DEFER';
                $operation['target_id'] = null;
                $operation['reason'] = 'MEDIA_POOL_NOT_IN_PACKAGE';
                $operation['media_pool_validation'] = [
                    'status' => 'SKIPPED_INCREMENTAL',
                    'code' => 'MEDIA_POOL_NOT_IN_PACKAGE',
                ];
                $skipped++;
                continue;
            }

            $checked++;
            $mapping = $this->resolve_action($sourceref);
            $operation['action'] = $available
                ? $mapping['action']
                : 'BLOCKED';
            $operation['target_id'] = $mapping['target_id'];

            if (!$available) {
                $this->block(
                    $operation,
                    'MEDIA_POOL_DATA_MODULE_DISABLED',
                    'Moodle mod_data is missing or disabled.'
                );
                $blocked++;
                continue;
            }

            if (!in_array(
                (string) $operation['action'],
                ['CREATE', 'UPDATE'],
                true
            )) {
                $this->block(
                    $operation,
                    'MEDIA_POOL_MAPPING_INVALID',
                    'The persistent Media Pool mapping is stale or invalid.'
                );
                $blocked++;
                continue;
            }

            $parent = $this->validate_parent_target($operation);
            $operation['media_pool_parent_validation'] = $parent;
            if (empty($parent['ready'])) {
                $this->block(
                    $operation,
                    'MEDIA_POOL_PARENT_MAPPING_INVALID',
                    (string) ($parent['message'] ?? 'Invalid Media Pool parent.')
                );
                $blocked++;
                continue;
            }

            $summary = $this->validate_media_pool($operation);
            $records += (int) ($summary['records'] ?? 0);
            $assets += (int) ($summary['assets'] ?? 0);
            $images += (int) ($summary['images'] ?? 0);
            $videos += (int) ($summary['videos'] ?? 0);
            $pages += (int) ($summary['pages'] ?? 0);
            $folders += (int) ($summary['folders'] ?? 0);

            if (($operation['action'] ?? '') === 'BLOCKED') {
                $blocked++;
                continue;
            }

            if ((string) $operation['action'] === 'CREATE') {
                $creates++;
            } else if ((string) $operation['action'] === 'UPDATE') {
                $updates++;
            }
        }
        unset($operation);

        if ($checked === 0) {
            $plan['warnings'][] = [
                'code' => 'NO_MEDIA_POOL_SELECTED',
                'message' => 'No normalized ILIAS Media Pool was found in this package.',
            ];
        }

        $ready = $checked > 0
            && $blocked === 0
            && $available
            && $this->targetcourseid > 0;

        $plan['phase65_media_pool_package'] = [
            'root' => $this->packageroot,
            'discovered_media_pools' => $discovered,
            'checked_media_pools' => $checked,
            'skipped_media_pools' => $skipped,
            'blocked_media_pools' => $blocked,
            'media_pool_create_count' => $creates,
            'media_pool_update_count' => $updates,
            'record_count' => $records,
            'asset_count' => $assets,
            'image_count' => $images,
            'video_count' => $videos,
            'page_record_count' => $pages,
            'folder_count' => $folders,
            'data_available' => $available,
            'target_activity' => 'mod_data',
            'record_policy' => 'ONE_RECORD_PER_CONTENT_TREE_ITEM',
            'folder_policy' => 'PRESERVE_AS_FOLDER_PATH_METADATA',
            'preview_policy' => 'DO_NOT_IMPORT',
            'audio_policy' => 'NOT_VALIDATED_BY_REAL_POC',
            'ready' => $ready,
            'apply_implemented' => false,
            'apply_ready' => false,
        ];

        return $plan;
    }

    private function validate_media_pool(array &$operation): array {
        $file = $this->resolve_relative_file(
            (string) ($operation['migration_structure_path'] ?? '')
        );
        if ($file === null) {
            $this->block(
                $operation,
                'MEDIA_POOL_STRUCTURE_MISSING',
                'Normalized Media Pool structure.json is missing.'
            );
            return $this->empty_summary();
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            $this->block(
                $operation,
                'MEDIA_POOL_STRUCTURE_UNREADABLE',
                'Unable to read Media Pool structure.json.'
            );
            return $this->empty_summary();
        }

        try {
            $structure = json_decode(
                $raw,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            $this->block(
                $operation,
                'MEDIA_POOL_STRUCTURE_INVALID_JSON',
                'Media Pool structure.json is invalid JSON.'
            );
            return $this->empty_summary();
        }

        if (!is_array($structure)
                || (string) ($structure['schema_version'] ?? '') !== '1.0') {
            $this->block(
                $operation,
                'MEDIA_POOL_SCHEMA_UNSUPPORTED',
                'Media Pool structure.json must use schema_version 1.0.'
            );
            return $this->empty_summary();
        }

        $source = is_array($structure['source'] ?? null)
            ? $structure['source']
            : [];
        if ((string) ($source['lms'] ?? '') !== 'ILIAS'
                || (string) ($source['ref_id'] ?? '')
                    !== (string) ($operation['source_ref_id'] ?? '')
                || (string) ($source['object_id'] ?? '')
                    !== (string) ($operation['source_obj_id'] ?? '')) {
            $this->block(
                $operation,
                'MEDIA_POOL_SOURCE_MISMATCH',
                'Media Pool source identity does not match the planned object.'
            );
            return $this->empty_summary();
        }

        $strategy = is_array($structure['target_strategy'] ?? null)
            ? $structure['target_strategy']
            : [];
        if ((string) ($strategy['moodle_activity'] ?? '') !== 'mod_data'
                || (string) ($strategy['collection_policy'] ?? '')
                    !== 'one_database_per_media_pool'
                || (string) ($strategy['record_policy'] ?? '')
                    !== 'one_record_per_content_tree_item'
                || (string) ($strategy['folder_policy'] ?? '')
                    !== 'preserve_as_folder_path_metadata'
                || (string) ($strategy['dummy_policy'] ?? '') !== 'skip') {
            $this->block(
                $operation,
                'MEDIA_POOL_TARGET_STRATEGY_INVALID',
                'Media Pool target strategy does not match Phase 6.5.8.'
            );
            return $this->empty_summary();
        }

        $unsupported = $structure['unsupported_components'] ?? null;
        if (!is_array($unsupported) || $unsupported) {
            $this->block(
                $operation,
                'MEDIA_POOL_UNSUPPORTED_COMPONENTS',
                'Media Pool contains unsupported components.'
            );
            return $this->empty_summary();
        }

        $tree = is_array($structure['tree'] ?? null)
            ? $structure['tree']
            : [];
        $records = is_array($structure['records'] ?? null)
            ? $structure['records']
            : [];
        $media = is_array($structure['media'] ?? null)
            ? $structure['media']
            : [];
        $counts = is_array($structure['counts'] ?? null)
            ? $structure['counts']
            : [];

        if (!$tree || !$records || !$media) {
            $this->block(
                $operation,
                'MEDIA_POOL_EMPTY',
                'Media Pool must contain tree, records and media.'
            );
            return $this->empty_summary();
        }

        $treeids = [];
        $foldertitles = [];
        foreach ($tree as $node) {
            if (!is_array($node)) {
                $this->block(
                    $operation,
                    'MEDIA_POOL_TREE_INVALID',
                    'At least one Media Pool tree node is invalid.'
                );
                return $this->empty_summary();
            }

            $treeid = trim((string) ($node['source_tree_id'] ?? ''));
            $type = (string) ($node['type'] ?? '');
            if ($treeid === '' || isset($treeids[$treeid])) {
                $this->block(
                    $operation,
                    'MEDIA_POOL_TREE_IDENTITY_INVALID',
                    'Media Pool tree ids must be present and unique.'
                );
                return $this->empty_summary();
            }
            if (!in_array($type, ['dummy', 'fold', 'mob', 'pg'], true)) {
                $this->block(
                    $operation,
                    'MEDIA_POOL_TREE_TYPE_UNSUPPORTED',
                    'Media Pool tree contains an unsupported node type.'
                );
                return $this->empty_summary();
            }

            $treeids[$treeid] = $node;
            if ($type === 'fold') {
                $title = trim((string) ($node['title'] ?? ''));
                if ($title === '') {
                    $this->block(
                        $operation,
                        'MEDIA_POOL_FOLDER_TITLE_MISSING',
                        'Media Pool folders must have a title.'
                    );
                    return $this->empty_summary();
                }
                $foldertitles[$treeid] = $title;
            }
        }

        $assetresult = $this->validate_assets($operation, $media);
        if (($operation['action'] ?? '') === 'BLOCKED') {
            return $this->empty_summary();
        }

        $recordids = [];
        $positions = [];
        $pages = 0;

        foreach ($records as $record) {
            if (!is_array($record)) {
                $this->block(
                    $operation,
                    'MEDIA_POOL_RECORD_INVALID',
                    'At least one Media Pool record is invalid.'
                );
                return $this->empty_summary();
            }

            $treeid = trim((string) ($record['source_tree_id'] ?? ''));
            $position = (int) ($record['position'] ?? 0);
            $type = (string) ($record['item_type'] ?? '');
            $title = trim((string) ($record['title'] ?? ''));

            if ($treeid === ''
                    || isset($recordids[$treeid])
                    || !isset($treeids[$treeid])
                    || $position <= 0
                    || isset($positions[$position])
                    || $title === '') {
                $this->block(
                    $operation,
                    'MEDIA_POOL_RECORD_IDENTITY_INVALID',
                    'Media Pool record identity/position/title is invalid.'
                );
                return $this->empty_summary();
            }

            $recordids[$treeid] = true;
            $positions[$position] = true;

            $folderpath = is_array($record['folder_path'] ?? null)
                ? $record['folder_path']
                : null;
            if ($folderpath === null) {
                $this->block(
                    $operation,
                    'MEDIA_POOL_FOLDER_PATH_INVALID',
                    'Media Pool record folder_path must be an array.'
                );
                return $this->empty_summary();
            }
            foreach ($folderpath as $segment) {
                if (trim((string) $segment) === '') {
                    $this->block(
                        $operation,
                        'MEDIA_POOL_FOLDER_PATH_INVALID',
                        'Media Pool folder_path contains an empty segment.'
                    );
                    return $this->empty_summary();
                }
            }

            if ($type === 'media') {
                $mediaid = trim((string) ($record['media_source_id'] ?? ''));
                if ($mediaid === '' || !isset($media[$mediaid])) {
                    $this->block(
                        $operation,
                        'MEDIA_POOL_RECORD_MEDIA_MISSING',
                        'A Media Pool media record has no matching MediaObject.'
                    );
                    return $this->empty_summary();
                }
            } else if ($type === 'page') {
                $content = is_array($record['content'] ?? null)
                    ? $record['content']
                    : [];
                $blocks = is_array($content['blocks'] ?? null)
                    ? $content['blocks']
                    : [];
                $pageunsupported = $content['unsupported_components'] ?? null;
                if ((string) ($content['status'] ?? '') !== 'ok'
                        || !$blocks
                        || !is_array($pageunsupported)
                        || $pageunsupported
                        || $this->contains_internal_link($blocks)) {
                    $this->block(
                        $operation,
                        'MEDIA_POOL_PAGE_CONTENT_INVALID',
                        'A Media Pool Page Editor record is incomplete or unsupported.'
                    );
                    return $this->empty_summary();
                }

                foreach ($this->collect_media_refs($blocks) as $mediaid) {
                    if (!isset($media[$mediaid])) {
                        $this->block(
                            $operation,
                            'MEDIA_POOL_PAGE_MEDIA_MISSING',
                            'A Media Pool Page Editor block references a missing MediaObject.'
                        );
                        return $this->empty_summary();
                    }
                }
                $pages++;
            } else {
                $this->block(
                    $operation,
                    'MEDIA_POOL_RECORD_TYPE_UNSUPPORTED',
                    'Media Pool records must be media or page.'
                );
                return $this->empty_summary();
            }
        }

        if ((int) ($counts['tree_nodes'] ?? -1) !== count($tree)
                || (int) ($counts['content_records'] ?? -1) !== count($records)
                || (int) ($counts['all_media'] ?? -1) !== count($media)
                || (int) ($counts['folders'] ?? -1) !== count($foldertitles)
                || (int) ($counts['page_items'] ?? -1) !== $pages) {
            $this->block(
                $operation,
                'MEDIA_POOL_COUNT_MISMATCH',
                'Media Pool normalized counters do not match validated content.'
            );
            return $this->empty_summary();
        }

        try {
            $render = (new phase65_media_pool_renderer())->render(
                $structure,
                static function(string $path, array $asset): string {
                    return '@@PLUGINFILE@@/'
                        . implode(
                            '/',
                            array_map(
                                'rawurlencode',
                                array_values(array_filter(
                                    explode('/', $path),
                                    static fn(string $part): bool =>
                                        $part !== ''
                                ))
                            )
                        );
                }
            );
        } catch (\Throwable $exception) {
            $this->block(
                $operation,
                'MEDIA_POOL_RENDER_FAILED',
                'Media Pool rendering failed: ' . $exception->getMessage()
            );
            return $this->empty_summary();
        }

        $rendered = is_array($render['records'] ?? null)
            ? $render['records']
            : [];
        if (count($rendered) !== count($records)) {
            $this->block(
                $operation,
                'MEDIA_POOL_RENDER_COUNT_MISMATCH',
                'Rendered Media Pool record count differs from source records.'
            );
            return $this->empty_summary();
        }
        foreach ($rendered as $renderedrecord) {
            if (!is_array($renderedrecord)
                    || trim((string) ($renderedrecord['html'] ?? '')) === '') {
                $this->block(
                    $operation,
                    'MEDIA_POOL_RENDER_EMPTY',
                    'At least one Media Pool record rendered to empty HTML.'
                );
                return $this->empty_summary();
            }
        }

        $operation['media_pool_validation'] = [
            'status' => 'READY',
            'code' => 'MEDIA_POOL_READY',
            'schema_version' => '1.0',
            'tree_node_count' => count($tree),
            'record_count' => count($records),
            'folder_count' => count($foldertitles),
            'page_record_count' => $pages,
            'asset_count' => (int) $assetresult['assets'],
            'image_count' => (int) $assetresult['images'],
            'video_count' => (int) $assetresult['videos'],
            'moodle_activity' => 'mod_data',
            'record_policy' => 'ONE_RECORD_PER_CONTENT_TREE_ITEM',
            'planned_fields' => [
                'source_tree_id',
                'position',
                'item_type',
                'title',
                'folder_path',
                'media_source_id',
                'content',
            ],
            'preview_policy' => 'DO_NOT_IMPORT',
            'audio_policy' => 'NOT_VALIDATED_BY_REAL_POC',
            'fingerprint_sha256' => (string) (
                $render['fingerprint_sha256'] ?? ''
            ),
        ];

        return [
            'records' => count($records),
            'assets' => (int) $assetresult['assets'],
            'images' => (int) $assetresult['images'],
            'videos' => (int) $assetresult['videos'],
            'pages' => $pages,
            'folders' => count($foldertitles),
        ];
    }

    private function validate_assets(
        array &$operation,
        array $media
    ): array {
        $assets = 0;
        $images = 0;
        $videos = 0;

        foreach ($media as $mediaid => $mediaobject) {
            if (!is_array($mediaobject)) {
                $this->block(
                    $operation,
                    'MEDIA_POOL_MEDIA_INVALID',
                    'At least one MediaObject is invalid.'
                );
                return ['assets' => 0, 'images' => 0, 'videos' => 0];
            }

            $items = is_array($mediaobject['items'] ?? null)
                ? $mediaobject['items']
                : [];
            if (!$items) {
                $this->block(
                    $operation,
                    'MEDIA_POOL_MEDIA_ITEM_MISSING',
                    'At least one MediaObject has no media item.'
                );
                return ['assets' => 0, 'images' => 0, 'videos' => 0];
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $path = trim((string) ($item['migration_path'] ?? ''));
                $size = (int) ($item['migration_size'] ?? 0);
                $sha256 = strtolower(trim((string) (
                    $item['migration_sha256'] ?? ''
                )));
                $mime = strtolower(trim((string) ($item['mime_type'] ?? '')));

                if (basename($path) === 'mob_vpreview.png') {
                    $this->block(
                        $operation,
                        'MEDIA_POOL_PREVIEW_IMPORTED',
                        'ILIAS mob_vpreview.png must not be imported.'
                    );
                    return ['assets' => 0, 'images' => 0, 'videos' => 0];
                }

                if (!str_starts_with($mime, 'image/')
                        && $mime !== 'video/mp4') {
                    $this->block(
                        $operation,
                        'MEDIA_POOL_MIME_NOT_VALIDATED',
                        'Only image/* and video/mp4 are validated by this real POC.'
                    );
                    return ['assets' => 0, 'images' => 0, 'videos' => 0];
                }

                $file = $this->resolve_relative_file($path);
                if ($file === null
                        || $size <= 0
                        || !preg_match('/^[0-9a-f]{64}$/', $sha256)) {
                    $this->block(
                        $operation,
                        'MEDIA_POOL_ASSET_PACKAGE_INVALID',
                        'Media Pool asset file/size/SHA-256 is incomplete.'
                    );
                    return ['assets' => 0, 'images' => 0, 'videos' => 0];
                }

                $actualsize = filesize($file);
                $actualsha256 = hash_file('sha256', $file);
                if ($actualsize === false
                        || $actualsha256 === false
                        || (int) $actualsize !== $size
                        || !hash_equals(
                            $sha256,
                            strtolower($actualsha256)
                        )) {
                    $this->block(
                        $operation,
                        'MEDIA_POOL_ASSET_INTEGRITY_ERROR',
                        'Media Pool asset no longer matches size/SHA-256.'
                    );
                    return ['assets' => 0, 'images' => 0, 'videos' => 0];
                }

                $assets++;
                if (str_starts_with($mime, 'image/')) {
                    $images++;
                } else {
                    $videos++;
                }
            }
        }

        return [
            'assets' => $assets,
            'images' => $images,
            'videos' => $videos,
        ];
    }

    private function collect_media_refs(array $value): array {
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string) ($item['type'] ?? '') === 'media') {
                $id = trim((string) ($item['source_id'] ?? ''));
                if ($id !== '') {
                    $result[$id] = true;
                }
            }
            foreach ($this->collect_media_refs($item) as $id) {
                $result[$id] = true;
            }
        }
        return array_keys($result);
    }

    private function contains_internal_link(array $value): bool {
        foreach ($value as $key => $item) {
            if ($key === 'type' && $item === 'internal_link') {
                return true;
            }
            if (is_array($item) && $this->contains_internal_link($item)) {
                return true;
            }
        }
        return false;
    }

    private function validate_parent_target(array $operation): array {
        global $DB;

        $parentref = trim((string) (
            $operation['parent_source_ref_id'] ?? ''
        ));
        if ($parentref === '') {
            return [
                'ready' => true,
                'kind' => 'course_root',
                'section_number' => 0,
                'target_id' => null,
            ];
        }

        foreach (['section', 'subsection'] as $targettype) {
            $mapping = $this->find_mapping($parentref, $targettype);
            if (!$mapping) {
                continue;
            }

            if ($targettype === 'section') {
                $section = $DB->get_record(
                    'course_sections',
                    [
                        'id' => (int) $mapping->targetid,
                        'course' => $this->targetcourseid,
                    ],
                    'id,section'
                );
                if ($section) {
                    return [
                        'ready' => true,
                        'kind' => 'section',
                        'section_number' => (int) $section->section,
                        'target_id' => (int) $section->id,
                    ];
                }
                continue;
            }

            $cm = $DB->get_record(
                'course_modules',
                [
                    'id' => (int) $mapping->targetid,
                    'course' => $this->targetcourseid,
                ],
                'id,instance'
            );
            if (!$cm) {
                continue;
            }

            $section = $DB->get_record(
                'course_sections',
                [
                    'course' => $this->targetcourseid,
                    'component' => 'mod_subsection',
                    'itemid' => (int) $cm->instance,
                ],
                'id,section'
            );
            if ($section) {
                return [
                    'ready' => true,
                    'kind' => 'subsection',
                    'section_number' => (int) $section->section,
                    'target_id' => (int) $cm->id,
                ];
            }
        }

        return [
            'ready' => false,
            'kind' => 'unresolved',
            'section_number' => null,
            'target_id' => null,
            'message' => 'No Moodle section/subsection mapping exists for the Media Pool parent.',
        ];
    }

    private function resolve_action(string $sourceref): array {
        global $DB;

        $mapping = $this->find_mapping($sourceref, 'data');
        if (!$mapping) {
            return [
                'action' => 'CREATE',
                'target_id' => null,
            ];
        }

        $cmid = (int) ($mapping->targetid ?? 0);
        if ($cmid <= 0) {
            return [
                'action' => 'ERROR_STALE_MAPPING',
                'target_id' => null,
            ];
        }

        $record = $DB->get_record_sql(
            'SELECT cm.id, cm.course, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = ?',
            [$cmid]
        );

        if (!$record
                || (string) $record->modulename !== 'data'
                || (int) $record->course !== $this->targetcourseid) {
            return [
                'action' => 'ERROR_MAPPING_TYPE',
                'target_id' => $cmid,
            ];
        }

        return [
            'action' => 'UPDATE',
            'target_id' => $cmid,
        ];
    }

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

    private function resolve_relative_file(string $relative): ?string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === ''
                || str_starts_with($relative, '/')
                || str_contains($relative, '../')) {
            return null;
        }

        $candidate = realpath(
            $this->packageroot
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative)
        );

        if ($candidate === false || !is_file($candidate)) {
            return null;
        }

        if (!str_starts_with(
            $candidate,
            $this->packageroot . DIRECTORY_SEPARATOR
        )) {
            return null;
        }

        return $candidate;
    }

    private function block(
        array &$operation,
        string $code,
        string $message
    ): void {
        $operation['action'] = 'BLOCKED';
        $operation['reason'] = $code;
        $operation['media_pool_validation'] = [
            'status' => 'BLOCKED',
            'code' => $code,
            'message' => $message,
        ];
    }

    private function empty_summary(): array {
        return [
            'records' => 0,
            'assets' => 0,
            'images' => 0,
            'videos' => 0,
            'pages' => 0,
            'folders' => 0,
        ];
    }
}

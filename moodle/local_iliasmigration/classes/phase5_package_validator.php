<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates normalized ILIAS Learning Module packages without Moodle writes.
 */
final class phase5_package_validator {
    /** @var string Canonical migration package root. */
    private string $packageroot;

    /**
     * @param string $migrationjson Absolute path to migration.json.
     */
    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        if ($root === false || !is_dir($root)) {
            throw new \coding_exception('Unable to resolve the migration package directory.');
        }
        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
    }

    /**
     * Validate all Phase 5 Learning Module operations and annotate the dry-run plan.
     *
     * @param array $plan Phase 5 plan.
     * @return array Annotated plan.
     */
    public function validate(array $plan): array {
        $checked = 0;
        $blocked = 0;

        foreach ($plan['operations'] as &$operation) {
            if (($operation['kind'] ?? '') !== 'learning_module') {
                continue;
            }

            $action = (string) ($operation['action'] ?? '');
            if ($action === 'BLOCKED') {
                $blocked++;
                continue;
            }
            if (!in_array($action, ['CREATE', 'UPDATE'], true)) {
                continue;
            }

            $checked++;
            $this->validate_learning_module($operation, $plan['warnings']);
            if (($operation['action'] ?? '') === 'BLOCKED') {
                $blocked++;
            }
        }
        unset($operation);

        $phase5prerequisites = !empty($plan['phase5_prerequisites']['ready']);
        $phase3ready = !isset($plan['phase3_package']) || !empty($plan['phase3_package']['ready']);
        $phase4ready = !isset($plan['phase4_package']) || !empty($plan['phase4_package']['ready']);
        $structurechecksready = $blocked === 0 && $checked > 0;

        if ($checked === 0) {
            $plan['warnings'][] = [
                'code' => 'NO_LEARNING_MODULE_FOUND',
                'message' => 'No Learning Module CREATE/UPDATE operation was found in this migration package.',
            ];
        }

        $prerequisitesready = $phase5prerequisites && $phase3ready && $phase4ready;
        $plan['phase5_package'] = [
            'root' => $this->packageroot,
            'checked_learning_modules' => $checked,
            'blocked_learning_modules' => $blocked,
            'structure_checks_ready' => $structurechecksready,
            'prerequisites_ready' => $prerequisitesready,
            'ready' => $structurechecksready && $prerequisitesready,
        ];

        return $plan;
    }

    /**
     * Validate one normalized Learning Module structure and all referenced assets.
     */
    private function validate_learning_module(array &$operation, array &$warnings): void {
        $relative = (string) ($operation['migration_structure_path'] ?? '');
        $resolved = $this->resolve_relative_file($relative);
        if ($resolved === null) {
            $this->block(
                $operation,
                'LEARNING_MODULE_STRUCTURE_MISSING',
                'The migration_structure_path is missing, unsafe or not present in the package.'
            );
            return;
        }

        $size = filesize($resolved);
        if ($size === false || $size <= 0 || $size > 32 * 1024 * 1024) {
            $this->block(
                $operation,
                'LEARNING_MODULE_STRUCTURE_SIZE_INVALID',
                'structure.json is empty, unreadable or unexpectedly large.'
            );
            return;
        }

        $raw = file_get_contents($resolved);
        if ($raw === false) {
            $this->block(
                $operation,
                'LEARNING_MODULE_STRUCTURE_READ_FAILED',
                'Unable to read structure.json.'
            );
            return;
        }

        try {
            $structure = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->block(
                $operation,
                'LEARNING_MODULE_STRUCTURE_JSON_INVALID',
                'Invalid structure.json: ' . $exception->getMessage()
            );
            return;
        }

        if (!is_array($structure) || ($structure['schema_version'] ?? null) !== '1.0') {
            $this->block(
                $operation,
                'LEARNING_MODULE_SCHEMA_UNSUPPORTED',
                'Learning Module structure schema must be 1.0.'
            );
            return;
        }

        $source = is_array($structure['source'] ?? null) ? $structure['source'] : [];
        $expectedref = (string) ($operation['source_ref_id'] ?? '');
        if ((string) ($source['lms'] ?? '') !== 'ILIAS'
                || (string) ($source['ref_id'] ?? '') !== $expectedref) {
            $this->block(
                $operation,
                'LEARNING_MODULE_SOURCE_MISMATCH',
                'structure.json source identity does not match the planned Learning Module.'
            );
            return;
        }

        $nodes = $structure['nodes'] ?? null;
        $pages = $structure['pages'] ?? null;
        $media = $structure['media'] ?? null;
        $files = $structure['files'] ?? null;
        $unsupported = $structure['unsupported_components'] ?? null;
        if (!is_array($nodes) || !is_array($pages) || !is_array($media)
                || !is_array($files) || !is_array($unsupported)) {
            $this->block(
                $operation,
                'LEARNING_MODULE_STRUCTURE_INCOMPLETE',
                'structure.json must contain nodes, pages, media, files and unsupported_components arrays.'
            );
            return;
        }

        $nodevalidation = $this->validate_nodes($nodes, $pages);
        if (!$nodevalidation['ok']) {
            $this->block(
                $operation,
                'LEARNING_MODULE_TREE_INVALID',
                (string) $nodevalidation['message']
            );
            return;
        }

        $assetvalidation = $this->validate_assets($media, $files);
        if (!$assetvalidation['ok']) {
            $this->block(
                $operation,
                'LEARNING_MODULE_ASSET_INVALID',
                (string) $assetvalidation['message']
            );
            return;
        }

        $blocksummary = [
            'paragraph' => 0,
            'media' => 0,
            'file_list' => 0,
            'table' => 0,
            'section' => 0,
            'internal_link' => 0,
            'unsupported' => 0,
        ];
        $referencetest = $this->validate_page_blocks(
            $pages,
            $media,
            $files,
            $blocksummary
        );
        if (!$referencetest['ok']) {
            $this->block(
                $operation,
                'LEARNING_MODULE_CONTENT_REFERENCE_INVALID',
                (string) $referencetest['message']
            );
            return;
        }

        if (count($unsupported) > 0 || $blocksummary['unsupported'] > 0) {
            $this->block(
                $operation,
                'LEARNING_MODULE_UNSUPPORTED_COMPONENTS',
                'The Learning Module contains content components that are not yet convertible to Moodle Book.'
            );
            $operation['structure_validation'] = [
                'status' => 'BLOCKED',
                'unsupported_count' => max(count($unsupported), $blocksummary['unsupported']),
                'unsupported_components' => $unsupported,
            ];
            return;
        }

        $chaptercount = (int) $nodevalidation['chapter_count'];
        $pagecount = (int) $nodevalidation['page_count'];
        $bookpreview = $this->build_book_preview($nodes, $pages);

        $operation['book_preview'] = $bookpreview;
        $operation['structure_validation'] = [
            'status' => 'OK',
            'relative_path' => $relative,
            'size' => (int) $size,
            'sha256' => hash_file('sha256', $resolved) ?: null,
            'node_count' => count($nodes),
            'root_count' => (int) $nodevalidation['root_count'],
            'chapter_count' => $chaptercount,
            'page_count' => $pagecount,
            'media_count' => count($media),
            'file_count' => count($files),
            'asset_files_checked' => (int) $assetvalidation['asset_files_checked'],
            'block_counts' => $blocksummary,
            'unsupported_count' => 0,
            'planned_moodle_book_chapters' => count($bookpreview['entries']),
        ];

        $this->compare_declared_count(
            $operation,
            'learning_module_node_count',
            count($nodes),
            $warnings
        );
        $this->compare_declared_count(
            $operation,
            'learning_module_chapter_count',
            $chaptercount,
            $warnings
        );
        $this->compare_declared_count(
            $operation,
            'learning_module_page_count',
            $pagecount,
            $warnings
        );
        $this->compare_declared_count(
            $operation,
            'learning_module_media_count',
            count($media),
            $warnings
        );
        $this->compare_declared_count(
            $operation,
            'learning_module_file_count',
            count($files),
            $warnings
        );

        if ($blocksummary['internal_link'] > 0) {
            $warnings[] = [
                'code' => 'LEARNING_MODULE_INTERNAL_LINK_REWRITE_REQUIRED',
                'source_ref_id' => $expectedref,
                'count' => $blocksummary['internal_link'],
                'message' => 'Internal ILIAS links were detected and will require Moodle Book chapter-link rewriting before apply.',
            ];
        }
    }

    /**
     * Validate tree identities, parents and page payloads.
     *
     * @return array Validation result.
     */
    private function validate_nodes(array $nodes, array $pages): array {
        if (!$nodes) {
            return ['ok' => false, 'message' => 'Learning Module tree contains no nodes.'];
        }

        $ids = [];
        $rootcount = 0;
        $chaptercount = 0;
        $pagecount = 0;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                return ['ok' => false, 'message' => 'Learning Module tree contains a non-object node.'];
            }
            $id = trim((string) ($node['source_id'] ?? ''));
            $type = (string) ($node['type'] ?? '');
            if ($id === '' || isset($ids[$id])) {
                return ['ok' => false, 'message' => 'Learning Module tree contains an empty or duplicate source_id.'];
            }
            if (!in_array($type, ['root', 'chapter', 'page'], true)) {
                return ['ok' => false, 'message' => "Unsupported Learning Module tree node type: {$type}."];
            }
            $ids[$id] = $node;
            if ($type === 'root') {
                $rootcount++;
            } else if ($type === 'chapter') {
                $chaptercount++;
            } else {
                $pagecount++;
            }
        }

        if ($rootcount !== 1) {
            return ['ok' => false, 'message' => 'Learning Module tree must contain exactly one root node.'];
        }

        foreach ($ids as $id => $node) {
            $type = (string) $node['type'];
            $parent = (string) ($node['parent_source_id'] ?? '');
            if ($type === 'root') {
                if ($parent !== '0' && $parent !== '') {
                    return ['ok' => false, 'message' => 'Learning Module root has an invalid parent.'];
                }
                continue;
            }
            if ($parent === '' || !isset($ids[$parent])) {
                return ['ok' => false, 'message' => "Learning Module node {$id} references a missing parent."];
            }
            if ((int) ($node['position'] ?? 0) <= 0) {
                return ['ok' => false, 'message' => "Learning Module node {$id} has an invalid position."];
            }
        }

        if ($pagecount === 0 || count($pages) !== $pagecount) {
            return [
                'ok' => false,
                'message' => 'Learning Module page payload count does not match page nodes.',
            ];
        }
        foreach ($pages as $pageid => $page) {
            if (!isset($ids[(string) $pageid]) || ($ids[(string) $pageid]['type'] ?? '') !== 'page') {
                return ['ok' => false, 'message' => "Page {$pageid} has no matching tree page node."];
            }
            if (!is_array($page) || !is_array($page['blocks'] ?? null)) {
                return ['ok' => false, 'message' => "Page {$pageid} does not contain a valid blocks array."];
            }
        }

        return [
            'ok' => true,
            'root_count' => $rootcount,
            'chapter_count' => $chaptercount,
            'page_count' => $pagecount,
        ];
    }

    /**
     * Validate extracted media and file paths.
     *
     * @return array Validation result.
     */
    private function validate_assets(array $media, array $files): array {
        $checked = 0;

        foreach ($media as $mediaid => $descriptor) {
            if (!is_array($descriptor) || !is_array($descriptor['items'] ?? null)) {
                return ['ok' => false, 'message' => "Media {$mediaid} has no valid items array."];
            }
            foreach ($descriptor['items'] as $item) {
                if (!is_array($item)) {
                    return ['ok' => false, 'message' => "Media {$mediaid} contains an invalid item."];
                }
                $path = (string) ($item['migration_path'] ?? '');
                if ($this->resolve_relative_file($path) === null) {
                    return [
                        'ok' => false,
                        'message' => "Media {$mediaid} references a missing or unsafe migration_path.",
                    ];
                }
                $checked++;
            }
        }

        foreach ($files as $fileid => $descriptor) {
            if (!is_array($descriptor)) {
                return ['ok' => false, 'message' => "File {$fileid} descriptor is invalid."];
            }
            $path = (string) ($descriptor['migration_path'] ?? '');
            $resolved = $this->resolve_relative_file($path);
            if ($resolved === null) {
                return [
                    'ok' => false,
                    'message' => "File {$fileid} references a missing or unsafe migration_path.",
                ];
            }
            $expectedsize = (int) ($descriptor['size'] ?? 0);
            $actualsize = filesize($resolved);
            if ($expectedsize > 0 && $actualsize !== false && (int) $actualsize !== $expectedsize) {
                return [
                    'ok' => false,
                    'message' => "File {$fileid} size differs from the source descriptor.",
                ];
            }
            $checked++;
        }

        return ['ok' => true, 'asset_files_checked' => $checked];
    }

    /**
     * Validate page block references recursively and collect block counts.
     *
     * @return array Validation result.
     */
    private function validate_page_blocks(
        array $pages,
        array $media,
        array $files,
        array &$summary
    ): array {
        foreach ($pages as $pageid => $page) {
            $result = $this->validate_blocks(
                is_array($page['blocks'] ?? null) ? $page['blocks'] : [],
                (string) $pageid,
                $media,
                $files,
                $summary
            );
            if (!$result['ok']) {
                return $result;
            }
        }
        return ['ok' => true];
    }

    /**
     * Recursive block validator.
     */
    private function validate_blocks(
        array $blocks,
        string $pageid,
        array $media,
        array $files,
        array &$summary
    ): array {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                return ['ok' => false, 'message' => "Page {$pageid} contains a non-object block."];
            }
            $type = (string) ($block['type'] ?? '');
            if (!array_key_exists($type, $summary)) {
                return ['ok' => false, 'message' => "Page {$pageid} contains unknown block type {$type}."];
            }
            $summary[$type]++;

            if ($type === 'media') {
                $sourceid = (string) ($block['source_id'] ?? '');
                if ($sourceid === '' || !isset($media[$sourceid])) {
                    return ['ok' => false, 'message' => "Page {$pageid} references missing media {$sourceid}."];
                }
            } else if ($type === 'file_list') {
                if (!is_array($block['files'] ?? null)) {
                    return ['ok' => false, 'message' => "Page {$pageid} contains an invalid file_list."];
                }
                foreach ($block['files'] as $fileitem) {
                    $sourceid = is_array($fileitem)
                        ? (string) ($fileitem['source_id'] ?? '')
                        : '';
                    if ($sourceid === '' || !isset($files[$sourceid])) {
                        return [
                            'ok' => false,
                            'message' => "Page {$pageid} references missing file {$sourceid}.",
                        ];
                    }
                }
            } else if ($type === 'section') {
                $result = $this->validate_blocks(
                    is_array($block['blocks'] ?? null) ? $block['blocks'] : [],
                    $pageid,
                    $media,
                    $files,
                    $summary
                );
                if (!$result['ok']) {
                    return $result;
                }
            } else if ($type === 'table') {
                if (!is_array($block['rows'] ?? null)) {
                    return ['ok' => false, 'message' => "Page {$pageid} contains an invalid table."];
                }
                foreach ($block['rows'] as $row) {
                    if (!is_array($row)) {
                        return ['ok' => false, 'message' => "Page {$pageid} contains an invalid table row."];
                    }
                    foreach ($row as $cell) {
                        $result = $this->validate_blocks(
                            is_array($cell) ? $cell : [],
                            $pageid,
                            $media,
                            $files,
                            $summary
                        );
                        if (!$result['ok']) {
                            return $result;
                        }
                    }
                }
            }
        }
        return ['ok' => true];
    }

    /**
     * Build the deterministic Moodle Book navigation preview.
     *
     * Moodle Book has a single subchapter flag rather than arbitrary tree depth.
     * For the current supported ILIAS shape, each ILIAS chapter becomes a Book
     * top-level chapter marker and its pages become Book subchapters. This keeps
     * the source chapter titles and page titles visible in the Book TOC.
     */
    private function build_book_preview(array $nodes, array $pages): array {
        $entries = [];
        $pagenum = 0;

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            if ($type === 'root') {
                continue;
            }

            $pagenum++;
            if ($type === 'chapter') {
                $entries[] = [
                    'pagenum' => $pagenum,
                    'source_type' => 'chapter',
                    'source_id' => (string) ($node['source_id'] ?? ''),
                    'parent_source_id' => (string) ($node['parent_source_id'] ?? ''),
                    'title' => (string) ($node['title'] ?? ''),
                    'subchapter' => 0,
                    'content_policy' => 'chapter_marker',
                    'block_count' => 0,
                    'block_types' => [],
                ];
                continue;
            }

            $pageid = (string) ($node['source_id'] ?? '');
            $page = is_array($pages[$pageid] ?? null) ? $pages[$pageid] : [];
            $blocks = is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
            $entries[] = [
                'pagenum' => $pagenum,
                'source_type' => 'page',
                'source_id' => $pageid,
                'parent_source_id' => (string) ($node['parent_source_id'] ?? ''),
                'title' => (string) ($node['title'] ?? ''),
                'subchapter' => 1,
                'content_policy' => 'render_blocks_to_html',
                'block_count' => count($blocks),
                'block_types' => array_values(array_map(
                    static fn(array $block): string => (string) ($block['type'] ?? ''),
                    array_filter($blocks, 'is_array')
                )),
            ];
        }

        return [
            'mapping_policy' => 'ilias_chapter_marker_plus_page_subchapters',
            'entries' => $entries,
            'entry_count' => count($entries),
        ];
    }

    /**
     * Warn when migration.json counts differ from structure.json counts.
     */
    private function compare_declared_count(
        array $operation,
        string $field,
        int $actual,
        array &$warnings
    ): void {
        $declared = (int) ($operation[$field] ?? 0);
        if ($declared !== $actual) {
            $warnings[] = [
                'code' => 'LEARNING_MODULE_DECLARED_COUNT_MISMATCH',
                'source_ref_id' => (string) ($operation['source_ref_id'] ?? ''),
                'field' => $field,
                'declared' => $declared,
                'actual' => $actual,
                'message' => 'migration.json and structure.json counts differ; review the package before apply.',
            ];
        }
    }

    /**
     * Resolve a package-relative file and keep it inside the package root.
     */
    private function resolve_relative_file(string $relative): ?string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_starts_with($relative, '/')) {
            return null;
        }
        if (preg_match('/^[A-Za-z]:\//', $relative) === 1) {
            return null;
        }

        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }

        $candidate = $this->packageroot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        $resolved = realpath($candidate);
        if ($resolved === false
                || !str_starts_with($resolved, $this->packageroot . DIRECTORY_SEPARATOR)
                || !is_file($resolved)) {
            return null;
        }
        return $resolved;
    }

    /**
     * Mark one Learning Module operation as blocked.
     */
    private function block(array &$operation, string $code, string $message): void {
        $requested = (string) ($operation['action'] ?? '');
        if (!isset($operation['requested_action'])) {
            $operation['requested_action'] = $requested;
        }
        $operation['action'] = 'BLOCKED';
        $operation['reason'] = $code;
        $operation['validation_error'] = $message;
    }
}

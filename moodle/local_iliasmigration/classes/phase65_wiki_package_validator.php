<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only validator for ILIAS Wiki -> Moodle mod_wiki Phase 6.5.3.
 *
 * No Moodle content write is performed. Apply intentionally stays disabled
 * until the real ILIAS 10.8 POC has confirmed export/link/media semantics.
 */
final class phase65_wiki_package_validator {
    /** @var string Canonical migration package root. */
    private string $packageroot;

    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        if ($root === false || !is_dir($root)) {
            throw new \coding_exception('Unable to resolve the migration package directory.');
        }
        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
    }

    /** Validate every Wiki operation without writing Moodle content. */
    public function validate(array $plan): array {
        global $DB;

        $wikimodule = $DB->get_record('modules', ['name' => 'wiki'], 'id,name,visible');
        $wikiavailable = $wikimodule && (int) $wikimodule->visible === 1;
        $plan['moodle']['wiki_available'] = $wikiavailable;

        $sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourse = (string) ($plan['course']['source_id'] ?? '');
        $targetcourseid = (int) ($plan['operations'][0]['target_id'] ?? 0);

        $checked = 0;
        $blocked = 0;
        $pagecount = 0;
        $assetcount = 0;
        $wikipagelinks = 0;
        $repositorylinks = 0;

        foreach ($plan['operations'] as &$operation) {
            if ((string) ($operation['kind'] ?? '') !== 'wiki') {
                continue;
            }

            $checked++;
            $sourceref = (string) ($operation['source_ref_id'] ?? '');
            $mapping = $this->resolve_action(
                $sourceinstance,
                $sourcecourse,
                $sourceref,
                $targetcourseid
            );

            $operation['phase'] = '6.5.3';
            $operation['action'] = $wikiavailable ? $mapping['action'] : 'BLOCKED';
            $operation['target_id'] = $mapping['target_id'];
            $operation['moodle_module'] = 'wiki';
            $operation['migration_structure_path'] = 'wikis/' . $sourceref . '/structure.json';
            if (!empty($mapping['legacy_mapping'])) {
                $operation['legacy_sourceinstance_mapping'] = true;
            }

            if (!$wikiavailable) {
                $this->block(
                    $operation,
                    'WIKI_MODULE_DISABLED',
                    'Moodle mod_wiki is missing or disabled.'
                );
                $blocked++;
                continue;
            }

            if (!in_array((string) $operation['action'], ['CREATE', 'UPDATE'], true)) {
                $this->block(
                    $operation,
                    'WIKI_MAPPING_INVALID',
                    'The persistent Wiki mapping is stale or points to the wrong Moodle object.'
                );
                $blocked++;
                continue;
            }

            $summary = $this->validate_wiki($operation);
            $pagecount += (int) ($summary['pages'] ?? 0);
            $assetcount += (int) ($summary['assets'] ?? 0);
            $wikipagelinks += (int) ($summary['wiki_page_links'] ?? 0);
            $repositorylinks += (int) ($summary['repository_links'] ?? 0);
            if (($operation['action'] ?? '') === 'BLOCKED') {
                $blocked++;
            }
        }
        unset($operation);

        if ($checked === 0) {
            $plan['warnings'][] = [
                'code' => 'NO_WIKI_FOUND',
                'message' => 'No ILIAS Wiki operation was found in this migration package.',
            ];
        }
        if (!$wikiavailable) {
            $plan['warnings'][] = [
                'code' => 'WIKI_MODULE_DISABLED',
                'message' => 'Moodle mod_wiki is missing or disabled; Wiki migration cannot run.',
            ];
        }

        $previousready = $this->previous_packages_ready($plan);
        $ready = $checked > 0
            && $blocked === 0
            && $wikiavailable
            && $previousready;

        $plan['phase65_wiki_package'] = [
            'root' => $this->packageroot,
            'checked_wikis' => $checked,
            'blocked_wikis' => $blocked,
            'page_count' => $pagecount,
            'asset_count' => $assetcount,
            'wiki_page_link_count' => $wikipagelinks,
            'repository_link_count' => $repositorylinks,
            'wiki_available' => $wikiavailable,
            'previous_packages_ready' => $previousready,
            'history_policy' => 'current_pages_only',
            'ready' => $ready,
            'apply_implemented' => false,
            'apply_ready' => false,
        ];

        if ($checked > 0) {
            $plan['warnings'][] = [
                'code' => 'WIKI_APPLY_NOT_IMPLEMENTED',
                'message' => 'Wiki dry-run is available, but CREATE/UPDATE remains disabled until validation on the real ILIAS 10.8 POC.',
            ];
        }

        return $plan;
    }

    /** Validate one normalized Wiki structure. */
    private function validate_wiki(array &$operation): array {
        $relative = (string) ($operation['migration_structure_path'] ?? '');
        $structurefile = $this->resolve_relative_file($relative);
        if ($structurefile === null) {
            $this->block(
                $operation,
                'WIKI_STRUCTURE_MISSING',
                'The normalized Wiki structure.json is missing from the package.'
            );
            return $this->empty_summary();
        }

        $structure = $this->read_json($structurefile, $operation);
        if ($structure === null) {
            return $this->empty_summary();
        }

        if ((string) ($structure['schema_version'] ?? '') !== '1.0') {
            $this->block(
                $operation,
                'WIKI_SCHEMA_UNSUPPORTED',
                'Wiki structure.json must use schema_version 1.0.'
            );
            return $this->empty_summary();
        }

        $source = is_array($structure['source'] ?? null) ? $structure['source'] : [];
        if ((string) ($source['lms'] ?? '') !== 'ILIAS'
                || (string) ($source['ref_id'] ?? '') !== (string) ($operation['source_ref_id'] ?? '')) {
            $this->block(
                $operation,
                'WIKI_SOURCE_MISMATCH',
                'Wiki source identity does not match the planned ILIAS object.'
            );
            return $this->empty_summary();
        }

        $history = is_array($structure['history'] ?? null) ? $structure['history'] : [];
        if ((string) ($history['migration_policy'] ?? '') !== 'current_pages_only'
                || !empty($history['source_export_contains_history'])
                || !empty($history['authors_migrated'])) {
            $this->block(
                $operation,
                'WIKI_HISTORY_POLICY_INVALID',
                'Wiki history/authorship policy must explicitly remain current_pages_only for Phase 6.5.3.'
            );
            return $this->empty_summary();
        }

        $unsupported = $structure['unsupported_components'] ?? null;
        if (!is_array($unsupported) || $unsupported) {
            $this->block(
                $operation,
                'WIKI_UNSUPPORTED_COMPONENTS',
                'The Wiki contains unsupported Page Editor components.'
            );
            return $this->empty_summary();
        }

        $pages = is_array($structure['pages'] ?? null) ? $structure['pages'] : [];
        if (!$pages) {
            $this->block($operation, 'WIKI_EMPTY', 'The normalized Wiki contains no pages.');
            return $this->empty_summary();
        }

        $pageids = [];
        $pagetitles = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                $this->block($operation, 'WIKI_PAGE_INVALID', 'At least one Wiki page is invalid.');
                return $this->empty_summary();
            }

            $pageid = trim((string) ($page['source_id'] ?? ''));
            $title = trim((string) ($page['title'] ?? ''));
            if ($pageid === '' || $title === '' || isset($pageids[$pageid]) || isset($pagetitles[$title])) {
                $this->block(
                    $operation,
                    'WIKI_PAGE_IDENTITY_INVALID',
                    'Wiki page ids/titles must be present and unique.'
                );
                return $this->empty_summary();
            }
            $pageids[$pageid] = true;
            $pagetitles[$title] = true;

            $content = is_array($page['content'] ?? null) ? $page['content'] : [];
            if ((string) ($content['status'] ?? '') !== 'ok') {
                $this->block(
                    $operation,
                    'WIKI_PAGE_CONTENT_MISSING',
                    'At least one Wiki page has no usable current COPage content.'
                );
                return $this->empty_summary();
            }
            $pageunsupported = $content['unsupported_components'] ?? [];
            if (!is_array($pageunsupported) || $pageunsupported) {
                $this->block(
                    $operation,
                    'WIKI_PAGE_CONTENT_UNSUPPORTED',
                    'At least one Wiki page contains an unsupported Page Editor component.'
                );
                return $this->empty_summary();
            }
        }

        $startpage = is_array($structure['start_page'] ?? null) ? $structure['start_page'] : [];
        $startid = (string) ($startpage['source_id'] ?? '');
        $starttitle = (string) ($startpage['title'] ?? '');
        if ($startid === '' || $starttitle === '' || !isset($pageids[$startid]) || !isset($pagetitles[$starttitle])) {
            $this->block(
                $operation,
                'WIKI_START_PAGE_INVALID',
                'The ILIAS Wiki start page does not resolve to an exported page.'
            );
            return $this->empty_summary();
        }

        $links = is_array($structure['internal_links'] ?? null)
            ? $structure['internal_links']
            : [];
        $wikipagelinks = 0;
        $repositorylinks = 0;
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            if ((string) ($link['scope'] ?? '') === 'wiki_page') {
                $wikipagelinks++;
                $targetid = (string) ($link['source_id'] ?? '');
                if ($targetid === '' || !isset($pageids[$targetid])) {
                    $this->block(
                        $operation,
                        'WIKI_INTERNAL_PAGE_LINK_MISSING',
                        'An internal Wiki page link targets a page absent from the export.'
                    );
                    return $this->empty_summary();
                }
            } else {
                $repositorylinks++;
            }
        }

        $assetpaths = $this->collect_asset_paths($structure);
        foreach ($assetpaths as $path) {
            if ($this->resolve_relative_file($path) === null) {
                $this->block(
                    $operation,
                    'WIKI_ASSET_MISSING',
                    'A normalized Wiki asset is missing from the migration package: ' . $path
                );
                return $this->empty_summary();
            }
        }

        $render = (new phase65_wiki_renderer())->render($structure);
        $renderedpages = is_array($render['pages'] ?? null) ? $render['pages'] : [];
        if (count($renderedpages) !== count($pages)) {
            $this->block(
                $operation,
                'WIKI_RENDER_PAGE_COUNT_MISMATCH',
                'Rendered Wiki page count differs from the normalized structure.'
            );
            return $this->empty_summary();
        }

        foreach ($renderedpages as $renderedpage) {
            if (!is_array($renderedpage)
                    || trim((string) ($renderedpage['html'] ?? '')) === '') {
                $this->block(
                    $operation,
                    'WIKI_RENDER_EMPTY',
                    'At least one Wiki page rendered to empty HTML.'
                );
                return $this->empty_summary();
            }
        }

        $operation['wiki_validation'] = [
            'status' => 'OK',
            'code' => 'WIKI_READY',
            'schema_version' => '1.0',
            'page_count' => count($pages),
            'asset_count' => count($assetpaths),
            'wiki_page_link_count' => $wikipagelinks,
            'repository_link_count' => $repositorylinks,
            'start_page_source_id' => $startid,
            'start_page_title' => $starttitle,
            'history_policy' => 'current_pages_only',
            'authors_migrated' => false,
            'fingerprint_sha256' => (string) ($render['fingerprint_sha256'] ?? ''),
            'pages' => array_values(array_map(
                static function(array $page): array {
                    return [
                        'source_id' => (string) ($page['source_id'] ?? ''),
                        'title' => (string) ($page['title'] ?? ''),
                        'html_bytes' => (int) ($page['html_bytes'] ?? 0),
                        'html_sha256' => (string) ($page['html_sha256'] ?? ''),
                        'asset_count' => (int) ($page['asset_count'] ?? 0),
                        'internal_link_count' => (int) ($page['internal_link_count'] ?? 0),
                    ];
                },
                $renderedpages
            )),
        ];

        return [
            'pages' => count($pages),
            'assets' => count($assetpaths),
            'wiki_page_links' => $wikipagelinks,
            'repository_links' => $repositorylinks,
        ];
    }

    /** Earlier phases must already be stable before a Wiki can be applied. */
    private function previous_packages_ready(array $plan): bool {
        foreach ([
            'phase3_package',
            'phase4_package',
            'phase5_package',
            'phase6_package',
            'phase6_prerequisites',
        ] as $key) {
            if (isset($plan[$key]) && empty($plan[$key]['ready'])) {
                return false;
            }
        }
        return true;
    }

    /** Collect every normalized media/file package path. */
    private function collect_asset_paths(array $structure): array {
        $paths = [];
        $media = is_array($structure['media'] ?? null) ? $structure['media'] : [];
        foreach ($media as $mediaobject) {
            if (!is_array($mediaobject)) {
                continue;
            }
            foreach ((array) ($mediaobject['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $path = trim((string) ($item['migration_path'] ?? ''));
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        }

        $files = is_array($structure['files'] ?? null) ? $structure['files'] : [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $path = trim((string) ($file['migration_path'] ?? ''));
            if ($path !== '') {
                $paths[] = $path;
            }
        }
        return array_values(array_unique($paths));
    }

    /** Resolve Wiki CREATE/UPDATE from the persistent mapping table. */
    private function resolve_action(
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
            'targettype' => 'wiki',
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
            return ['action' => 'CREATE', 'target_id' => null, 'legacy_mapping' => false];
        }

        $cmid = (int) ($mapping->targetid ?? 0);
        if ($cmid <= 0) {
            return ['action' => 'ERROR_STALE_MAPPING', 'target_id' => null, 'legacy_mapping' => $legacy];
        }

        $record = $DB->get_record_sql(
            'SELECT cm.id, cm.course, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = ?',
            [$cmid]
        );
        if (!$record) {
            return ['action' => 'ERROR_STALE_MAPPING', 'target_id' => $cmid, 'legacy_mapping' => $legacy];
        }
        if ((string) $record->modulename !== 'wiki'
                || ($targetcourseid > 0 && (int) $record->course !== $targetcourseid)) {
            return ['action' => 'ERROR_MAPPING_TYPE', 'target_id' => $cmid, 'legacy_mapping' => $legacy];
        }

        return ['action' => 'UPDATE', 'target_id' => $cmid, 'legacy_mapping' => $legacy];
    }

    private function empty_summary(): array {
        return ['pages' => 0, 'assets' => 0, 'wiki_page_links' => 0, 'repository_links' => 0];
    }

    /** Resolve one package-relative file while preventing path traversal. */
    private function resolve_relative_file(string $relative): ?string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../')) {
            return null;
        }

        $candidate = $this->packageroot . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            return null;
        }
        if (!str_starts_with($real, $this->packageroot . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $real;
    }

    private function read_json(string $path, array &$operation): ?array {
        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->block($operation, 'WIKI_JSON_READ_FAILED', 'Unable to read Wiki structure.json.');
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->block($operation, 'WIKI_JSON_INVALID', 'Wiki structure.json is not valid JSON.');
            return null;
        }
        return $decoded;
    }

    private function block(array &$operation, string $code, string $message): void {
        $operation['action'] = 'BLOCKED';
        $operation['reason'] = $code;
        $operation['wiki_validation'] = [
            'status' => 'BLOCKED',
            'code' => $code,
            'message' => $message,
        ];
    }
}

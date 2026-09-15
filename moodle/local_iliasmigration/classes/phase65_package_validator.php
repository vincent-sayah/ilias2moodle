<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Validate normalized Content Page packages and render deterministic mod_page HTML.
 *
 * This class performs no Moodle writes.
 */
final class phase65_package_validator {
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
     * Validate every Content Page operation in the Phase 6.5 dry-run plan.
     *
     * @param array $plan Phase 6.5 plan.
     * @return array Annotated plan.
     */
    public function validate(array $plan): array {
        $checked = 0;
        $blocked = 0;
        $rewritten = 0;
        $preserved = 0;
        $assets = 0;

        $sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourse = (string) ($plan['course']['source_id'] ?? '');
        $targetcourseid = (int) ($plan['operations'][0]['target_id'] ?? 0);

        foreach ($plan['operations'] as &$operation) {
            if ((string) ($operation['kind'] ?? '') !== 'content_page') {
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
            $summary = $this->validate_page(
                $operation,
                $sourceinstance,
                $sourcecourse,
                $targetcourseid,
                $plan['warnings']
            );
            $rewritten += (int) ($summary['rewritten'] ?? 0);
            $preserved += (int) ($summary['preserved'] ?? 0);
            $assets += (int) ($summary['assets'] ?? 0);
            if (($operation['action'] ?? '') === 'BLOCKED') {
                $blocked++;
            }
        }
        unset($operation);

        if ($checked === 0) {
            $plan['warnings'][] = [
                'code' => 'NO_CONTENT_PAGE_FOUND',
                'message' => 'No ILIAS Content Page CREATE/UPDATE operation was found in this migration package.',
            ];
        }

        $phase3ready = !isset($plan['phase3_package']) || !empty($plan['phase3_package']['ready']);
        $phase4ready = !isset($plan['phase4_package']) || !empty($plan['phase4_package']['ready']);
        $phase5ready = !isset($plan['phase5_package']) || !empty($plan['phase5_package']['ready']);
        $phase6ready = !isset($plan['phase6_package']) || !empty($plan['phase6_package']['ready']);
        $prerequisitesready = !empty($plan['phase65_prerequisites']['ready']);
        $packagechecksready = $checked > 0 && $blocked === 0;

        $plan['phase65_package'] = [
            'root' => $this->packageroot,
            'checked_pages' => $checked,
            'blocked_pages' => $blocked,
            'asset_count' => $assets,
            'rewritten_internal_links' => $rewritten,
            'preserved_internal_links' => $preserved,
            'previous_packages_ready' => $phase3ready && $phase4ready && $phase5ready && $phase6ready,
            'prerequisites_ready' => $prerequisitesready,
            'package_checks_ready' => $packagechecksready,
            'ready' => $packagechecksready
                && $prerequisitesready
                && $phase3ready
                && $phase4ready
                && $phase5ready
                && $phase6ready,
            'apply_implemented' => false,
            'apply_ready' => false,
        ];

        if (!empty($plan['phase65_package']['ready'])) {
            $plan['warnings'][] = [
                'code' => 'PHASE65_APPLY_NOT_IMPLEMENTED',
                'message' => 'The Content Page package is valid for dry-run; mod_page writes remain intentionally disabled until the real preview is approved.',
            ];
        }

        return $plan;
    }

    /** Validate and render one Content Page operation. */
    private function validate_page(
        array &$operation,
        string $sourceinstance,
        string $sourcecourse,
        int $targetcourseid,
        array &$warnings
    ): array {
        $relative = (string) ($operation['migration_structure_path'] ?? '');
        $structurefile = $this->resolve_relative_file($relative);
        if ($structurefile === null) {
            $this->block(
                $operation,
                'CONTENT_PAGE_STRUCTURE_MISSING',
                'The normalized Content Page structure.json is missing from the package.'
            );
            return ['rewritten' => 0, 'preserved' => 0, 'assets' => 0];
        }

        $structure = $this->read_json($structurefile, $operation);
        if ($structure === null) {
            return ['rewritten' => 0, 'preserved' => 0, 'assets' => 0];
        }

        if ((string) ($structure['schema_version'] ?? '') !== '1.0') {
            $this->block(
                $operation,
                'CONTENT_PAGE_SCHEMA_UNSUPPORTED',
                'Content Page structure.json must use schema_version 1.0.'
            );
            return ['rewritten' => 0, 'preserved' => 0, 'assets' => 0];
        }

        $source = is_array($structure['source'] ?? null) ? $structure['source'] : [];
        if ((string) ($source['lms'] ?? '') !== 'ILIAS'
                || (string) ($source['ref_id'] ?? '') !== (string) ($operation['source_ref_id'] ?? '')) {
            $this->block(
                $operation,
                'CONTENT_PAGE_SOURCE_MISMATCH',
                'Content Page source identity does not match the planned ILIAS object.'
            );
            return ['rewritten' => 0, 'preserved' => 0, 'assets' => 0];
        }

        $unsupported = $structure['unsupported_components'] ?? null;
        if (!is_array($unsupported) || $unsupported) {
            $this->block(
                $operation,
                'CONTENT_PAGE_UNSUPPORTED_COMPONENTS',
                'The Content Page still contains unsupported Page Editor components.'
            );
            return ['rewritten' => 0, 'preserved' => 0, 'assets' => 0];
        }

        $assetpaths = $this->collect_asset_paths($structure);
        foreach ($assetpaths as $path) {
            if ($this->resolve_relative_file($path) === null) {
                $this->block(
                    $operation,
                    'CONTENT_PAGE_ASSET_MISSING',
                    'A normalized Content Page asset is missing from the migration package: ' . $path
                );
                return ['rewritten' => 0, 'preserved' => 0, 'assets' => count($assetpaths)];
            }
        }

        $expectedassets = (int) ($operation['migration_media_file_count'] ?? 0)
            + (int) ($operation['migration_embedded_file_count'] ?? 0);
        if ($expectedassets !== count($assetpaths)) {
            $this->block(
                $operation,
                'CONTENT_PAGE_ASSET_COUNT_MISMATCH',
                'Content Page asset counts in migration.json and structure.json are inconsistent.'
            );
            return ['rewritten' => 0, 'preserved' => 0, 'assets' => count($assetpaths)];
        }

        $renderer = new phase65_content_renderer();
        $result = $renderer->render(
            $structure,
            function(array $link) use (
                $sourceinstance,
                $sourcecourse,
                $targetcourseid
            ): array {
                return $this->resolve_internal_link(
                    $link,
                    $sourceinstance,
                    $sourcecourse,
                    $targetcourseid
                );
            }
        );

        $html = (string) ($result['html'] ?? '');
        if (trim($html) === '') {
            $this->block(
                $operation,
                'CONTENT_PAGE_RENDER_EMPTY',
                'The normalized Content Page produced empty Moodle Page HTML.'
            );
            return ['rewritten' => 0, 'preserved' => 0, 'assets' => count($assetpaths)];
        }

        $resolutions = is_array($result['internal_link_resolutions'] ?? null)
            ? $result['internal_link_resolutions']
            : [];
        $rewritten = 0;
        $preserved = 0;
        foreach ($resolutions as $resolution) {
            if (!is_array($resolution)) {
                continue;
            }
            if ((string) ($resolution['status'] ?? '') === 'REWRITTEN') {
                $rewritten++;
            } else {
                $preserved++;
                $warnings[] = [
                    'code' => 'CONTENT_PAGE_INTERNAL_LINK_PRESERVED',
                    'source_ref_id' => (string) ($operation['source_ref_id'] ?? ''),
                    'target_ref_id' => (string) ($resolution['source_ref_id'] ?? ''),
                    'reason' => (string) ($resolution['reason'] ?? 'TARGET_NOT_MIGRATED'),
                    'message' => 'An ILIAS internal link inside Content Page could not be uniquely rewritten and remains on the source fallback URL.',
                ];
            }
        }

        $operation['content_page_validation'] = [
            'status' => 'OK',
            'code' => 'CONTENT_PAGE_READY',
            'schema_version' => (string) ($structure['schema_version'] ?? ''),
            'block_types' => array_values(array_map(
                static fn($block): string => is_array($block) ? (string) ($block['type'] ?? '') : '',
                is_array($structure['blocks'] ?? null) ? $structure['blocks'] : []
            )),
            'asset_count' => count($assetpaths),
            'html_bytes' => strlen($html),
            'html_sha256' => hash('sha256', $html),
            'html_excerpt' => substr($html, 0, 600),
            'internal_link_resolutions' => $resolutions,
            'rewritten_internal_links' => $rewritten,
            'preserved_internal_links' => $preserved,
        ];

        return [
            'rewritten' => $rewritten,
            'preserved' => $preserved,
            'assets' => count($assetpaths),
        ];
    }

    /** Collect every normalized media/file migration path from structure.json. */
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

    /** Resolve one ILIAS internal repository link to a unique existing Moodle target. */
    private function resolve_internal_link(
        array $link,
        string $sourceinstance,
        string $sourcecourse,
        int $targetcourseid
    ): array {
        global $DB;

        $targettype = trim((string) ($link['target_type'] ?? ''));
        $sourceref = trim((string) ($link['source_ref_id'] ?? ''));
        $fallback = $this->source_fallback_url($sourceinstance, $targettype, $sourceref);

        if ($sourceref === '') {
            return [
                'status' => 'PRESERVED',
                'reason' => 'INVALID_TARGET',
                'candidate_count' => 0,
                'url' => $fallback,
                'fallback_url' => $fallback,
            ];
        }

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
        ];
        $mappings = $DB->get_records('local_iliasmigration_map', $conditions);
        if (!$mappings && $sourceinstance !== '') {
            $conditions['sourceinstance'] = '';
            $mappings = $DB->get_records('local_iliasmigration_map', $conditions);
        }

        $candidates = [];
        foreach ($mappings as $mapping) {
            $candidate = $this->mapping_candidate($mapping, $targetcourseid);
            if ($candidate !== null) {
                $candidates[$candidate['url']] = $candidate;
            }
        }
        $candidates = array_values($candidates);

        if (count($candidates) === 1) {
            $candidate = $candidates[0];
            return [
                'status' => 'REWRITTEN',
                'reason' => null,
                'candidate_count' => 1,
                'url' => (string) $candidate['url'],
                'fallback_url' => $fallback,
                'moodle_target_type' => (string) $candidate['target_type'],
                'moodle_target_id' => (int) $candidate['target_id'],
                'moodle_module' => (string) ($candidate['module'] ?? ''),
            ];
        }

        return [
            'status' => 'PRESERVED',
            'reason' => count($candidates) > 1 ? 'AMBIGUOUS_TARGET' : 'TARGET_NOT_MIGRATED',
            'candidate_count' => count($candidates),
            'url' => $fallback,
            'fallback_url' => $fallback,
        ];
    }

    /** Convert one mapping row to a verified navigation URL, or null when unsuitable. */
    private function mapping_candidate(object $mapping, int $targetcourseid): ?array {
        global $DB;

        $targettype = (string) ($mapping->targettype ?? '');
        $targetid = (int) ($mapping->targetid ?? 0);
        if ($targetid <= 0) {
            return null;
        }

        if ($targettype === 'course') {
            $course = $DB->get_record('course', ['id' => $targetid], 'id');
            if (!$course || ($targetcourseid > 0 && $targetid !== $targetcourseid)) {
                return null;
            }
            return [
                'url' => (new \moodle_url('/course/view.php', ['id' => $targetid]))->out(false),
                'target_type' => 'course',
                'target_id' => $targetid,
                'module' => '',
            ];
        }

        if ($targettype === 'section') {
            $section = $DB->get_record('course_sections', ['id' => $targetid], 'id,course,section');
            if (!$section || ($targetcourseid > 0 && (int) $section->course !== $targetcourseid)) {
                return null;
            }
            $url = (new \moodle_url('/course/view.php', ['id' => (int) $section->course]))->out(false)
                . '#section-' . (int) $section->section;
            return [
                'url' => $url,
                'target_type' => 'section',
                'target_id' => $targetid,
                'module' => '',
            ];
        }

        $allowed = [
            'subsection',
            'url',
            'file',
            'html_module',
            'scorm',
            'book',
            'qbank',
            'quiz',
            'page',
        ];
        if (!in_array($targettype, $allowed, true)) {
            return null;
        }

        $record = $DB->get_record_sql(
            'SELECT cm.id, cm.course, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = ?',
            [$targetid]
        );
        if (!$record || ($targetcourseid > 0 && (int) $record->course !== $targetcourseid)) {
            return null;
        }

        $module = (string) $record->modulename;
        if (!in_array($module, ['subsection', 'url', 'resource', 'scorm', 'book', 'qbank', 'quiz', 'page'], true)) {
            return null;
        }

        return [
            'url' => (new \moodle_url('/mod/' . $module . '/view.php', ['id' => $targetid]))->out(false),
            'target_type' => $targettype,
            'target_id' => $targetid,
            'module' => $module,
        ];
    }

    /** Build the source ILIAS fallback URL used when no unique Moodle target exists. */
    private function source_fallback_url(string $sourceinstance, string $type, string $refid): string {
        if ($sourceinstance === '' || $type === '' || $refid === '') {
            return '';
        }
        return rtrim($sourceinstance, '/') . '/goto.php?target=' . rawurlencode($type . '_' . $refid);
    }

    /** Resolve one safe package-relative file and reject traversal/symlink escape. */
    private function resolve_relative_file(string $relative): ?string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../')) {
            return null;
        }
        $candidate = realpath($this->packageroot . DIRECTORY_SEPARATOR . $relative);
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }
        $rootprefix = $this->packageroot . DIRECTORY_SEPARATOR;
        if (!str_starts_with($candidate, $rootprefix)) {
            return null;
        }
        return $candidate;
    }

    /** Read JSON safely and block the operation on malformed content. */
    private function read_json(string $path, array &$operation): ?array {
        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->block($operation, 'CONTENT_PAGE_STRUCTURE_UNREADABLE', 'Unable to read Content Page structure.json.');
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->block(
                $operation,
                'CONTENT_PAGE_STRUCTURE_INVALID_JSON',
                'Content Page structure.json is invalid JSON: ' . $exception->getMessage()
            );
            return null;
        }
        if (!is_array($decoded)) {
            $this->block($operation, 'CONTENT_PAGE_STRUCTURE_INVALID', 'Content Page structure.json must contain a JSON object.');
            return null;
        }
        return $decoded;
    }

    /** Mark one operation as blocked with an explicit validation reason. */
    private function block(array &$operation, string $code, string $message): void {
        $operation['action'] = 'BLOCKED';
        $operation['reason'] = $code;
        $operation['content_page_validation'] = [
            'status' => 'BLOCKED',
            'code' => $code,
            'message' => $message,
        ];
    }
}

<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only validator for ILIAS Glossary -> Moodle mod_glossary Phase 6.5 POC.
 *
 * This class intentionally performs no Moodle writes. Apply remains disabled
 * until the real CREATE/UPDATE path has been validated separately.
 */
final class phase65_glossary_package_validator {
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
     * Plan and validate every Glossary operation in an existing Phase 6.5 plan.
     *
     * @param array $plan Phase 6.5 plan after previous validators.
     * @return array Annotated dry-run plan.
     */
    public function validate(array $plan): array {
        global $DB;

        $glossarymodule = $DB->get_record('modules', ['name' => 'glossary'], 'id,name,visible');
        $glossaryavailable = $glossarymodule && (int) $glossarymodule->visible === 1;
        $plan['moodle']['glossary_available'] = $glossaryavailable;

        $sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourse = (string) ($plan['course']['source_id'] ?? '');
        $targetcourseid = (int) ($plan['operations'][0]['target_id'] ?? 0);

        $checked = 0;
        $blocked = 0;
        $termcount = 0;
        $assetcount = 0;

        foreach ($plan['operations'] as &$operation) {
            if ((string) ($operation['kind'] ?? '') !== 'glossary') {
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

            $operation['phase'] = '6.5';
            $operation['action'] = $glossaryavailable ? $mapping['action'] : 'BLOCKED';
            $operation['target_id'] = $mapping['target_id'];
            $operation['moodle_module'] = 'glossary';
            $operation['migration_structure_path'] = 'glossaries/' . $sourceref . '/structure.json';
            if (!empty($mapping['legacy_mapping'])) {
                $operation['legacy_sourceinstance_mapping'] = true;
            }

            if (!$glossaryavailable) {
                $this->block(
                    $operation,
                    'GLOSSARY_MODULE_DISABLED',
                    'Moodle mod_glossary is missing or disabled.'
                );
                $blocked++;
                continue;
            }

            if (!in_array((string) $operation['action'], ['CREATE', 'UPDATE'], true)) {
                $this->block(
                    $operation,
                    'GLOSSARY_MAPPING_INVALID',
                    'The persistent Glossary mapping is stale or points to the wrong Moodle object.'
                );
                $blocked++;
                continue;
            }

            $summary = $this->validate_glossary($operation);
            $termcount += (int) ($summary['terms'] ?? 0);
            $assetcount += (int) ($summary['assets'] ?? 0);
            if (($operation['action'] ?? '') === 'BLOCKED') {
                $blocked++;
            }
        }
        unset($operation);

        if ($checked === 0) {
            $plan['warnings'][] = [
                'code' => 'NO_GLOSSARY_FOUND',
                'message' => 'No ILIAS Glossary operation was found in this migration package.',
            ];
        }
        if (!$glossaryavailable) {
            $plan['warnings'][] = [
                'code' => 'GLOSSARY_MODULE_DISABLED',
                'message' => 'Moodle mod_glossary is missing or disabled; Glossary migration cannot run.',
            ];
        }

        $previousready = !isset($plan['phase65_package'])
            || !empty($plan['phase65_package']['ready']);
        $ready = $checked > 0
            && $blocked === 0
            && $glossaryavailable
            && $previousready;

        $plan['phase65_glossary_package'] = [
            'root' => $this->packageroot,
            'checked_glossaries' => $checked,
            'blocked_glossaries' => $blocked,
            'term_count' => $termcount,
            'asset_count' => $assetcount,
            'glossary_available' => $glossaryavailable,
            'previous_phase65_ready' => $previousready,
            'ready' => $ready,
            'apply_implemented' => false,
            'apply_ready' => false,
        ];

        // Explicit safety gate: while Glossary apply is not implemented, a
        // package containing a glossary must never be accepted by the existing
        // Content Page executor.
        if ($checked > 0) {
            $plan['phase65_package']['apply_ready'] = false;
            $plan['phase65_package']['apply_implemented'] = false;
            $plan['warnings'][] = [
                'code' => 'GLOSSARY_APPLY_NOT_IMPLEMENTED',
                'message' => 'Glossary dry-run is validated, but Phase 6.5 apply remains disabled until mod_glossary CREATE/UPDATE is implemented.',
            ];
        }

        return $plan;
    }

    /** Validate one normalized Glossary structure. */
    private function validate_glossary(array &$operation): array {
        $relative = (string) ($operation['migration_structure_path'] ?? '');
        $structurefile = $this->resolve_relative_file($relative);
        if ($structurefile === null) {
            $this->block(
                $operation,
                'GLOSSARY_STRUCTURE_MISSING',
                'The normalized Glossary structure.json is missing from the package.'
            );
            return ['terms' => 0, 'assets' => 0];
        }

        $structure = $this->read_json($structurefile, $operation);
        if ($structure === null) {
            return ['terms' => 0, 'assets' => 0];
        }

        if ((string) ($structure['schema_version'] ?? '') !== '1.0') {
            $this->block(
                $operation,
                'GLOSSARY_SCHEMA_UNSUPPORTED',
                'Glossary structure.json must use schema_version 1.0.'
            );
            return ['terms' => 0, 'assets' => 0];
        }

        $source = is_array($structure['source'] ?? null) ? $structure['source'] : [];
        if ((string) ($source['lms'] ?? '') !== 'ILIAS'
                || (string) ($source['ref_id'] ?? '') !== (string) ($operation['source_ref_id'] ?? '')) {
            $this->block(
                $operation,
                'GLOSSARY_SOURCE_MISMATCH',
                'Glossary source identity does not match the planned ILIAS object.'
            );
            return ['terms' => 0, 'assets' => 0];
        }

        $unsupported = $structure['unsupported_components'] ?? null;
        if (!is_array($unsupported) || $unsupported) {
            $this->block(
                $operation,
                'GLOSSARY_UNSUPPORTED_COMPONENTS',
                'The Glossary still contains unsupported Page Editor components.'
            );
            return ['terms' => 0, 'assets' => 0];
        }

        $taxonomy = is_array($structure['taxonomy'] ?? null) ? $structure['taxonomy'] : [];
        if (!empty($taxonomy['enabled']) || !empty($taxonomy['export_component_present'])) {
            $this->block(
                $operation,
                'GLOSSARY_TAXONOMY_NOT_VALIDATED',
                'Glossary taxonomy export is present but has not yet been validated on the POC.'
            );
            return ['terms' => 0, 'assets' => 0];
        }

        $terms = is_array($structure['terms'] ?? null) ? $structure['terms'] : [];
        if (!$terms) {
            $this->block(
                $operation,
                'GLOSSARY_EMPTY',
                'The normalized Glossary contains no terms.'
            );
            return ['terms' => 0, 'assets' => 0];
        }

        foreach ($terms as $term) {
            if (!is_array($term)
                    || trim((string) ($term['source_id'] ?? '')) === ''
                    || trim((string) ($term['term'] ?? '')) === '') {
                $this->block(
                    $operation,
                    'GLOSSARY_TERM_INVALID',
                    'At least one normalized Glossary term is missing its source id or concept text.'
                );
                return ['terms' => count($terms), 'assets' => 0];
            }
            $definition = is_array($term['definition'] ?? null) ? $term['definition'] : [];
            if ((string) ($definition['status'] ?? '') !== 'ok') {
                $this->block(
                    $operation,
                    'GLOSSARY_DEFINITION_MISSING',
                    'At least one Glossary term has no usable exported definition.'
                );
                return ['terms' => count($terms), 'assets' => 0];
            }
            $termunsupported = $definition['unsupported_components'] ?? [];
            if (!is_array($termunsupported) || $termunsupported) {
                $this->block(
                    $operation,
                    'GLOSSARY_DEFINITION_UNSUPPORTED',
                    'At least one Glossary definition contains an unsupported Page Editor component.'
                );
                return ['terms' => count($terms), 'assets' => 0];
            }
        }

        $assetpaths = $this->collect_asset_paths($structure);
        foreach ($assetpaths as $path) {
            if ($this->resolve_relative_file($path) === null) {
                $this->block(
                    $operation,
                    'GLOSSARY_ASSET_MISSING',
                    'A normalized Glossary asset is missing from the migration package: ' . $path
                );
                return ['terms' => count($terms), 'assets' => count($assetpaths)];
            }
        }

        $renderer = new phase65_glossary_renderer();
        $render = $renderer->render($structure);
        $renderedterms = is_array($render['terms'] ?? null) ? $render['terms'] : [];
        if (count($renderedterms) !== count($terms)) {
            $this->block(
                $operation,
                'GLOSSARY_RENDER_TERM_COUNT_MISMATCH',
                'Rendered Glossary term count differs from the normalized structure.'
            );
            return ['terms' => count($terms), 'assets' => count($assetpaths)];
        }

        foreach ($renderedterms as $renderedterm) {
            if (!is_array($renderedterm) || trim((string) ($renderedterm['html'] ?? '')) === '') {
                $this->block(
                    $operation,
                    'GLOSSARY_RENDER_EMPTY',
                    'At least one Glossary definition rendered to empty HTML.'
                );
                return ['terms' => count($terms), 'assets' => count($assetpaths)];
            }
        }

        $links = is_array($render['internal_link_resolutions'] ?? null)
            ? $render['internal_link_resolutions']
            : [];
        if ($links) {
            $this->block(
                $operation,
                'GLOSSARY_INTERNAL_LINKS_NOT_VALIDATED',
                'Internal ILIAS repository links inside Glossary definitions require a dedicated validated rewrite policy.'
            );
            return ['terms' => count($terms), 'assets' => count($assetpaths)];
        }

        $operation['glossary_validation'] = [
            'status' => 'OK',
            'code' => 'GLOSSARY_READY',
            'schema_version' => (string) ($structure['schema_version'] ?? ''),
            'term_count' => count($terms),
            'asset_count' => count($assetpaths),
            'media_count' => count(is_array($structure['media'] ?? null) ? $structure['media'] : []),
            'file_count' => count(is_array($structure['files'] ?? null) ? $structure['files'] : []),
            'taxonomy_enabled' => false,
            'taxonomy_export_present' => false,
            'fingerprint_sha256' => (string) ($render['fingerprint_sha256'] ?? ''),
            'terms' => array_values(array_map(
                static function(array $term): array {
                    return [
                        'source_id' => (string) ($term['source_id'] ?? ''),
                        'term' => (string) ($term['term'] ?? ''),
                        'language' => (string) ($term['language'] ?? ''),
                        'html_bytes' => (int) ($term['html_bytes'] ?? 0),
                        'html_sha256' => (string) ($term['html_sha256'] ?? ''),
                        'asset_count' => (int) ($term['asset_count'] ?? 0),
                    ];
                },
                $renderedterms
            )),
        ];

        return ['terms' => count($terms), 'assets' => count($assetpaths)];
    }

    /** Collect normalized media/file paths from structure.json. */
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

    /** Resolve CREATE/UPDATE from the persistent glossary mapping. */
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
            'targettype' => 'glossary',
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
        if ((string) $record->modulename !== 'glossary'
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

    /** Resolve a package-relative file while preventing path traversal. */
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
        $prefix = $this->packageroot . DIRECTORY_SEPARATOR;
        if (!str_starts_with($real, $prefix)) {
            return null;
        }
        return $real;
    }

    /** Read one JSON object or block the operation. */
    private function read_json(string $path, array &$operation): ?array {
        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->block($operation, 'GLOSSARY_JSON_READ_FAILED', 'Unable to read Glossary structure.json.');
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->block($operation, 'GLOSSARY_JSON_INVALID', 'Glossary structure.json is not valid JSON.');
            return null;
        }
        return $decoded;
    }

    /** Mark one operation blocked with a deterministic validation code. */
    private function block(array &$operation, string $code, string $message): void {
        $operation['action'] = 'BLOCKED';
        $operation['reason'] = $code;
        $operation['glossary_validation'] = [
            'status' => 'BLOCKED',
            'code' => $code,
            'message' => $message,
        ];
    }
}

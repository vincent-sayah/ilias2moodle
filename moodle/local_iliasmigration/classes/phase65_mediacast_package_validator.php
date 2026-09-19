<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only validator/planner for ILIAS MediaCast -> Moodle mod_data.
 *
 * Phase 6.5.6 maps one ILIAS MediaCast to one Moodle Database activity and
 * one MediaCast item to one Database record. Local MP4 files and external
 * URLs are validated from the normalized package before any write is allowed.
 */
final class phase65_mediacast_package_validator {
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
        $entries = 0;
        $localfiles = 0;
        $externalurls = 0;
        $previews = 0;

        foreach ($plan['operations'] as &$operation) {
            if ((string) ($operation['kind'] ?? '') !== 'mediacast') {
                continue;
            }

            $discovered++;
            $sourceref = (string) ($operation['source_ref_id'] ?? '');

            $operation['phase'] = '6.5.6';
            $operation['moodle_module'] = 'data';
            $operation['migration_structure_path'] =
                'mediacasts/' . $sourceref . '/structure.json';

            if ($this->resolve_relative_file(
                (string) $operation['migration_structure_path']
            ) === null) {
                $operation['action'] = 'DEFER';
                $operation['target_id'] = null;
                $operation['reason'] = 'MEDIACAST_NOT_IN_INCREMENTAL_PACKAGE';
                $operation['mediacast_validation'] = [
                    'status' => 'SKIPPED_INCREMENTAL',
                    'code' => 'MEDIACAST_NOT_IN_INCREMENTAL_PACKAGE',
                    'message' => 'MediaCast is referenced by the course Container but has no normalized structure in this targeted Phase 6.5.6 package.',
                ];
                $skipped++;
                continue;
            }

            $checked++;
            $mapping = $this->resolve_action($sourceref);
            $operation['action'] = $available ? $mapping['action'] : 'BLOCKED';
            $operation['target_id'] = $mapping['target_id'];

            if (!$available) {
                $this->block(
                    $operation,
                    'MEDIACAST_DATA_MODULE_DISABLED',
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
                    'MEDIACAST_MAPPING_INVALID',
                    'The persistent MediaCast mapping is stale or points to the wrong Moodle object.'
                );
                $blocked++;
                continue;
            }

            $parent = $this->validate_parent_target($operation);
            $operation['mediacast_parent_validation'] = $parent;
            if (empty($parent['ready'])) {
                $this->block(
                    $operation,
                    'MEDIACAST_PARENT_MAPPING_INVALID',
                    (string) ($parent['message'] ?? 'The MediaCast parent mapping is invalid.')
                );
                $blocked++;
                continue;
            }

            $summary = $this->validate_mediacast($operation);
            $entries += (int) ($summary['entries'] ?? 0);
            $localfiles += (int) ($summary['local_files'] ?? 0);
            $externalurls += (int) ($summary['external_urls'] ?? 0);
            $previews += (int) ($summary['previews'] ?? 0);

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
                'code' => 'NO_MEDIACAST_SELECTED',
                'message' => 'No normalized ILIAS MediaCast structure was selected in this incremental Phase 6.5.6 package.',
            ];
        }

        if ($skipped > 0) {
            $plan['warnings'][] = [
                'code' => 'MEDIACAST_INCREMENTAL_OBJECTS_SKIPPED',
                'count' => $skipped,
                'message' => 'MediaCast objects referenced by the course Container but absent from the targeted normalized package were deferred without blocking selected MediaCasts.',
            ];
        }

        $ready = $checked > 0
            && $blocked === 0
            && $available
            && $this->targetcourseid > 0;

        $plan['phase65_mediacast_package'] = [
            'root' => $this->packageroot,
            'discovered_mediacasts' => $discovered,
            'checked_mediacasts' => $checked,
            'skipped_mediacasts' => $skipped,
            'blocked_mediacasts' => $blocked,
            'mediacast_create_count' => $creates,
            'mediacast_update_count' => $updates,
            'entry_count' => $entries,
            'local_file_count' => $localfiles,
            'external_url_count' => $externalurls,
            'preview_count' => $previews,
            'data_available' => $available,
            'target_activity' => 'mod_data',
            'record_policy' => 'ONE_RECORD_PER_MEDIACAST_ENTRY',
            'incremental_object_policy' => 'VALIDATE_ONLY_NORMALIZED_MEDIACASTS_IN_PACKAGE',
            'prerequisite_policy' => 'PERSISTED_TARGET_STATE',
            'ready' => $ready,
            'apply_implemented' => false,
            'apply_ready' => false,
        ];

        return $plan;
    }

    private function validate_mediacast(array &$operation): array {
        $relative = (string) ($operation['migration_structure_path'] ?? '');
        $structurefile = $this->resolve_relative_file($relative);

        if ($structurefile === null) {
            $this->block(
                $operation,
                'MEDIACAST_STRUCTURE_MISSING',
                'The normalized MediaCast structure.json is missing from the package.'
            );
            return $this->empty_summary();
        }

        $raw = file_get_contents($structurefile);
        if ($raw === false) {
            $this->block(
                $operation,
                'MEDIACAST_STRUCTURE_UNREADABLE',
                'The normalized MediaCast structure.json cannot be read.'
            );
            return $this->empty_summary();
        }

        try {
            $structure = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->block(
                $operation,
                'MEDIACAST_STRUCTURE_INVALID_JSON',
                'The normalized MediaCast structure.json is invalid JSON.'
            );
            return $this->empty_summary();
        }

        if (!is_array($structure)
                || (string) ($structure['schema_version'] ?? '') !== '1.0') {
            $this->block(
                $operation,
                'MEDIACAST_SCHEMA_UNSUPPORTED',
                'MediaCast structure.json must use schema_version 1.0.'
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
                'MEDIACAST_SOURCE_MISMATCH',
                'MediaCast source identity does not match the planned ILIAS object.'
            );
            return $this->empty_summary();
        }

        $strategy = is_array($structure['target_strategy'] ?? null)
            ? $structure['target_strategy']
            : [];

        if ((string) ($strategy['moodle_activity'] ?? '') !== 'mod_data'
                || empty($strategy['one_database_per_ilias_mediacast'])
                || empty($strategy['one_record_per_mediacast_entry'])) {
            $this->block(
                $operation,
                'MEDIACAST_TARGET_STRATEGY_INVALID',
                'MediaCast Phase 6.5.6 requires one mod_data activity and one record per source item.'
            );
            return $this->empty_summary();
        }

        $missingassets = $structure['missing_assets'] ?? null;
        if (!is_array($missingassets) || $missingassets) {
            $this->block(
                $operation,
                'MEDIACAST_SOURCE_ASSETS_INCOMPLETE',
                'At least one MediaCast local media asset is still missing from the normalized package.'
            );
            return $this->empty_summary();
        }

        $items = is_array($structure['entries'] ?? null)
            ? $structure['entries']
            : [];

        $ids = [];
        $positions = [];
        $localfiles = 0;
        $externalurls = 0;
        $previews = 0;

        foreach ($items as $entry) {
            if (!is_array($entry)) {
                $this->block(
                    $operation,
                    'MEDIACAST_ENTRY_INVALID',
                    'At least one MediaCast entry is invalid.'
                );
                return $this->empty_summary();
            }

            $entryid = trim((string) ($entry['source_id'] ?? ''));
            $position = (int) ($entry['position'] ?? 0);
            if ($entryid === '' || isset($ids[$entryid])) {
                $this->block(
                    $operation,
                    'MEDIACAST_ENTRY_IDENTITY_INVALID',
                    'MediaCast entry source ids must be present and unique.'
                );
                return $this->empty_summary();
            }
            if ($position <= 0 || isset($positions[$position])) {
                $this->block(
                    $operation,
                    'MEDIACAST_ENTRY_POSITION_INVALID',
                    'MediaCast entry positions must be positive and unique.'
                );
                return $this->empty_summary();
            }
            if (empty($entry['supported'])) {
                $this->block(
                    $operation,
                    'MEDIACAST_ENTRY_UNSUPPORTED',
                    'The selected MediaCast contains an unsupported entry.'
                );
                return $this->empty_summary();
            }

            $ids[$entryid] = true;
            $positions[$position] = true;

            $kind = (string) ($entry['source_kind'] ?? '');
            $location = trim((string) ($entry['location'] ?? ''));
            $locationtype = (string) ($entry['location_type'] ?? '');
            $format = (string) ($entry['format'] ?? '');

            if ($kind === 'local_file') {
                if ($locationtype !== 'LocalFile' || $format !== 'video/mp4') {
                    $this->block(
                        $operation,
                        'MEDIACAST_LOCAL_MEDIA_TYPE_INVALID',
                        'Local MediaCast items must be LocalFile video/mp4 in this POC.'
                    );
                    return $this->empty_summary();
                }

                $path = trim((string) ($entry['migration_path'] ?? ''));
                $file = $this->resolve_relative_file($path);
                $size = (int) ($entry['migration_size'] ?? 0);
                $sha256 = strtolower(trim((string) ($entry['migration_sha256'] ?? '')));

                if ($file === null
                        || $size <= 0
                        || !preg_match('/^[0-9a-f]{64}$/', $sha256)) {
                    $this->block(
                        $operation,
                        'MEDIACAST_LOCAL_MEDIA_PACKAGE_INVALID',
                        'A recovered MP4 is missing its validated file, size or SHA-256.'
                    );
                    return $this->empty_summary();
                }

                $actualsize = filesize($file);
                $actualsha256 = hash_file('sha256', $file);
                if ($actualsize === false
                        || $actualsha256 === false
                        || (int) $actualsize !== $size
                        || !hash_equals($sha256, strtolower($actualsha256))) {
                    $this->block(
                        $operation,
                        'MEDIACAST_LOCAL_MEDIA_INTEGRITY_ERROR',
                        'A recovered MP4 no longer matches its normalized size/SHA-256.'
                    );
                    return $this->empty_summary();
                }

                $localfiles++;
            } else if ($kind === 'external_url') {
                if ($locationtype !== 'Reference'
                        || !filter_var($location, FILTER_VALIDATE_URL)) {
                    $this->block(
                        $operation,
                        'MEDIACAST_EXTERNAL_URL_INVALID',
                        'An external MediaCast item has an invalid reference URL.'
                    );
                    return $this->empty_summary();
                }
                $scheme = strtolower((string) parse_url($location, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true)) {
                    $this->block(
                        $operation,
                        'MEDIACAST_EXTERNAL_URL_SCHEME_INVALID',
                        'Only HTTP/HTTPS external MediaCast URLs are accepted.'
                    );
                    return $this->empty_summary();
                }
                $externalurls++;
            } else {
                $this->block(
                    $operation,
                    'MEDIACAST_SOURCE_KIND_INVALID',
                    'MediaCast entries must be local_file or external_url.'
                );
                return $this->empty_summary();
            }

            $entrypreviews = is_array($entry['preview_assets'] ?? null)
                ? $entry['preview_assets']
                : [];
            foreach ($entrypreviews as $preview) {
                if (!is_array($preview)) {
                    $this->block(
                        $operation,
                        'MEDIACAST_PREVIEW_INVALID',
                        'At least one MediaCast preview asset is invalid.'
                    );
                    return $this->empty_summary();
                }
                $previewpath = trim((string) ($preview['migration_path'] ?? ''));
                if ($previewpath === ''
                        || $this->resolve_relative_file($previewpath) === null) {
                    $this->block(
                        $operation,
                        'MEDIACAST_PREVIEW_MISSING',
                        'A normalized MediaCast preview asset is missing from the package.'
                    );
                    return $this->empty_summary();
                }
                $previews++;
            }
        }

        if ((int) ($structure['entry_count'] ?? -1) !== count($items)
                || (int) ($structure['local_file_count'] ?? -1) !== $localfiles
                || (int) ($structure['external_url_count'] ?? -1) !== $externalurls
                || (int) ($structure['unsupported_entry_count'] ?? -1) !== 0) {
            $this->block(
                $operation,
                'MEDIACAST_COUNT_MISMATCH',
                'MediaCast normalized counters do not match the validated entries.'
            );
            return $this->empty_summary();
        }

        $operation['mediacast_validation'] = [
            'status' => 'READY',
            'code' => 'MEDIACAST_READY',
            'schema_version' => '1.0',
            'entry_count' => count($items),
            'local_file_count' => $localfiles,
            'external_url_count' => $externalurls,
            'preview_count' => $previews,
            'moodle_activity' => 'mod_data',
            'record_policy' => 'ONE_RECORD_PER_MEDIACAST_ENTRY',
            'planned_fields' => [
                'source_entry_id',
                'title',
                'description',
                'source_type',
                'duration',
                'media_file',
                'external_url',
            ],
        ];

        return [
            'entries' => count($items),
            'local_files' => $localfiles,
            'external_urls' => $externalurls,
            'previews' => $previews,
        ];
    }

    private function validate_parent_target(array $operation): array {
        global $DB;

        $parentref = trim((string) ($operation['parent_source_ref_id'] ?? ''));
        if ($parentref === '') {
            return [
                'ready' => true,
                'kind' => 'course_root',
                'section_number' => 0,
                'target_id' => null,
            ];
        }

        $sectionmapping = $this->find_mapping($parentref, 'section');
        if ($sectionmapping) {
            $section = $DB->get_record(
                'course_sections',
                [
                    'id' => (int) $sectionmapping->targetid,
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
        }

        $subsectionmapping = $this->find_mapping($parentref, 'subsection');
        if ($subsectionmapping) {
            $cm = $DB->get_record(
                'course_modules',
                [
                    'id' => (int) $subsectionmapping->targetid,
                    'course' => $this->targetcourseid,
                ],
                'id,instance'
            );
            if ($cm) {
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
        }

        return [
            'ready' => false,
            'kind' => 'unresolved',
            'section_number' => null,
            'target_id' => null,
            'message' => 'No Moodle section/subsection mapping exists for the ILIAS MediaCast parent.',
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

        if (!$record) {
            return [
                'action' => 'ERROR_STALE_MAPPING',
                'target_id' => $cmid,
            ];
        }

        if ((string) $record->modulename !== 'data'
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

    private function find_mapping(string $sourceref, string $targettype): \stdClass|false {
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
        $operation['mediacast_validation'] = [
            'status' => 'BLOCKED',
            'code' => $code,
            'message' => $message,
        ];
    }

    private function empty_summary(): array {
        return [
            'entries' => 0,
            'local_files' => 0,
            'external_urls' => 0,
            'previews' => 0,
        ];
    }
}

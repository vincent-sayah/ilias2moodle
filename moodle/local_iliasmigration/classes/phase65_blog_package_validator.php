<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only validator/planner for ILIAS Blog -> Moodle mod_data.
 *
 * Phase 6.5.7 maps one ILIAS Blog to one Moodle Database activity and
 * one Blog posting to one Database record. Source author identifiers are
 * preserved as metadata only; Moodle user attribution remains a Phase 7 task.
 */
final class phase65_blog_package_validator {
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
        $postings = 0;
        $assets = 0;
        $keywords = 0;
        $sourceauthors = [];

        foreach ($plan['operations'] as &$operation) {
            if ((string) ($operation['kind'] ?? '') !== 'blog') {
                continue;
            }

            $discovered++;
            $sourceref = (string) ($operation['source_ref_id'] ?? '');

            $operation['phase'] = '6.5.7';
            $operation['moodle_module'] = 'data';
            $operation['migration_structure_path'] =
                'blogs/' . $sourceref . '/structure.json';

            if ($this->resolve_relative_file(
                (string) $operation['migration_structure_path']
            ) === null) {
                $operation['action'] = 'DEFER';
                $operation['target_id'] = null;
                $operation['reason'] = 'BLOG_NOT_IN_INCREMENTAL_PACKAGE';
                $operation['blog_validation'] = [
                    'status' => 'SKIPPED_INCREMENTAL',
                    'code' => 'BLOG_NOT_IN_INCREMENTAL_PACKAGE',
                    'message' => 'Blog is referenced by the course Container but has no normalized structure in this targeted Phase 6.5.7 package.',
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
                    'BLOG_DATA_MODULE_DISABLED',
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
                    'BLOG_MAPPING_INVALID',
                    'The persistent Blog mapping is stale or points to the wrong Moodle object.'
                );
                $blocked++;
                continue;
            }

            $parent = $this->validate_parent_target($operation);
            $operation['blog_parent_validation'] = $parent;
            if (empty($parent['ready'])) {
                $this->block(
                    $operation,
                    'BLOG_PARENT_MAPPING_INVALID',
                    (string) ($parent['message'] ?? 'The Blog parent mapping is invalid.')
                );
                $blocked++;
                continue;
            }

            $summary = $this->validate_blog($operation);
            $postings += (int) ($summary['postings'] ?? 0);
            $assets += (int) ($summary['assets'] ?? 0);
            $keywords += (int) ($summary['keywords'] ?? 0);
            foreach ((array) ($summary['source_authors'] ?? []) as $author) {
                $sourceauthors[(string) $author] = true;
            }

            if (($operation['action'] ?? '') === 'BLOCKED') {
                $blocked++;
                continue;
            }

            if ((string) $operation['action'] === 'CREATE') {
                $creates++;
            } else {
                $updates++;
            }
        }
        unset($operation);

        if ($checked === 0) {
            $plan['warnings'][] = [
                'code' => 'NO_BLOG_SELECTED',
                'message' => 'No normalized ILIAS Blog structure was selected in this incremental Phase 6.5.7 package.',
            ];
        }

        if ($skipped > 0) {
            $plan['warnings'][] = [
                'code' => 'BLOG_INCREMENTAL_OBJECTS_SKIPPED',
                'count' => $skipped,
                'message' => 'Blog objects referenced by the course Container but absent from the targeted normalized package were deferred without blocking selected Blogs.',
            ];
        }

        if ($keywords === 0 && $checked > 0) {
            $plan['warnings'][] = [
                'code' => 'BLOG_KEYWORDS_NOT_PRESENT_IN_POC',
                'message' => 'The selected real Blog export contains no posting KeywordN value; keyword parsing is implemented but not yet validated on real POC data.',
            ];
        }

        $ready = $checked > 0
            && $blocked === 0
            && $available
            && $this->targetcourseid > 0;

        $plan['phase65_blog_package'] = [
            'root' => $this->packageroot,
            'discovered_blogs' => $discovered,
            'checked_blogs' => $checked,
            'skipped_blogs' => $skipped,
            'blocked_blogs' => $blocked,
            'blog_create_count' => $creates,
            'blog_update_count' => $updates,
            'posting_count' => $postings,
            'asset_count' => $assets,
            'keyword_count' => $keywords,
            'source_author_count' => count($sourceauthors),
            'data_available' => $available,
            'target_activity' => 'mod_data',
            'record_policy' => 'ONE_RECORD_PER_BLOG_POSTING',
            'author_policy' => 'PRESERVE_SOURCE_IDENTIFIER_DEFER_MOODLE_USER_TO_PHASE7',
            'incremental_object_policy' => 'VALIDATE_ONLY_NORMALIZED_BLOGS_IN_PACKAGE',
            'prerequisite_policy' => 'PERSISTED_TARGET_STATE',
            'ready' => $ready,
            'apply_implemented' => false,
            'apply_ready' => false,
        ];

        return $plan;
    }

    private function validate_blog(array &$operation): array {
        $relative = (string) ($operation['migration_structure_path'] ?? '');
        $structurefile = $this->resolve_relative_file($relative);
        if ($structurefile === null) {
            $this->block(
                $operation,
                'BLOG_STRUCTURE_MISSING',
                'The normalized Blog structure.json is missing from the package.'
            );
            return $this->empty_summary();
        }

        $raw = file_get_contents($structurefile);
        if ($raw === false) {
            $this->block(
                $operation,
                'BLOG_STRUCTURE_UNREADABLE',
                'The normalized Blog structure.json cannot be read.'
            );
            return $this->empty_summary();
        }

        try {
            $structure = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->block(
                $operation,
                'BLOG_STRUCTURE_INVALID_JSON',
                'The normalized Blog structure.json is invalid JSON.'
            );
            return $this->empty_summary();
        }

        if (!is_array($structure)
                || (string) ($structure['schema_version'] ?? '') !== '1.0') {
            $this->block(
                $operation,
                'BLOG_SCHEMA_UNSUPPORTED',
                'Blog structure.json must use schema_version 1.0.'
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
                'BLOG_SOURCE_MISMATCH',
                'Blog source identity does not match the planned ILIAS object.'
            );
            return $this->empty_summary();
        }

        $strategy = is_array($structure['target_strategy'] ?? null)
            ? $structure['target_strategy']
            : [];
        if ((string) ($strategy['moodle_activity'] ?? '') !== 'mod_data'
                || (string) ($strategy['collection_policy'] ?? '')
                    !== 'one_database_per_ilias_blog'
                || (string) ($strategy['record_policy'] ?? '')
                    !== 'one_record_per_blog_posting') {
            $this->block(
                $operation,
                'BLOG_TARGET_STRATEGY_INVALID',
                'Blog Phase 6.5.7 requires one mod_data activity per Blog and one record per Blog posting.'
            );
            return $this->empty_summary();
        }

        $policy = is_array($structure['author_policy'] ?? null)
            ? $structure['author_policy']
            : [];
        if (empty($policy['source_identifier_preserved'])
                || (string) ($policy['moodle_user_assignment'] ?? '') !== 'phase7'
                || !array_key_exists('invent_author', $policy)
                || !empty($policy['invent_author'])) {
            $this->block(
                $operation,
                'BLOG_AUTHOR_POLICY_INVALID',
                'Blog author policy must preserve the source identifier and defer Moodle user assignment to Phase 7.'
            );
            return $this->empty_summary();
        }

        $unsupported = $structure['unsupported_components'] ?? null;
        if (!is_array($unsupported) || $unsupported) {
            $this->block(
                $operation,
                'BLOG_UNSUPPORTED_COMPONENTS',
                'The selected Blog contains unsupported Page Editor components.'
            );
            return $this->empty_summary();
        }

        $assetcount = $this->validate_assets($operation, $structure);
        if (($operation['action'] ?? '') === 'BLOCKED') {
            return $this->empty_summary();
        }

        $items = is_array($structure['postings'] ?? null)
            ? $structure['postings']
            : [];
        if (!$items) {
            $this->block(
                $operation,
                'BLOG_EMPTY',
                'The normalized Blog contains no postings.'
            );
            return $this->empty_summary();
        }

        $ids = [];
        $positions = [];
        $keywordcount = 0;
        $sourceauthors = [];

        foreach ($items as $posting) {
            if (!is_array($posting)) {
                $this->block(
                    $operation,
                    'BLOG_POSTING_INVALID',
                    'At least one Blog posting is invalid.'
                );
                return $this->empty_summary();
            }

            $postingid = trim((string) ($posting['source_id'] ?? ''));
            $position = (int) ($posting['position'] ?? 0);
            $title = trim((string) ($posting['title'] ?? ''));
            $created = trim((string) ($posting['created'] ?? ''));
            $author = trim((string) ($posting['author_source'] ?? ''));

            if ($postingid === '' || isset($ids[$postingid])) {
                $this->block(
                    $operation,
                    'BLOG_POSTING_IDENTITY_INVALID',
                    'Blog posting source ids must be present and unique.'
                );
                return $this->empty_summary();
            }
            if ($position <= 0 || isset($positions[$position])) {
                $this->block(
                    $operation,
                    'BLOG_POSTING_POSITION_INVALID',
                    'Blog posting positions must be positive and unique.'
                );
                return $this->empty_summary();
            }
            if ($title === '') {
                $this->block(
                    $operation,
                    'BLOG_POSTING_TITLE_MISSING',
                    'Every Blog posting must have a title.'
                );
                return $this->empty_summary();
            }
            if ($created === ''
                    || \DateTimeImmutable::createFromFormat(
                        'Y-m-d H:i:s',
                        $created
                    ) === false) {
                $this->block(
                    $operation,
                    'BLOG_POSTING_DATE_INVALID',
                    'Every Blog posting must have an ILIAS creation timestamp.'
                );
                return $this->empty_summary();
            }
            if ($author === '') {
                $this->block(
                    $operation,
                    'BLOG_SOURCE_AUTHOR_MISSING',
                    'Every Blog posting must retain its ILIAS source author identifier.'
                );
                return $this->empty_summary();
            }

            $content = is_array($posting['content'] ?? null)
                ? $posting['content']
                : [];
            if ((string) ($content['status'] ?? '') !== 'ok') {
                $this->block(
                    $operation,
                    'BLOG_POSTING_CONTENT_MISSING',
                    'At least one Blog posting has no usable COPage content.'
                );
                return $this->empty_summary();
            }

            $postingunsupported = $content['unsupported_components'] ?? [];
            if (!is_array($postingunsupported) || $postingunsupported) {
                $this->block(
                    $operation,
                    'BLOG_POSTING_UNSUPPORTED_COMPONENTS',
                    'At least one Blog posting contains unsupported Page Editor components.'
                );
                return $this->empty_summary();
            }

            $blocks = is_array($content['blocks'] ?? null)
                ? $content['blocks']
                : [];
            if (!$blocks) {
                $this->block(
                    $operation,
                    'BLOG_POSTING_CONTENT_EMPTY',
                    'At least one Blog posting contains no normalized content block.'
                );
                return $this->empty_summary();
            }

            $keywords = is_array($posting['keywords'] ?? null)
                ? $posting['keywords']
                : [];
            foreach ($keywords as $keyword) {
                if (trim((string) $keyword) !== '') {
                    $keywordcount++;
                }
            }

            $ids[$postingid] = true;
            $positions[$position] = true;
            $sourceauthors[$author] = true;
        }

        if ((int) ($structure['posting_count'] ?? -1) !== count($items)
                || (int) ($structure['media_count'] ?? -1)
                    !== count((array) ($structure['media'] ?? []))
                || (int) ($structure['file_count'] ?? -1)
                    !== count((array) ($structure['files'] ?? []))) {
            $this->block(
                $operation,
                'BLOG_COUNT_MISMATCH',
                'Blog normalized counters do not match the validated content.'
            );
            return $this->empty_summary();
        }

        $operation['blog_validation'] = [
            'status' => 'READY',
            'code' => 'BLOG_READY',
            'schema_version' => '1.0',
            'posting_count' => count($items),
            'asset_count' => $assetcount,
            'keyword_count' => $keywordcount,
            'source_author_count' => count($sourceauthors),
            'moodle_activity' => 'mod_data',
            'record_policy' => 'ONE_RECORD_PER_BLOG_POSTING',
            'planned_fields' => [
                'source_posting_id',
                'position',
                'title',
                'created',
                'source_author',
                'keywords',
                'content',
            ],
            'author_policy' => 'SOURCE_IDENTIFIER_ONLY_PHASE7_MOODLE_USER',
        ];

        return [
            'postings' => count($items),
            'assets' => $assetcount,
            'keywords' => $keywordcount,
            'source_authors' => array_keys($sourceauthors),
        ];
    }

    private function validate_assets(
        array &$operation,
        array $structure
    ): int {
        $assets = 0;

        foreach ((array) ($structure['media'] ?? []) as $media) {
            if (!is_array($media)) {
                continue;
            }
            foreach ((array) ($media['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $path = trim((string) ($item['migration_path'] ?? ''));
                if ($path === '') {
                    continue;
                }

                $file = $this->resolve_relative_file($path);
                $size = (int) ($item['migration_size'] ?? 0);
                $sha256 = strtolower(
                    trim((string) ($item['migration_sha256'] ?? ''))
                );

                if ($file === null
                        || $size <= 0
                        || !preg_match('/^[0-9a-f]{64}$/', $sha256)) {
                    $this->block(
                        $operation,
                        'BLOG_ASSET_PACKAGE_INVALID',
                        'A Blog media asset is missing its file, size or SHA-256.'
                    );
                    return 0;
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
                        'BLOG_ASSET_INTEGRITY_ERROR',
                        'A Blog media asset no longer matches its normalized size/SHA-256.'
                    );
                    return 0;
                }

                $locationtype = (string) ($item['location_type'] ?? '');
                $mime = strtolower((string) ($item['mime_type'] ?? ''));
                if ($locationtype !== 'LocalFile'
                        || !str_starts_with($mime, 'image/')) {
                    $this->block(
                        $operation,
                        'BLOG_MEDIA_TYPE_NOT_VALIDATED',
                        'The Phase 6.5.7 POC currently validates embedded local image media only.'
                    );
                    return 0;
                }

                $assets++;
            }
        }

        foreach ((array) ($structure['files'] ?? []) as $file) {
            if (!is_array($file)
                    || empty($file['migration_path'])) {
                continue;
            }

            $path = (string) $file['migration_path'];
            if ($this->resolve_relative_file($path) === null) {
                $this->block(
                    $operation,
                    'BLOG_FILE_MISSING',
                    'A normalized Blog file is missing from the migration package.'
                );
                return 0;
            }
            $assets++;
        }

        return $assets;
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
            'message' => 'No Moodle section/subsection mapping exists for the ILIAS Blog parent.',
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
        $operation['blog_validation'] = [
            'status' => 'BLOCKED',
            'code' => $code,
            'message' => $message,
        ];
    }

    private function empty_summary(): array {
        return [
            'postings' => 0,
            'assets' => 0,
            'keywords' => 0,
            'source_authors' => [],
        ];
    }
}

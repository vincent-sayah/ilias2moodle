<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only validator/planner for ILIAS Forum -> Moodle mod_forum.
 *
 * Phase 6.5.5 creates/updates only the Forum container. Authored discussions,
 * posts and their post-owned assets remain deferred to Phase 7 because source
 * authors must first be resolved to Moodle users.
 */
final class phase65_forum_package_validator {
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
            ['name' => 'forum'],
            'id,name,visible'
        );
        $available = $module && (int) $module->visible === 1;
        $plan['moodle']['forum_available'] = $available;

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $this->sourcecourse = (string) ($plan['course']['source_id'] ?? '');
        $this->targetcourseid = (int) ($plan['operations'][0]['target_id'] ?? 0);

        $discovered = 0;
        $checked = 0;
        $skipped = 0;
        $blocked = 0;
        $threads = 0;
        $posts = 0;
        $attachments = 0;
        $media = 0;
        $authors = 0;
        $phase7dependencies = 0;
        $creates = 0;
        $updates = 0;

        foreach ($plan['operations'] as &$operation) {
            if ((string) ($operation['kind'] ?? '') !== 'forum') {
                continue;
            }

            $discovered++;
            $sourceref = (string) ($operation['source_ref_id'] ?? '');

            $operation['phase'] = '6.5.5';
            $operation['moodle_module'] = 'forum';
            $operation['migration_structure_path'] =
                'forums/' . $sourceref . '/structure.json';

            // Phase 6.5.5 is incremental. A course Container can reference
            // Forums intentionally omitted from a targeted export fixture.
            // Missing normalized structure therefore means "not selected in
            // this incremental package", not a broken Forum package.
            if ($this->resolve_relative_file(
                (string) $operation['migration_structure_path']
            ) === null) {
                $operation['action'] = 'DEFER';
                $operation['target_id'] = null;
                $operation['reason'] = 'FORUM_NOT_IN_INCREMENTAL_PACKAGE';
                $operation['forum_validation'] = [
                    'status' => 'SKIPPED_INCREMENTAL',
                    'code' => 'FORUM_NOT_IN_INCREMENTAL_PACKAGE',
                    'message' => 'Forum is referenced by the course Container but has no normalized structure in this targeted Phase 6.5.5 package.',
                ];
                $skipped++;
                continue;
            }

            $checked++;
            $mapping = $this->resolve_action($sourceref);
            $operation['action'] = $available ? $mapping['action'] : 'BLOCKED';
            $operation['target_id'] = $mapping['target_id'];

            if (!empty($mapping['legacy_mapping'])) {
                $operation['legacy_sourceinstance_mapping'] = true;
            }

            if (!$available) {
                $this->block(
                    $operation,
                    'FORUM_MODULE_DISABLED',
                    'Moodle mod_forum is missing or disabled.'
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
                    'FORUM_MAPPING_INVALID',
                    'The persistent Forum mapping is stale or points to the wrong Moodle object.'
                );
                $blocked++;
                continue;
            }

            $parent = $this->validate_parent_target($operation);
            $operation['forum_parent_validation'] = $parent;
            if (empty($parent['ready'])) {
                $this->block(
                    $operation,
                    'FORUM_PARENT_MAPPING_INVALID',
                    (string) ($parent['message'] ?? 'The Forum parent mapping is invalid.')
                );
                $blocked++;
                continue;
            }

            $summary = $this->validate_forum($operation);

            $threads += (int) ($summary['threads'] ?? 0);
            $posts += (int) ($summary['posts'] ?? 0);
            $attachments += (int) ($summary['attachments'] ?? 0);
            $media += (int) ($summary['media'] ?? 0);
            $authors += (int) ($summary['authors'] ?? 0);
            $phase7dependencies += (int) ($summary['phase7_dependencies'] ?? 0);

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
                'code' => 'NO_FORUM_SELECTED',
                'message' => 'No normalized ILIAS Forum structure was selected in this incremental Phase 6.5.5 package.',
            ];
        }

        if ($skipped > 0) {
            $plan['warnings'][] = [
                'code' => 'FORUM_INCREMENTAL_OBJECTS_SKIPPED',
                'count' => $skipped,
                'message' => 'Forum objects referenced by the course Container but absent from the targeted normalized package were deferred without blocking selected Forums.',
            ];
        }

        if ($phase7dependencies > 0) {
            $plan['warnings'][] = [
                'code' => 'FORUM_PHASE7_AUTHOR_DEPENDENCY',
                'message' => 'Forum discussions, posts and post-owned assets are preserved in the neutral package but remain deferred until Phase 7 resolves source authors to Moodle users.',
            ];
        }

        $ready = $checked > 0
            && $blocked === 0
            && $available
            && $this->targetcourseid > 0;

        $plan['phase65_forum_package'] = [
            'root' => $this->packageroot,
            'discovered_forums' => $discovered,
            'checked_forums' => $checked,
            'skipped_forums' => $skipped,
            'blocked_forums' => $blocked,
            'forum_create_count' => $creates,
            'forum_update_count' => $updates,
            'thread_count' => $threads,
            'post_count' => $posts,
            'attachment_count' => $attachments,
            'media_object_count' => $media,
            'source_author_count' => $authors,
            'phase7_dependency_count' => $phase7dependencies,
            'forum_available' => $available,
            'prerequisite_policy' => 'PERSISTED_TARGET_STATE',
            'incremental_object_policy' => 'VALIDATE_ONLY_NORMALIZED_FORUMS_IN_PACKAGE',
            'contribution_policy' => 'DEFER_TO_PHASE7_AUTHOR_RESOLUTION',
            'ready' => $ready,
            'apply_implemented' => true,
            'apply_ready' => $ready,
        ];

        return $plan;
    }

    private function validate_forum(array &$operation): array {
        $relative = (string) ($operation['migration_structure_path'] ?? '');
        $structurefile = $this->resolve_relative_file($relative);

        if ($structurefile === null) {
            $this->block(
                $operation,
                'FORUM_STRUCTURE_MISSING',
                'The normalized Forum structure.json is missing from the package.'
            );
            return $this->empty_summary();
        }

        $raw = file_get_contents($structurefile);
        if ($raw === false) {
            $this->block(
                $operation,
                'FORUM_STRUCTURE_UNREADABLE',
                'The normalized Forum structure.json cannot be read.'
            );
            return $this->empty_summary();
        }

        try {
            $structure = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->block(
                $operation,
                'FORUM_STRUCTURE_INVALID_JSON',
                'The normalized Forum structure.json is invalid JSON.'
            );
            return $this->empty_summary();
        }

        if (!is_array($structure)
                || (string) ($structure['schema_version'] ?? '') !== '1.0') {
            $this->block(
                $operation,
                'FORUM_SCHEMA_UNSUPPORTED',
                'Forum structure.json must use schema_version 1.0.'
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
                'FORUM_SOURCE_MISMATCH',
                'Forum source identity does not match the planned ILIAS object.'
            );
            return $this->empty_summary();
        }

        $strategy = is_array($structure['target_strategy'] ?? null)
            ? $structure['target_strategy']
            : [];
        $policy = is_array($structure['user_data_policy'] ?? null)
            ? $structure['user_data_policy']
            : [];

        if ((string) ($strategy['moodle_activity'] ?? '') !== 'mod_forum'
                || empty($strategy['forum_container_migrated_in_phase65'])
                || !empty($strategy['discussions_migrated_in_phase65'])
                || !empty($strategy['posts_migrated_in_phase65'])
                || !empty($strategy['contribution_assets_migrated_in_phase65'])) {
            $this->block(
                $operation,
                'FORUM_TARGET_STRATEGY_INVALID',
                'Forum Phase 6.5.5 must migrate only the Moodle forum container.'
            );
            return $this->empty_summary();
        }

        if (!empty($policy['authors_resolved_to_moodle'])
                || !empty($policy['discussions_migrated'])
                || !empty($policy['posts_migrated'])
                || !empty($policy['attachments_migrated_to_posts'])
                || (string) ($policy['target_phase'] ?? '') !== '7') {
            $this->block(
                $operation,
                'FORUM_USER_DATA_POLICY_INVALID',
                'Forum authors and authored contributions must remain deferred to Phase 7.'
            );
            return $this->empty_summary();
        }

        $missingassets = $structure['missing_assets'] ?? null;
        if (!is_array($missingassets) || $missingassets) {
            $this->block(
                $operation,
                'FORUM_SOURCE_ASSETS_INCOMPLETE',
                'At least one Forum source attachment/media object is missing from the native export.'
            );
            return $this->empty_summary();
        }

        $threads = is_array($structure['threads'] ?? null)
            ? $structure['threads']
            : [];
        $threadids = [];
        $postids = [];
        $postcount = 0;
        $attachmentcount = 0;
        $mediacount = 0;

        foreach ($threads as $thread) {
            if (!is_array($thread)) {
                $this->block(
                    $operation,
                    'FORUM_THREAD_INVALID',
                    'At least one Forum thread is invalid.'
                );
                return $this->empty_summary();
            }

            $threadid = trim((string) ($thread['source_id'] ?? ''));
            if ($threadid === '' || isset($threadids[$threadid])) {
                $this->block(
                    $operation,
                    'FORUM_THREAD_IDENTITY_INVALID',
                    'Forum thread source ids must be present and unique.'
                );
                return $this->empty_summary();
            }
            $threadids[$threadid] = true;

            $localpostids = [];
            $threadposts = is_array($thread['posts'] ?? null)
                ? $thread['posts']
                : [];

            foreach ($threadposts as $post) {
                if (!is_array($post)) {
                    $this->block(
                        $operation,
                        'FORUM_POST_INVALID',
                        'At least one Forum post is invalid.'
                    );
                    return $this->empty_summary();
                }

                $postid = trim((string) ($post['source_id'] ?? ''));
                if ($postid === '' || isset($postids[$postid])) {
                    $this->block(
                        $operation,
                        'FORUM_POST_IDENTITY_INVALID',
                        'Forum post source ids must be present and globally unique.'
                    );
                    return $this->empty_summary();
                }
                if ((string) ($post['thread_source_id'] ?? '') !== $threadid) {
                    $this->block(
                        $operation,
                        'FORUM_POST_THREAD_MISMATCH',
                        'A Forum post references the wrong source thread.'
                    );
                    return $this->empty_summary();
                }

                $postids[$postid] = true;
                $localpostids[$postid] = true;
                $postcount++;

                foreach (['attachments', 'media_objects'] as $assetkey) {
                    $assets = is_array($post[$assetkey] ?? null)
                        ? $post[$assetkey]
                        : [];
                    foreach ($assets as $asset) {
                        if (!is_array($asset)) {
                            $this->block(
                                $operation,
                                'FORUM_ASSET_INVALID',
                                'At least one Forum post asset is invalid.'
                            );
                            return $this->empty_summary();
                        }
                        $path = trim((string) ($asset['migration_path'] ?? ''));
                        if ($path === '' || $this->resolve_relative_file($path) === null) {
                            $this->block(
                                $operation,
                                'FORUM_ASSET_MISSING',
                                'A normalized Forum post asset is missing from the migration package.'
                            );
                            return $this->empty_summary();
                        }
                        if ($assetkey === 'attachments') {
                            $attachmentcount++;
                        } else {
                            $mediacount++;
                        }
                    }
                }
            }

            foreach ($threadposts as $post) {
                if (!is_array($post)) {
                    continue;
                }
                $parent = trim((string) ($post['parent_source_id'] ?? ''));
                $depth = (int) (($post['tree']['depth'] ?? 0));

                if ($depth <= 0) {
                    $this->block(
                        $operation,
                        'FORUM_POST_DEPTH_INVALID',
                        'Forum post depth must be positive.'
                    );
                    return $this->empty_summary();
                }
                if ($parent !== '' && $parent !== '0' && !isset($localpostids[$parent])) {
                    $this->block(
                        $operation,
                        'FORUM_POST_PARENT_INVALID',
                        'A Forum post parent is missing from its source thread.'
                    );
                    return $this->empty_summary();
                }
            }
        }

        $authors = array_values(array_filter(
            (array) ($structure['source_author_ids'] ?? []),
            static fn($value): bool => trim((string) $value) !== ''
                && trim((string) $value) !== '0'
        ));

        if ((int) ($structure['thread_count'] ?? -1) !== count($threads)
                || (int) ($structure['post_count'] ?? -1) !== $postcount
                || (int) ($structure['attachment_count'] ?? -1) !== $attachmentcount
                || (int) ($structure['media_object_count'] ?? -1) !== $mediacount) {
            $this->block(
                $operation,
                'FORUM_COUNT_MISMATCH',
                'Forum normalized counters do not match the parsed thread/post/assets structure.'
            );
            return $this->empty_summary();
        }

        $operation['forum_validation'] = [
            'status' => 'READY_WITH_PHASE7_DEPENDENCY',
            'code' => 'FORUM_CONTAINER_READY',
            'schema_version' => '1.0',
            'thread_count' => count($threads),
            'post_count' => $postcount,
            'attachment_count' => $attachmentcount,
            'media_object_count' => $mediacount,
            'source_author_count' => count($authors),
            'source_author_ids' => $authors,
            'contributions_deferred_to_phase7' => true,
            'authors_resolved_to_moodle' => false,
        ];

        return [
            'threads' => count($threads),
            'posts' => $postcount,
            'attachments' => $attachmentcount,
            'media' => $mediacount,
            'authors' => count($authors),
            'phase7_dependencies' => $postcount > 0 ? 1 : 0,
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
            'message' => 'No Moodle section/subsection mapping exists for the ILIAS Forum parent.',
        ];
    }

    private function resolve_action(string $sourceref): array {
        global $DB;

        $mapping = $this->find_mapping($sourceref, 'forum');
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
                'legacy_mapping' => false,
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
                'legacy_mapping' => false,
            ];
        }

        if ((string) $record->modulename !== 'forum'
                || (int) $record->course !== $this->targetcourseid) {
            return [
                'action' => 'ERROR_MAPPING_TYPE',
                'target_id' => $cmid,
                'legacy_mapping' => false,
            ];
        }

        return [
            'action' => 'UPDATE',
            'target_id' => $cmid,
            'legacy_mapping' => false,
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

        $mapping = $DB->get_record('local_iliasmigration_map', $conditions);
        if ($mapping) {
            return $mapping;
        }

        if ($this->sourceinstance === '') {
            return false;
        }

        $conditions['sourceinstance'] = '';
        return $DB->get_record('local_iliasmigration_map', $conditions);
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
        $operation['forum_validation'] = [
            'status' => 'BLOCKED',
            'code' => $code,
            'message' => $message,
        ];
    }

    private function empty_summary(): array {
        return [
            'threads' => 0,
            'posts' => 0,
            'attachments' => 0,
            'media' => 0,
            'authors' => 0,
            'phase7_dependencies' => 0,
        ];
    }
}

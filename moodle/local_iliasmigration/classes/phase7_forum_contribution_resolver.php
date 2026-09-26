<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only Phase 7.4 resolver for ILIAS Forum contributions.
 *
 * Requires the Phase 6.5.5 forum container to exist and Phase 7.1 persistent
 * user mappings to resolve all historical authors. No discussions, posts,
 * files or mappings are written here.
 */
final class phase7_forum_contribution_resolver {
    private string $packageroot;

    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        if ($root === false || !is_dir($root)) {
            throw new \coding_exception(
                'Unable to resolve the Forum migration package directory.'
            );
        }
        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
    }

    public function resolve(
        array $document,
        string $migrationjson,
        int $categoryid,
        string $forumref
    ): array {
        global $DB;

        $forumref = trim($forumref);
        if ($forumref === '') {
            throw new \coding_exception('Forum source ref_id is required.');
        }

        $plan = (new phase6_plan_builder($categoryid))->build($document);
        $plan['phase'] = '7.4';

        $plan = (new phase65_forum_package_validator($migrationjson))
            ->validate($plan);

        $operation = null;
        foreach ($plan['operations'] as $candidate) {
            if ((string) ($candidate['kind'] ?? '') !== 'forum') {
                continue;
            }
            if ((string) ($candidate['source_ref_id'] ?? '') !== $forumref) {
                continue;
            }
            $operation = $candidate;
            break;
        }

        if (!is_array($operation)) {
            throw new \coding_exception(
                'Requested Forum ref_id is not present in the migration plan: '
                . $forumref
            );
        }

        $validation = is_array($operation['forum_validation'] ?? null)
            ? $operation['forum_validation']
            : [];

        if (($validation['status'] ?? '') !== 'READY_WITH_PHASE7_DEPENDENCY') {
            throw new \coding_exception(
                'Forum container/package is not ready for Phase 7.4.'
            );
        }

        $courseid = (int) ($plan['operations'][0]['target_id'] ?? 0);
        if ($courseid <= 0) {
            throw new \coding_exception('Target Moodle course is unresolved.');
        }

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,shortname,fullname',
            MUST_EXIST
        );

        $cmid = (int) ($operation['target_id'] ?? 0);
        if ($cmid <= 0) {
            throw new \coding_exception(
                'Persistent target Forum mapping is missing.'
            );
        }

        $cm = get_coursemodule_from_id(
            'forum',
            $cmid,
            $courseid,
            false,
            MUST_EXIST
        );

        $forum = $DB->get_record(
            'forum',
            ['id' => (int) $cm->instance],
            'id,course,name,type',
            MUST_EXIST
        );

        if ((int) $forum->course !== $courseid) {
            throw new \coding_exception(
                'Mapped Moodle Forum belongs to the wrong course.'
            );
        }

        $structure = $this->read_structure($forumref);

        $sourceinstance = trim((string) ($plan['source']['instance'] ?? ''));
        $sourcecourse = trim((string) ($document['course']['source_id'] ?? ''));

        if ($sourcecourse === '') {
            throw new \coding_exception('Source course id is missing.');
        }

        $context = \context_course::instance($courseid);
        $enrolled = get_enrolled_users(
            $context,
            '',
            0,
            'u.id,u.username'
        );
        $enrolledids = array_fill_keys(
            array_map('intval', array_keys($enrolled)),
            true
        );

        $authorids = array_values(array_unique(array_filter(
            array_map(
                static fn($value): string => trim((string) $value),
                (array) ($structure['source_author_ids'] ?? [])
            ),
            static fn(string $value): bool => $value !== '' && $value !== '0'
        )));
        sort($authorids, SORT_NATURAL);

        $authors = [];
        $authorissues = 0;

        foreach ($authorids as $sourceuserid) {
            $mappingresolution = $this->resolve_user_mapping(
                $sourceinstance,
                $sourceuserid
            );
            $mapping = $mappingresolution['mapping'];

            $targetuser = null;
            if ($mapping && (int) $mapping->targetid > 0) {
                $targetuser = $DB->get_record(
                    'user',
                    [
                        'id' => (int) $mapping->targetid,
                        'deleted' => 0,
                    ],
                    'id,username,firstname,lastname,suspended'
                );
            }

            $ready = $mapping
                && (int) $mapping->targetid > 0
                && $targetuser
                && empty($targetuser->suspended);

            if (!$ready) {
                $authorissues++;
            }

            $authors[$sourceuserid] = [
                'source_user_id' => $sourceuserid,
                'mapping_found' => (bool) $mapping,
                'mapping_status' => $mapping
                    ? (string) $mapping->status
                    : 'MISSING',
                'mapping_resolution' => (string) (
                    $mappingresolution['resolution'] ?? 'MISSING'
                ),
                'mapping_sourceinstance' => $mapping
                    ? (string) $mapping->sourceinstance
                    : null,
                'mapping_candidates' => (int) (
                    $mappingresolution['candidate_count'] ?? 0
                ),
                'target_user_id' => $mapping
                    ? (int) $mapping->targetid
                    : null,
                'target_username' => $targetuser
                    ? (string) $targetuser->username
                    : null,
                'target_user_exists' => (bool) $targetuser,
                'target_user_suspended' => $targetuser
                    ? !empty($targetuser->suspended)
                    : null,
                'target_course_enrolled' => $targetuser
                    ? isset($enrolledids[(int) $targetuser->id])
                    : false,
                'target_is_site_admin' => $targetuser
                    ? is_siteadmin((int) $targetuser->id)
                    : false,
                'ready_as_historical_author' => (bool) $ready,
            ];
        }

        $threads = [];
        $posts = [];
        $threadcreate = 0;
        $threadkeep = 0;
        $postcreate = 0;
        $postkeep = 0;
        $blocked = 0;
        $assetcount = 0;
        $assetmissing = 0;

        foreach ((array) ($structure['threads'] ?? []) as $thread) {
            if (!is_array($thread)) {
                $blocked++;
                continue;
            }

            $threadid = trim((string) ($thread['source_id'] ?? ''));
            if ($threadid === '') {
                $blocked++;
                continue;
            }

            $threadmappingref = $forumref . ':thread:' . $threadid;
            $threadmapping = $this->find_mapping(
                $sourceinstance,
                $sourcecourse,
                $threadmappingref,
                'forumdiscussion'
            );

            $targetdiscussion = null;
            $threadaction = 'CREATE';
            $threadreason = 'NO_PERSISTENT_DISCUSSION_MAPPING';

            if ($threadmapping && (int) $threadmapping->targetid > 0) {
                $targetdiscussion = $DB->get_record(
                    'forum_discussions',
                    [
                        'id' => (int) $threadmapping->targetid,
                        'forum' => (int) $forum->id,
                    ],
                    'id,forum,course,name,userid,firstpost'
                );

                if ($targetdiscussion) {
                    $threadaction = 'KEEP';
                    $threadreason = 'PERSISTENT_DISCUSSION_MAPPING_VALID';
                    $threadkeep++;
                } else {
                    $threadaction = 'BLOCKED';
                    $threadreason = 'MAPPED_DISCUSSION_MISSING_OR_WRONG_FORUM';
                    $blocked++;
                }
            } else {
                $threadcreate++;
            }

            $threadposts = is_array($thread['posts'] ?? null)
                ? $thread['posts']
                : [];

            $rootpost = null;
            foreach ($threadposts as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $parent = trim((string) ($candidate['parent_source_id'] ?? ''));
                if ($parent === '' || $parent === '0') {
                    $rootpost = $candidate;
                    break;
                }
            }

            $threadsourceauthor = $this->source_author_id(
                is_array($rootpost) ? $rootpost : $thread
            );
            $threadauthor = $authors[$threadsourceauthor] ?? null;

            if (!$threadauthor
                    || empty($threadauthor['ready_as_historical_author'])) {
                if ($threadaction !== 'BLOCKED') {
                    $blocked++;
                }
                $threadaction = 'BLOCKED';
                $threadreason = 'THREAD_AUTHOR_MAPPING_NOT_READY';
            }

            $threads[$threadid] = [
                'source_thread_id' => $threadid,
                'subject' => (string) ($thread['subject'] ?? ''),
                'create_date' => (string) ($thread['create_date'] ?? ''),
                'update_date' => (string) ($thread['update_date'] ?? ''),
                'sticky' => !empty($thread['sticky']),
                'closed' => !empty($thread['closed']),
                'source_author_id' => $threadsourceauthor,
                'target_author_user_id' => $threadauthor['target_user_id'] ?? null,
                'mapping_ref' => $threadmappingref,
                'mapping_found' => (bool) $threadmapping,
                'target_discussion_id' => $threadmapping
                    ? (int) $threadmapping->targetid
                    : null,
                'action' => $threadaction,
                'reason' => $threadreason,
                'post_count' => count($threadposts),
            ];

            foreach ($threadposts as $post) {
                if (!is_array($post)) {
                    $blocked++;
                    continue;
                }

                $postid = trim((string) ($post['source_id'] ?? ''));
                if ($postid === '') {
                    $blocked++;
                    continue;
                }

                $sourceauthor = $this->source_author_id($post);
                $author = $authors[$sourceauthor] ?? null;

                $postmappingref = $forumref . ':post:' . $postid;
                $postmapping = $this->find_mapping(
                    $sourceinstance,
                    $sourcecourse,
                    $postmappingref,
                    'forumpost'
                );

                $targetpost = null;
                $postaction = 'CREATE';
                $postreason = 'NO_PERSISTENT_POST_MAPPING';

                if ($postmapping && (int) $postmapping->targetid > 0) {
                    $targetpost = $DB->get_record(
                        'forum_posts',
                        ['id' => (int) $postmapping->targetid],
                        'id,discussion,parent,userid,subject,created,modified'
                    );

                    if ($targetpost) {
                        $postaction = 'KEEP';
                        $postreason = 'PERSISTENT_POST_MAPPING_VALID';
                        $postkeep++;
                    } else {
                        $postaction = 'BLOCKED';
                        $postreason = 'MAPPED_POST_MISSING';
                        $blocked++;
                    }
                } else {
                    $postcreate++;
                }

                if (!$author
                        || empty($author['ready_as_historical_author'])) {
                    if ($postaction !== 'BLOCKED') {
                        $blocked++;
                    }
                    $postaction = 'BLOCKED';
                    $postreason = 'POST_AUTHOR_MAPPING_NOT_READY';
                }

                $assets = [];
                foreach (['attachments', 'media_objects'] as $assetkind) {
                    foreach ((array) ($post[$assetkind] ?? []) as $asset) {
                        if (!is_array($asset)) {
                            $assetmissing++;
                            $blocked++;
                            continue;
                        }

                        $assetcount++;
                        $relative = trim((string) ($asset['migration_path'] ?? ''));
                        $resolved = $this->resolve_relative_file($relative);

                        if ($resolved === null) {
                            $assetmissing++;
                            $blocked++;
                        }

                        $assets[] = [
                            'kind' => $assetkind === 'attachments'
                                ? 'attachment'
                                : 'media_object',
                            'filename' => (string) ($asset['filename'] ?? ''),
                            'migration_path' => $relative,
                            'exists' => $resolved !== null,
                            'size' => $resolved !== null
                                ? (int) filesize($resolved)
                                : null,
                        ];
                    }
                }

                $posts[$postid] = [
                    'source_post_id' => $postid,
                    'source_thread_id' => $threadid,
                    'parent_source_id' => trim((string) (
                        $post['parent_source_id'] ?? ''
                    )),
                    'tree_depth' => (int) ($post['tree']['depth'] ?? 0),
                    'subject' => (string) ($post['subject'] ?? ''),
                    'message_html' => (string) ($post['message_html'] ?? ''),
                    'create_date' => (string) ($post['create_date'] ?? ''),
                    'update_date' => (string) ($post['update_date'] ?? ''),
                    'source_author_id' => $sourceauthor,
                    'target_author_user_id' => $author['target_user_id'] ?? null,
                    'author_ready' => $author
                        ? (bool) $author['ready_as_historical_author']
                        : false,
                    'mapping_ref' => $postmappingref,
                    'mapping_found' => (bool) $postmapping,
                    'target_post_id' => $postmapping
                        ? (int) $postmapping->targetid
                        : null,
                    'action' => $postaction,
                    'reason' => $postreason,
                    'assets' => $assets,
                ];
            }
        }

        $expectedthreads = (int) ($structure['thread_count'] ?? -1);
        $expectedposts = (int) ($structure['post_count'] ?? -1);
        $expectedassets = (int) ($structure['attachment_count'] ?? 0)
            + (int) ($structure['media_object_count'] ?? 0);

        if ($expectedthreads !== count($threads)
                || $expectedposts !== count($posts)
                || $expectedassets !== $assetcount) {
            $blocked++;
        }

        $ready = $authorissues === 0
            && $blocked === 0
            && $assetmissing === 0
            && count($threads) > 0
            && count($posts) > 0;

        return [
            'phase' => '7.4',
            'mode' => 'dry-run',
            'writes_performed' => false,
            'source' => [
                'lms' => 'ILIAS',
                'instance' => $sourceinstance,
                'course_object_id' => $sourcecourse,
                'forum_ref_id' => $forumref,
                'forum_object_id' => (string) (
                    $structure['source']['object_id'] ?? ''
                ),
            ],
            'target_course' => [
                'id' => (int) $course->id,
                'shortname' => (string) $course->shortname,
                'fullname' => (string) $course->fullname,
            ],
            'target_forum' => [
                'cmid' => $cmid,
                'instance_id' => (int) $forum->id,
                'name' => (string) $forum->name,
                'type' => (string) $forum->type,
            ],
            'policy' => [
                'persistent_user_mapping_required' => true,
                'target_user_must_exist' => true,
                'target_course_enrolment_required_for_historical_author' => false,
                'source_user_id_preferred_over_author_id' => true,
                'default_technical_author_allowed' => false,
                'preserve_thread_tree' => true,
                'preserve_source_dates' => true,
                'assets_via_file_api' => true,
                'idempotence_via_persistent_contribution_mappings' => true,
            ],
            'counts' => [
                'source_authors' => count($authors),
                'author_mapping_issues' => $authorissues,
                'threads' => count($threads),
                'thread_create' => $threadcreate,
                'thread_keep' => $threadkeep,
                'posts' => count($posts),
                'post_create' => $postcreate,
                'post_keep' => $postkeep,
                'assets' => $assetcount,
                'assets_missing' => $assetmissing,
                'blocked_items' => $blocked,
            ],
            'authors' => $authors,
            'threads' => $threads,
            'posts' => $posts,
            'ready_for_apply' => $ready,
            'apply_implemented' => false,
        ];
    }

    private function source_author_id(array $entry): string {
        $userid = trim((string) ($entry['source_user_id'] ?? ''));
        if ($userid !== '' && $userid !== '0') {
            return $userid;
        }

        $authorid = trim((string) ($entry['source_author_id'] ?? ''));
        if ($authorid !== '' && $authorid !== '0') {
            return $authorid;
        }

        return '';
    }

    private function read_structure(string $forumref): array {
        $relative = 'forums/' . $forumref . '/structure.json';
        $path = $this->resolve_relative_file($relative);

        if ($path === null) {
            throw new \coding_exception(
                'Forum structure.json is missing for ref_id ' . $forumref
            );
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \coding_exception(
                'Invalid Forum structure.json: ' . $exception->getMessage()
            );
        }

        if (!is_array($decoded)
                || (string) ($decoded['schema_version'] ?? '') !== '1.0') {
            throw new \coding_exception(
                'Unsupported Forum contribution structure schema.'
            );
        }

        return $decoded;
    }

    private function resolve_user_mapping(
        string $sourceinstance,
        string $sourceuserid
    ): array {
        global $DB;

        if ($sourceinstance !== '') {
            $exact = $DB->get_record(
                'local_iliasmigration_map',
                [
                    'sourcelms' => 'ILIAS',
                    'sourceinstance' => $sourceinstance,
                    'sourcecourse' => 'GLOBAL',
                    'sourceref' => $sourceuserid,
                    'targettype' => 'user',
                ],
                'id,sourceinstance,targetid,status'
            );

            if ($exact) {
                return [
                    'mapping' => $exact,
                    'resolution' => 'EXACT_SOURCEINSTANCE',
                    'candidate_count' => 1,
                ];
            }
        }

        $candidates = $DB->get_records(
            'local_iliasmigration_map',
            [
                'sourcelms' => 'ILIAS',
                'sourcecourse' => 'GLOBAL',
                'sourceref' => $sourceuserid,
                'targettype' => 'user',
            ],
            'id ASC',
            'id,sourceinstance,targetid,status'
        );

        if (count($candidates) === 1) {
            return [
                'mapping' => reset($candidates),
                'resolution' => $sourceinstance === ''
                    ? 'UNIQUE_GLOBAL_MAPPING_WITHOUT_PACKAGE_INSTANCE'
                    : 'UNIQUE_GLOBAL_MAPPING_AFTER_EXACT_MISS',
                'candidate_count' => 1,
            ];
        }

        return [
            'mapping' => false,
            'resolution' => count($candidates) > 1
                ? 'AMBIGUOUS_GLOBAL_MAPPING'
                : 'MISSING_GLOBAL_MAPPING',
            'candidate_count' => count($candidates),
        ];
    }

    private function find_mapping(
        string $sourceinstance,
        string $sourcecourse,
        string $sourceref,
        string $targettype
    ): \stdClass|false {
        global $DB;

        return $DB->get_record(
            'local_iliasmigration_map',
            [
                'sourcelms' => 'ILIAS',
                'sourceinstance' => $sourceinstance,
                'sourcecourse' => $sourcecourse,
                'sourceref' => $sourceref,
                'targettype' => $targettype,
            ]
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
}

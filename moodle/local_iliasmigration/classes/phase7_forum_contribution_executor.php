<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Phase 7.4 apply for ILIAS Forum authored contributions.
 *
 * Discussions/posts are created through Moodle Forum APIs. Attachments are
 * staged through the user draft area and imported by the Forum/File APIs.
 *
 * Direct DB updates are intentionally limited to post/discussion migration
 * metadata that Moodle's public creation functions do not expose:
 * source timestamps and mailed state. Content rows themselves are never
 * inserted directly by this executor.
 */
final class phase7_forum_contribution_executor {
    private string $packageroot;
    private string $migrationjson;

    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        $file = realpath($migrationjson);

        if ($root === false || !is_dir($root)
                || $file === false || !is_file($file)) {
            throw new \coding_exception(
                'Unable to resolve Phase 7.4 Forum migration package.'
            );
        }

        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
        $this->migrationjson = $file;
    }

    public function execute(
        array $document,
        int $categoryid,
        string $forumref
    ): array {
        global $CFG, $DB, $USER, $COURSE, $PAGE;

        require_once($CFG->dirroot . '/mod/forum/lib.php');
        require_once($CFG->libdir . '/filelib.php');

        if (!class_exists('mod_forum_post_form')) {
            $postformcandidates = [
                $CFG->dirroot . '/mod/forum/classes/post_form.php',
                $CFG->dirroot . '/mod/forum/post_form.php',
                $CFG->dirroot . '/public/mod/forum/classes/post_form.php',
                $CFG->dirroot . '/public/mod/forum/post_form.php',
                dirname($CFG->dirroot) . '/public/mod/forum/classes/post_form.php',
                dirname($CFG->dirroot) . '/public/mod/forum/post_form.php',
            ];

            $postformloaded = false;
            foreach ($postformcandidates as $candidate) {
                if (is_file($candidate)) {
                    require_once($candidate);
                    $postformloaded = class_exists('mod_forum_post_form');
                    if ($postformloaded) {
                        break;
                    }
                }
            }

            if (!$postformloaded) {
                throw new \coding_exception(
                    'Unable to locate/load Moodle mod_forum_post_form. Checked: '
                    . implode(', ', $postformcandidates)
                );
            }
        }

        $resolver = new phase7_forum_contribution_resolver(
            $this->migrationjson
        );
        $plan = $resolver->resolve(
            $document,
            $this->migrationjson,
            $categoryid,
            $forumref
        );

        if (empty($plan['ready_for_apply'])) {
            throw new \coding_exception(
                'Phase 7.4 Forum contribution plan is not ready for apply.'
            );
        }

        if (!empty($plan['target_forum']['notification_risk'])) {
            throw new \coding_exception(
                'Forum contribution apply is blocked because notification risk is present.'
            );
        }

        $sourceinstance = trim((string) (
            $plan['source']['mapping_instance'] ?? ''
        ));
        $sourcecourse = trim((string) (
            $plan['source']['course_object_id'] ?? ''
        ));
        $sourceforumobj = trim((string) (
            $plan['source']['forum_object_id'] ?? ''
        ));

        if ($sourceinstance === '' || $sourcecourse === '') {
            throw new \coding_exception(
                'Phase 7.4 requires a canonical source instance and course id.'
            );
        }

        $courseid = (int) ($plan['target_course']['id'] ?? 0);
        $cmid = (int) ($plan['target_forum']['cmid'] ?? 0);
        $forumid = (int) ($plan['target_forum']['instance_id'] ?? 0);

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            '*',
            MUST_EXIST
        );
        $cm = get_coursemodule_from_id(
            'forum',
            $cmid,
            $courseid,
            false,
            MUST_EXIST
        );
        $forum = $DB->get_record(
            'forum',
            ['id' => $forumid, 'course' => $courseid],
            '*',
            MUST_EXIST
        );

        $modulecontext = \context_module::instance($cmid);
        $originaluser = $USER;
        $originalcourse = $COURSE;

        $COURSE = $course;
        $PAGE->set_context($modulecontext);

        $createddiscussions = 0;
        $keptdiscussions = 0;
        $createdposts = 0;
        $keptposts = 0;
        $createdattachments = 0;
        $createdmappings = 0;
        $repairedrootmappings = 0;
        $writes = false;

        try {
            $transaction = $DB->start_delegated_transaction();

            try {
                $posttargets = [];

                foreach ($plan['threads'] as $threadid => $threadplan) {
                    $threadid = (string) $threadid;

                    $threadposts = array_filter(
                        $plan['posts'],
                        static fn(array $post): bool =>
                            (string) ($post['source_thread_id'] ?? '')
                                === $threadid
                    );

                    $rootposts = array_filter(
                        $threadposts,
                        static function(array $post): bool {
                            $parent = trim((string) (
                                $post['parent_source_id'] ?? ''
                            ));
                            return $parent === '' || $parent === '0';
                        }
                    );

                    if (count($rootposts) !== 1) {
                        throw new \coding_exception(
                            'Forum thread must contain exactly one root post: '
                            . $threadid
                        );
                    }

                    $root = reset($rootposts);
                    $rootsourceid = (string) $root['source_post_id'];

                    $discussionid = 0;
                    $rootpostid = 0;
                    $threadcreated = false;
                    $threadchanged = false;

                    if (($threadplan['action'] ?? '') === 'KEEP') {
                        $discussionid = (int) (
                            $threadplan['target_discussion_id'] ?? 0
                        );
                        $discussion = $DB->get_record(
                            'forum_discussions',
                            [
                                'id' => $discussionid,
                                'forum' => $forumid,
                            ],
                            '*',
                            MUST_EXIST
                        );

                        $rootpostid = (int) $discussion->firstpost;
                        $rootrecord = $DB->get_record(
                            'forum_posts',
                            [
                                'id' => $rootpostid,
                                'discussion' => $discussionid,
                                'parent' => 0,
                            ],
                            'id,userid',
                            MUST_EXIST
                        );

                        if ((int) $rootrecord->userid
                                !== (int) $root['target_author_user_id']) {
                            throw new \coding_exception(
                                'Existing Forum root post author does not match source mapping.'
                            );
                        }

                        $keptdiscussions++;
                        $keptposts++;
                    } else if (($threadplan['action'] ?? '') === 'CREATE') {
                        $authorid = (int) (
                            $root['target_author_user_id'] ?? 0
                        );
                        $author = $this->target_user($authorid);
                        \core\session\manager::set_user($author);

                        $created = $this->source_time(
                            (string) ($root['create_date'] ?? ''),
                            time()
                        );
                        $modified = $this->source_time(
                            (string) ($root['update_date'] ?? ''),
                            $created
                        );

                        [$attachmentdraft, $attachmentcount] =
                            $this->create_attachment_draft(
                                (array) ($root['assets'] ?? []),
                                $authorid
                            );

                        $discussiondata = (object) [
                            'course' => $courseid,
                            'forum' => $forumid,
                            'name' => (string) ($threadplan['subject'] ?? ''),
                            'message' => (string) ($root['message_html'] ?? ''),
                            'messageformat' => FORMAT_HTML,
                            'messagetrust' => 0,
                            'mailnow' => 0,
                            'groupid' => -1,
                            'timestart' => 0,
                            'timeend' => 0,
                            'pinned' => !empty($threadplan['sticky'])
                                ? FORUM_DISCUSSION_PINNED
                                : FORUM_DISCUSSION_UNPINNED,
                            'timenow' => $created,
                        ];

                        if ($attachmentdraft > 0) {
                            $discussiondata->attachments = $attachmentdraft;
                        }

                        $discussionid = (int) forum_add_discussion(
                            $discussiondata,
                            $attachmentdraft > 0
                                ? $attachmentdraft
                                : null,
                            null,
                            $authorid
                        );

                        if ($discussionid <= 0) {
                            throw new \coding_exception(
                                'Moodle failed to create Forum discussion.'
                            );
                        }

                        $discussion = $DB->get_record(
                            'forum_discussions',
                            [
                                'id' => $discussionid,
                                'forum' => $forumid,
                            ],
                            '*',
                            MUST_EXIST
                        );
                        $rootpostid = (int) $discussion->firstpost;

                        $this->normalise_post_metadata(
                            $rootpostid,
                            $created,
                            $modified
                        );

                        $createdattachments += $attachmentcount;
                        $createddiscussions++;
                        $createdposts++;
                        $threadcreated = true;
                        $threadchanged = true;
                        $writes = true;

                        $this->save_mapping(
                            $sourceinstance,
                            $sourcecourse,
                            $forumref . ':thread:' . $threadid,
                            $threadid,
                            'forumdiscussion',
                            $discussionid,
                            (string) ($document['source']['version'] ?? '')
                        );
                        $createdmappings++;
                    } else {
                        throw new \coding_exception(
                            'Unexpected Forum discussion action: '
                            . (string) ($threadplan['action'] ?? '')
                        );
                    }

                    $rootmapping = $this->get_mapping(
                        $sourceinstance,
                        $sourcecourse,
                        $forumref . ':post:' . $rootsourceid,
                        'forumpost'
                    );

                    if ($rootmapping) {
                        if ((int) $rootmapping->targetid !== $rootpostid) {
                            throw new \coding_exception(
                                'Persistent root post mapping points to a different Moodle post.'
                            );
                        }
                    } else {
                        $this->save_mapping(
                            $sourceinstance,
                            $sourcecourse,
                            $forumref . ':post:' . $rootsourceid,
                            $rootsourceid,
                            'forumpost',
                            $rootpostid,
                            (string) ($document['source']['version'] ?? '')
                        );
                        $createdmappings++;
                        if (!$threadcreated) {
                            $repairedrootmappings++;
                        }
                        $writes = true;
                    }

                    $posttargets[$rootsourceid] = $rootpostid;

                    $replies = array_values(array_filter(
                        $threadposts,
                        static function(array $post): bool {
                            $parent = trim((string) (
                                $post['parent_source_id'] ?? ''
                            ));
                            return $parent !== '' && $parent !== '0';
                        }
                    ));

                    usort(
                        $replies,
                        static function(array $a, array $b): int {
                            $depth = (int) ($a['tree_depth'] ?? 0)
                                <=> (int) ($b['tree_depth'] ?? 0);
                            if ($depth !== 0) {
                                return $depth;
                            }
                            return strnatcmp(
                                (string) ($a['source_post_id'] ?? ''),
                                (string) ($b['source_post_id'] ?? '')
                            );
                        }
                    );

                    $latesttime = $this->source_time(
                        (string) ($root['update_date'] ?? ''),
                        $this->source_time(
                            (string) ($root['create_date'] ?? ''),
                            time()
                        )
                    );
                    $latestuserid = (int) $root['target_author_user_id'];

                    foreach ($replies as $postplan) {
                        $sourcepostid = (string) $postplan['source_post_id'];
                        $parentsourceid = (string) $postplan['parent_source_id'];

                        if (!isset($posttargets[$parentsourceid])) {
                            throw new \coding_exception(
                                'Forum reply parent target is unresolved: '
                                . $sourcepostid
                                . ' -> '
                                . $parentsourceid
                            );
                        }

                        if (($postplan['action'] ?? '') === 'KEEP') {
                            $targetpostid = (int) (
                                $postplan['target_post_id'] ?? 0
                            );
                            $existing = $DB->get_record(
                                'forum_posts',
                                [
                                    'id' => $targetpostid,
                                    'discussion' => $discussionid,
                                ],
                                'id,parent,userid',
                                MUST_EXIST
                            );

                            if ((int) $existing->parent
                                    !== (int) $posttargets[$parentsourceid]
                                    || (int) $existing->userid
                                    !== (int) $postplan['target_author_user_id']) {
                                throw new \coding_exception(
                                    'Existing Forum post tree/author differs from source mapping.'
                                );
                            }

                            $posttargets[$sourcepostid] = $targetpostid;
                            $keptposts++;
                            continue;
                        }

                        if (($postplan['action'] ?? '') !== 'CREATE') {
                            throw new \coding_exception(
                                'Unexpected Forum post action: '
                                . (string) ($postplan['action'] ?? '')
                            );
                        }

                        $authorid = (int) (
                            $postplan['target_author_user_id'] ?? 0
                        );
                        $author = $this->target_user($authorid);
                        \core\session\manager::set_user($author);

                        $messageitemid = file_get_unused_draft_itemid();

                        [$attachmentdraft, $attachmentcount] =
                            $this->create_attachment_draft(
                                (array) ($postplan['assets'] ?? []),
                                $authorid
                            );

                        $postdata = (object) [
                            'discussion' => $discussionid,
                            'parent' => (int) $posttargets[$parentsourceid],
                            'subject' => (string) ($postplan['subject'] ?? ''),
                            'message' => (string) (
                                $postplan['message_html'] ?? ''
                            ),
                            'messageformat' => FORMAT_HTML,
                            'messagetrust' => 0,
                            'itemid' => $messageitemid,
                            'attachments' => $attachmentdraft,
                            'mailnow' => 0,
                            'isprivatereply' => 0,
                        ];

                        $targetpostid = (int) forum_add_new_post(
                            $postdata,
                            $attachmentdraft > 0
                                ? $attachmentdraft
                                : null
                        );

                        if ($targetpostid <= 0) {
                            throw new \coding_exception(
                                'Moodle failed to create Forum reply.'
                            );
                        }

                        $created = $this->source_time(
                            (string) ($postplan['create_date'] ?? ''),
                            time()
                        );
                        $modified = $this->source_time(
                            (string) ($postplan['update_date'] ?? ''),
                            $created
                        );

                        $this->normalise_post_metadata(
                            $targetpostid,
                            $created,
                            $modified
                        );

                        $this->save_mapping(
                            $sourceinstance,
                            $sourcecourse,
                            $forumref . ':post:' . $sourcepostid,
                            $sourcepostid,
                            'forumpost',
                            $targetpostid,
                            (string) ($document['source']['version'] ?? '')
                        );

                        $posttargets[$sourcepostid] = $targetpostid;
                        $createdposts++;
                        $createdattachments += $attachmentcount;
                        $createdmappings++;
                        $threadchanged = true;
                        $writes = true;

                        if ($modified >= $latesttime) {
                            $latesttime = $modified;
                            $latestuserid = $authorid;
                        }
                    }

                    if ($threadchanged) {
                        $DB->update_record(
                            'forum_discussions',
                            (object) [
                                'id' => $discussionid,
                                'timemodified' => $latesttime,
                                'usermodified' => $latestuserid,
                            ]
                        );
                    }
                }

                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                if (!$transaction->is_disposed()) {
                    $transaction->rollback($exception);
                }
                throw $exception;
            }
        } finally {
            if ($originaluser instanceof \stdClass) {
                \core\session\manager::set_user($originaluser);
            }
            if ($originalcourse instanceof \stdClass) {
                $COURSE = $originalcourse;
            }
        }

        $result = $plan;
        $result['mode'] = 'apply';
        $result['apply_implemented'] = true;
        $result['writes_performed'] = $writes;
        $result['apply_counts'] = [
            'discussions_created' => $createddiscussions,
            'discussions_kept' => $keptdiscussions,
            'posts_created' => $createdposts,
            'posts_kept' => $keptposts,
            'attachments_created' => $createdattachments,
            'mappings_created' => $createdmappings,
            'root_mappings_repaired' => $repairedrootmappings,
        ];
        $result['notification_policy'] = [
            'preflight_required' => true,
            'forum_subscription_count' => (int) (
                $plan['target_forum']['explicit_subscription_count'] ?? 0
            ),
            'notification_risk' => false,
            'created_posts_marked_mailed' => true,
        ];
        $result['timestamp_policy'] = [
            'source_created_preserved' => true,
            'source_modified_preserved' => true,
            'normalisation_method' =>
                'FORUM_API_CREATE_THEN_METADATA_UPDATE',
        ];

        return $result;
    }

    private function target_user(int $userid): \stdClass {
        global $DB;

        if ($userid <= 0) {
            throw new \coding_exception('Invalid target Forum author id.');
        }

        return $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0, 'suspended' => 0],
            '*',
            MUST_EXIST
        );
    }

    private function create_attachment_draft(
        array $assets,
        int $userid
    ): array {
        $attachments = array_values(array_filter(
            $assets,
            static fn(array $asset): bool =>
                (string) ($asset['kind'] ?? '') === 'attachment'
        ));

        $media = array_values(array_filter(
            $assets,
            static fn(array $asset): bool =>
                (string) ($asset['kind'] ?? '') === 'media_object'
        ));

        if ($media) {
            throw new \coding_exception(
                'Inline Forum media migration is not implemented in Phase 7.4.'
            );
        }

        if (!$attachments) {
            return [0, 0];
        }

        $draftid = file_get_unused_draft_itemid();
        $context = \context_user::instance($userid);
        $fs = get_file_storage();
        $seen = [];
        $count = 0;

        foreach ($attachments as $asset) {
            $path = $this->resolve_relative_file(
                (string) ($asset['migration_path'] ?? '')
            );
            if ($path === null) {
                throw new \coding_exception(
                    'Validated Forum attachment is missing during apply.'
                );
            }

            $filename = clean_param(
                (string) ($asset['filename'] ?? basename($path)),
                PARAM_FILE
            );
            if ($filename === '' || isset($seen[$filename])) {
                throw new \coding_exception(
                    'Forum attachment filename is empty or duplicated: '
                    . $filename
                );
            }
            $seen[$filename] = true;

            $fs->create_file_from_pathname(
                [
                    'contextid' => (int) $context->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => $draftid,
                    'filepath' => '/',
                    'filename' => $filename,
                    'timecreated' => time(),
                    'timemodified' => time(),
                ],
                $path
            );
            $count++;
        }

        return [$draftid, $count];
    }

    private function normalise_post_metadata(
        int $postid,
        int $created,
        int $modified
    ): void {
        global $DB;

        $DB->update_record(
            'forum_posts',
            (object) [
                'id' => $postid,
                'created' => $created,
                'modified' => $modified,
                'mailed' => FORUM_MAILED_SUCCESS,
                'mailnow' => 0,
            ]
        );
    }

    private function source_time(string $raw, int $fallback): int {
        $raw = trim($raw);
        if ($raw === '') {
            return $fallback;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false || $timestamp <= 0) {
            return $fallback;
        }

        return $timestamp;
    }

    private function get_mapping(
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

    private function save_mapping(
        string $sourceinstance,
        string $sourcecourse,
        string $sourceref,
        string $sourceobj,
        string $targettype,
        int $targetid,
        string $sourceversion
    ): void {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => $targettype,
        ];

        $existing = $DB->get_record(
            'local_iliasmigration_map',
            $conditions
        );

        if ($existing) {
            if ((int) $existing->targetid !== $targetid) {
                throw new \coding_exception(
                    'Persistent Forum contribution mapping conflicts with target id.'
                );
            }
            return;
        }

        $now = time();
        $DB->insert_record(
            'local_iliasmigration_map',
            (object) ($conditions + [
                'sourceversion' => $sourceversion !== ''
                    ? $sourceversion
                    : null,
                'sourceobj' => $sourceobj !== ''
                    ? $sourceobj
                    : null,
                'targetid' => $targetid,
                'status' => 'READY',
                'timecreated' => $now,
                'timemodified' => $now,
            ])
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

<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only Phase 7.5 Blog author resolver.
 *
 * Audits existing Phase 6.5.7 mod_data records and compares the preserved
 * ILIAS source_author field with persistent Phase 7.1 GLOBAL user mappings.
 * No Moodle records, contents, files or mappings are written.
 */
final class phase7_blog_author_resolver {
    public function resolve(int $courseid, string $blogref): array {
        global $DB;

        $blogref = trim($blogref);
        if ($courseid <= 0 || $blogref === '') {
            throw new \coding_exception(
                'A target course id and Blog source ref_id are required.'
            );
        }

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,shortname,fullname',
            MUST_EXIST
        );

        $activitymappings = $DB->get_records(
            'local_iliasmigration_map',
            [
                'sourcelms' => 'ILIAS',
                'sourceref' => $blogref,
                'targettype' => 'data',
            ],
            'id ASC'
        );

        if (count($activitymappings) !== 1) {
            throw new \coding_exception(
                'Blog activity mapping must be unique for ref_id '
                . $blogref
                . '; found '
                . count($activitymappings)
                . '.'
            );
        }

        $activitymapping = reset($activitymappings);
        $cmid = (int) ($activitymapping->targetid ?? 0);

        $cm = get_coursemodule_from_id(
            'data',
            $cmid,
            $courseid,
            false,
            MUST_EXIST
        );

        $data = $DB->get_record(
            'data',
            [
                'id' => (int) $cm->instance,
                'course' => $courseid,
            ],
            'id,course,name',
            MUST_EXIST
        );

        $fields = $DB->get_records(
            'data_fields',
            ['dataid' => (int) $data->id],
            'id ASC',
            'id,name,type'
        );

        $fieldbyname = [];
        foreach ($fields as $field) {
            $fieldbyname[(string) $field->name] = $field;
        }

        foreach (['source_posting_id', 'source_author'] as $required) {
            if (!isset($fieldbyname[$required])) {
                throw new \coding_exception(
                    'Blog Database field is missing: ' . $required
                );
            }
        }

        $sourcepostingfield = (int) $fieldbyname['source_posting_id']->id;
        $sourceauthorfield = (int) $fieldbyname['source_author']->id;

        $records = $DB->get_records(
            'data_records',
            ['dataid' => (int) $data->id],
            'id ASC',
            'id,dataid,userid,groupid,timecreated,timemodified,approved'
        );

        $authors = [];
        $entries = [];
        $recordmappingissues = 0;
        $authorissues = 0;
        $ownerchangesrequired = 0;
        $ownermatches = 0;

        foreach ($records as $record) {
            $postingcontent = $DB->get_record(
                'data_content',
                [
                    'recordid' => (int) $record->id,
                    'fieldid' => $sourcepostingfield,
                ],
                'id,content'
            );
            $authorcontent = $DB->get_record(
                'data_content',
                [
                    'recordid' => (int) $record->id,
                    'fieldid' => $sourceauthorfield,
                ],
                'id,content'
            );

            $postingid = trim((string) (
                $postingcontent->content ?? ''
            ));
            $rawauthor = trim((string) (
                $authorcontent->content ?? ''
            ));

            if ($postingid === '') {
                $recordmappingissues++;
            }

            $recordmapping = null;
            if ($postingid !== '') {
                $recordmapping = $DB->get_record(
                    'local_iliasmigration_map',
                    [
                        'sourcelms' => 'ILIAS',
                        'sourceinstance' => (string) $activitymapping->sourceinstance,
                        'sourcecourse' => (string) $activitymapping->sourcecourse,
                        'sourceref' => $blogref . ':' . $postingid,
                        'targettype' => 'data_record',
                    ]
                );
            }

            $recordmappingvalid = $recordmapping
                && (int) $recordmapping->targetid === (int) $record->id;
            if (!$recordmappingvalid) {
                $recordmappingissues++;
            }

            $sourceuserid = null;
            $authorformatvalid = preg_match(
                '/^il_0_usr_([1-9][0-9]*)$/',
                $rawauthor,
                $matches
            ) === 1;

            if ($authorformatvalid) {
                $sourceuserid = (string) $matches[1];
            }

            if (!$authorformatvalid || $sourceuserid === null) {
                $authorissues++;
            }

            $authorresolution = [
                'source_author_raw' => $rawauthor,
                'source_user_id' => $sourceuserid,
                'source_author_format_valid' => $authorformatvalid,
                'mapping_found' => false,
                'mapping_resolution' => 'INVALID_SOURCE_AUTHOR',
                'mapping_candidates' => 0,
                'target_user_id' => null,
                'target_username' => null,
                'target_user_exists' => false,
                'target_user_suspended' => null,
                'ready' => false,
            ];

            if ($sourceuserid !== null) {
                if (!isset($authors[$sourceuserid])) {
                    $authors[$sourceuserid] = $this->resolve_user_mapping(
                        $sourceuserid
                    );
                }

                $authorresolution = array_merge(
                    $authorresolution,
                    $authors[$sourceuserid]
                );

                if (empty($authorresolution['ready'])) {
                    $authorissues++;
                }
            }

            $targetuserid = (int) (
                $authorresolution['target_user_id'] ?? 0
            );
            $ownermatch = $targetuserid > 0
                && (int) $record->userid === $targetuserid;

            if ($ownermatch) {
                $ownermatches++;
            } else if (!empty($authorresolution['ready'])) {
                $ownerchangesrequired++;
            }

            $entries[$postingid !== '' ? $postingid : 'record:' . $record->id] = [
                'source_posting_id' => $postingid,
                'source_author_raw' => $rawauthor,
                'source_user_id' => $sourceuserid,
                'record_id' => (int) $record->id,
                'current_owner_user_id' => (int) $record->userid,
                'expected_owner_user_id' => $targetuserid > 0
                    ? $targetuserid
                    : null,
                'owner_matches_mapping' => $ownermatch,
                'record_mapping_found' => (bool) $recordmapping,
                'record_mapping_valid' => $recordmappingvalid,
                'record_mapping_id' => $recordmapping
                    ? (int) $recordmapping->id
                    : null,
                'record_timecreated' => (int) $record->timecreated,
                'record_timemodified' => (int) $record->timemodified,
                'approved' => (int) $record->approved,
                'author_resolution' => $authorresolution,
                'action' => !$recordmappingvalid
                    ? 'BLOCKED'
                    : (empty($authorresolution['ready'])
                        ? 'BLOCKED'
                        : ($ownermatch ? 'KEEP' : 'REASSIGN_OWNER')),
            ];
        }

        $context = \context_module::instance($cmid);
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $context->id,
            'mod_data',
            'content',
            false,
            'itemid ASC, filename ASC',
            false
        );

        $assetrows = [];
        foreach ($files as $file) {
            $assetrows[] = [
                'itemid' => (int) $file->get_itemid(),
                'filename' => $file->get_filename(),
                'size' => (int) $file->get_filesize(),
                'contenthash' => $file->get_contenthash(),
            ];
        }

        $blocked = $recordmappingissues > 0 || $authorissues > 0;

        return [
            'phase' => '7.5',
            'mode' => 'dry-run',
            'writes_performed' => false,
            'source' => [
                'lms' => 'ILIAS',
                'blog_ref_id' => $blogref,
                'sourceinstance' => (string) $activitymapping->sourceinstance,
                'sourcecourse' => (string) $activitymapping->sourcecourse,
                'sourceobj' => (string) ($activitymapping->sourceobj ?? ''),
            ],
            'target_course' => [
                'id' => (int) $course->id,
                'shortname' => (string) $course->shortname,
                'fullname' => (string) $course->fullname,
            ],
            'target_blog' => [
                'cmid' => $cmid,
                'instance_id' => (int) $data->id,
                'name' => (string) $data->name,
                'activity_mapping_id' => (int) $activitymapping->id,
            ],
            'policy' => [
                'source_author_format' => 'il_0_usr_<numeric_id>',
                'persistent_global_user_mapping_required' => true,
                'fallback_by_login_email_name' => false,
                'existing_records_reused' => true,
                'content_assets_modified_by_dry_run' => false,
            ],
            'counts' => [
                'records' => count($records),
                'record_mapping_issues' => $recordmappingissues,
                'source_authors' => count($authors),
                'author_mapping_issues' => $authorissues,
                'owner_matches' => $ownermatches,
                'owner_changes_required' => $ownerchangesrequired,
                'content_files' => count($assetrows),
                'blocked_items' => $blocked
                    ? $recordmappingissues + $authorissues
                    : 0,
            ],
            'authors' => $authors,
            'entries' => $entries,
            'content_files' => $assetrows,
            'ready_for_apply' => !$blocked,
            'apply_required' => !$blocked && $ownerchangesrequired > 0,
            'apply_implemented' => false,
            'apply_reason' => $blocked
                ? 'IDENTITY_OR_RECORD_MAPPING_BLOCKED'
                : ($ownerchangesrequired > 0
                    ? 'BLOG_RECORD_OWNER_DIFFERS_FROM_RESOLVED_SOURCE_AUTHOR'
                    : 'BLOG_RECORD_OWNERS_ALREADY_MATCH_SOURCE_AUTHORS'),
        ];
    }

    private function resolve_user_mapping(string $sourceuserid): array {
        global $DB;

        $mappings = $DB->get_records(
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

        if (count($mappings) !== 1) {
            return [
                'mapping_found' => false,
                'mapping_resolution' => count($mappings) > 1
                    ? 'AMBIGUOUS_GLOBAL_MAPPING'
                    : 'MISSING_GLOBAL_MAPPING',
                'mapping_candidates' => count($mappings),
                'target_user_id' => null,
                'target_username' => null,
                'target_user_exists' => false,
                'target_user_suspended' => null,
                'ready' => false,
            ];
        }

        $mapping = reset($mappings);
        $user = null;

        if ((int) $mapping->targetid > 0) {
            $user = $DB->get_record(
                'user',
                [
                    'id' => (int) $mapping->targetid,
                    'deleted' => 0,
                ],
                'id,username,suspended'
            );
        }

        $ready = $user && empty($user->suspended);

        return [
            'mapping_found' => true,
            'mapping_resolution' => 'UNIQUE_GLOBAL_MAPPING',
            'mapping_candidates' => 1,
            'mapping_id' => (int) $mapping->id,
            'mapping_sourceinstance' => (string) $mapping->sourceinstance,
            'target_user_id' => (int) $mapping->targetid,
            'target_username' => $user
                ? (string) $user->username
                : null,
            'target_user_exists' => (bool) $user,
            'target_user_suspended' => $user
                ? !empty($user->suspended)
                : null,
            'ready' => (bool) $ready,
        ];
    }
}

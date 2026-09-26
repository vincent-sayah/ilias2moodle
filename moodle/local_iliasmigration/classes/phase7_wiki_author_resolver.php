<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Phase 7.6 read-only Wiki current-page author/timestamp reconciliation.
 */
final class phase7_wiki_author_resolver {
    public function resolve(
        array $source,
        int $courseid,
        string $wikiref
    ): array {
        global $DB;

        $wikiref = trim($wikiref);
        if ($courseid <= 0 || $wikiref === '') {
            throw new \coding_exception(
                'A target course id and Wiki source ref_id are required.'
            );
        }

        if ((string) ($source['phase'] ?? '') !== '7.6'
                || (string) ($source['extractor'] ?? '')
                    !== 'wiki_current_page_authors') {
            throw new \coding_exception(
                'Unsupported Phase 7.6 Wiki author inventory.'
            );
        }

        if ((string) ($source['wiki']['ref_id'] ?? '') !== $wikiref) {
            throw new \coding_exception(
                'Wiki author inventory ref_id does not match --wiki-ref.'
            );
        }

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,shortname,fullname',
            MUST_EXIST
        );

        $wikimappings = $DB->get_records(
            'local_iliasmigration_map',
            [
                'sourcelms' => 'ILIAS',
                'sourceref' => $wikiref,
                'targettype' => 'wiki',
            ],
            'id ASC'
        );

        if (count($wikimappings) !== 1) {
            throw new \coding_exception(
                'Wiki activity mapping must be unique for ref_id '
                . $wikiref
                . '; found '
                . count($wikimappings)
                . '.'
            );
        }

        $wikimapping = reset($wikimappings);
        $cmid = (int) ($wikimapping->targetid ?? 0);

        $cm = get_coursemodule_from_id(
            'wiki',
            $cmid,
            $courseid,
            false,
            MUST_EXIST
        );

        $wiki = $DB->get_record(
            'wiki',
            [
                'id' => (int) $cm->instance,
                'course' => $courseid,
            ],
            'id,course,name,wikimode,firstpagetitle,defaultformat',
            MUST_EXIST
        );

        $subwiki = $DB->get_record(
            'wiki_subwikis',
            [
                'wikiid' => (int) $wiki->id,
                'groupid' => 0,
                'userid' => 0,
            ],
            'id,wikiid,groupid,userid'
        );

        if (!$subwiki) {
            throw new \coding_exception(
                'Collaborative Wiki subwiki could not be resolved.'
            );
        }

        $pagesource = is_array($source['pages'] ?? null)
            ? $source['pages']
            : [];

        if (!$pagesource) {
            throw new \coding_exception(
                'Wiki author inventory contains no pages.'
            );
        }

        $authors = [];
        $pages = [];
        $mappingissues = 0;
        $authorissues = 0;
        $currentmetadatakeep = 0;
        $currentmetadatareconcile = 0;
        $historyonly = 0;

        foreach ($pagesource as $sourcepageid => $sourcepage) {
            if (!is_array($sourcepage)) {
                $mappingissues++;
                continue;
            }

            $sourcepageid = (string) (
                $sourcepage['source_page_id'] ?? $sourcepageid
            );
            $title = (string) ($sourcepage['title'] ?? '');
            $mappingref = $wikiref . ':page:' . $sourcepageid;

            $pagemapping = $this->unique_mapping(
                $mappingref,
                'wiki_page'
            );

            $targetpage = null;
            if ($pagemapping) {
                $targetpage = $DB->get_record(
                    'wiki_pages',
                    [
                        'id' => (int) $pagemapping->targetid,
                        'subwikiid' => (int) $subwiki->id,
                    ],
                    'id,subwikiid,title,timecreated,timemodified,userid'
                );
            }

            $mappingvalid = $pagemapping
                && $targetpage
                && (string) $targetpage->title === $title;

            if (!$mappingvalid) {
                $mappingissues++;
            }

            $creatorid = (string) (
                $sourcepage['create_user_id'] ?? ''
            );
            $editorid = (string) (
                $sourcepage['last_change_user_id'] ?? ''
            );

            $creatorresolution = $this->resolve_author(
                $creatorid,
                $authors
            );
            $editorresolution = $this->resolve_author(
                $editorid,
                $authors
            );

            if (empty($creatorresolution['ready'])) {
                $authorissues++;
            }
            if (empty($editorresolution['ready'])) {
                $authorissues++;
            }

            $createdts = $this->source_timestamp(
                (string) ($sourcepage['created'] ?? '')
            );
            $modifiedts = $this->source_timestamp(
                (string) ($sourcepage['last_change'] ?? '')
            );

            if ($createdts === null || $modifiedts === null) {
                $mappingissues++;
            }

            $versions = [];
            $currentversion = null;
            if ($targetpage) {
                $versions = $DB->get_records(
                    'wiki_versions',
                    ['pageid' => (int) $targetpage->id],
                    'version ASC',
                    'id,pageid,version,timecreated,userid,contentformat'
                );
                if ($versions) {
                    $currentversion = end($versions);
                }
            }

            $expectededitor = (int) (
                $editorresolution['target_user_id'] ?? 0
            );

            $pageownermatch = $targetpage
                && $expectededitor > 0
                && (int) $targetpage->userid === $expectededitor;
            $versionownermatch = $currentversion
                && $expectededitor > 0
                && (int) $currentversion->userid === $expectededitor;

            $pagecreatedmatch = $targetpage
                && $createdts !== null
                && (int) $targetpage->timecreated === $createdts;
            $pagemodifiedmatch = $targetpage
                && $modifiedts !== null
                && (int) $targetpage->timemodified === $modifiedts;
            $versionmodifiedmatch = $currentversion
                && $modifiedts !== null
                && (int) $currentversion->timecreated === $modifiedts;

            $ready = $mappingvalid
                && !empty($creatorresolution['ready'])
                && !empty($editorresolution['ready'])
                && $createdts !== null
                && $modifiedts !== null
                && $currentversion;

            $currentmetadatamatch = $ready
                && $pageownermatch
                && $versionownermatch
                && $pagecreatedmatch
                && $pagemodifiedmatch
                && $versionmodifiedmatch;

            $action = 'BLOCKED';
            if ($ready) {
                if ($currentmetadatamatch) {
                    $action = 'KEEP';
                    $currentmetadatakeep++;
                } else {
                    $action = 'RECONCILE_CURRENT_METADATA';
                    $currentmetadatareconcile++;
                }
            }

            $historyonly++;

            $pages[$sourcepageid] = [
                'source_page_id' => $sourcepageid,
                'title' => $title,
                'mapping_ref' => $mappingref,
                'mapping_found' => (bool) $pagemapping,
                'mapping_valid' => $mappingvalid,
                'target_page_id' => $targetpage
                    ? (int) $targetpage->id
                    : null,
                'source_creator_user_id' => $creatorid !== ''
                    ? $creatorid
                    : null,
                'source_creator' => $creatorresolution,
                'source_created' => (string) (
                    $sourcepage['created'] ?? ''
                ),
                'source_created_timestamp' => $createdts,
                'source_last_editor_user_id' => $editorid !== ''
                    ? $editorid
                    : null,
                'source_last_editor' => $editorresolution,
                'source_last_change' => (string) (
                    $sourcepage['last_change'] ?? ''
                ),
                'source_last_change_timestamp' => $modifiedts,
                'moodle_page' => $targetpage
                    ? [
                        'userid' => (int) $targetpage->userid,
                        'timecreated' => (int) $targetpage->timecreated,
                        'timemodified' => (int) $targetpage->timemodified,
                    ]
                    : null,
                'moodle_versions' => array_values(array_map(
                    static fn(\stdClass $version): array => [
                        'id' => (int) $version->id,
                        'version' => (int) $version->version,
                        'userid' => (int) $version->userid,
                        'timecreated' => (int) $version->timecreated,
                        'contentformat' => (string) $version->contentformat,
                    ],
                    $versions
                )),
                'current_version' => $currentversion
                    ? [
                        'id' => (int) $currentversion->id,
                        'version' => (int) $currentversion->version,
                        'userid' => (int) $currentversion->userid,
                        'timecreated' => (int) $currentversion->timecreated,
                    ]
                    : null,
                'checks' => [
                    'page_last_editor_matches' => $pageownermatch,
                    'current_version_author_matches' => $versionownermatch,
                    'page_created_matches' => $pagecreatedmatch,
                    'page_modified_matches' => $pagemodifiedmatch,
                    'current_version_time_matches' =>
                        $versionmodifiedmatch,
                ],
                'current_metadata_classification' => $ready
                    ? 'MIGRATE'
                    : 'UNSUPPORTED',
                'history_classification' => 'HISTORY_ONLY',
                'history_reason' =>
                    'FULL_ILIAS_WIKI_REVISION_HISTORY_NOT_EXTRACTED',
                'action' => $action,
            ];
        }

        $blocked = $mappingissues + $authorissues;

        return [
            'phase' => '7.6',
            'mode' => 'dry-run',
            'writes_performed' => false,
            'source' => [
                'lms' => 'ILIAS',
                'client_id' => (string) (
                    $source['source']['client_id'] ?? ''
                ),
                'course_object_id' => (string) (
                    $source['course']['object_id'] ?? ''
                ),
                'course_ref_id' => (string) (
                    $source['course']['ref_id'] ?? ''
                ),
                'wiki_object_id' => (string) (
                    $source['wiki']['object_id'] ?? ''
                ),
                'wiki_ref_id' => $wikiref,
            ],
            'target_course' => [
                'id' => (int) $course->id,
                'shortname' => (string) $course->shortname,
                'fullname' => (string) $course->fullname,
            ],
            'target_wiki' => [
                'cmid' => $cmid,
                'instance_id' => (int) $wiki->id,
                'subwiki_id' => (int) $subwiki->id,
                'name' => (string) $wiki->name,
                'activity_mapping_id' => (int) $wikimapping->id,
            ],
            'policy' => [
                'full_revision_history' => 'HISTORY_ONLY',
                'current_page_metadata' => 'MIGRATE_WHEN_SAFE',
                'technical_moodle_version_zero_preserved' => true,
                'fallback_by_login_email_name' => false,
                'persistent_global_user_mapping_required' => true,
                'dry_run_writes' => false,
            ],
            'counts' => [
                'pages' => count($pages),
                'page_mapping_issues' => $mappingissues,
                'author_mapping_issues' => $authorissues,
                'current_metadata_keep' => $currentmetadatakeep,
                'current_metadata_reconcile' =>
                    $currentmetadatareconcile,
                'history_only' => $historyonly,
                'blocked_items' => $blocked,
            ],
            'authors' => $authors,
            'pages' => $pages,
            'ready_for_apply' => $blocked === 0,
            'apply_required' => $blocked === 0
                && $currentmetadatareconcile > 0,
            'apply_implemented' => false,
            'apply_reason' => $blocked > 0
                ? 'WIKI_AUTHOR_OR_PAGE_MAPPING_BLOCKED'
                : ($currentmetadatareconcile > 0
                    ? 'CURRENT_PAGE_METADATA_DIFFERS_FROM_SOURCE'
                    : 'CURRENT_PAGE_METADATA_ALREADY_MATCHES_SOURCE'),
        ];
    }

    private function unique_mapping(
        string $sourceref,
        string $targettype
    ): \stdClass|false {
        global $DB;

        $mappings = $DB->get_records(
            'local_iliasmigration_map',
            [
                'sourcelms' => 'ILIAS',
                'sourceref' => $sourceref,
                'targettype' => $targettype,
            ],
            'id ASC'
        );

        if (count($mappings) !== 1) {
            return false;
        }

        return reset($mappings);
    }

    private function resolve_author(
        string $sourceuserid,
        array &$authors
    ): array {
        global $DB;

        $sourceuserid = trim($sourceuserid);
        if ($sourceuserid === '') {
            return [
                'source_user_id' => null,
                'mapping_found' => false,
                'mapping_resolution' => 'NO_SOURCE_USER',
                'target_user_id' => null,
                'target_username' => null,
                'ready' => false,
            ];
        }

        if (isset($authors[$sourceuserid])) {
            return $authors[$sourceuserid];
        }

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
            $authors[$sourceuserid] = [
                'source_user_id' => $sourceuserid,
                'mapping_found' => false,
                'mapping_resolution' => count($mappings) > 1
                    ? 'AMBIGUOUS_GLOBAL_MAPPING'
                    : 'MISSING_GLOBAL_MAPPING',
                'mapping_candidates' => count($mappings),
                'target_user_id' => null,
                'target_username' => null,
                'ready' => false,
            ];
            return $authors[$sourceuserid];
        }

        $mapping = reset($mappings);
        $user = $DB->get_record(
            'user',
            [
                'id' => (int) $mapping->targetid,
                'deleted' => 0,
            ],
            'id,username,suspended'
        );

        $ready = $user && empty($user->suspended);

        $authors[$sourceuserid] = [
            'source_user_id' => $sourceuserid,
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

        return $authors[$sourceuserid];
    }

    private function source_timestamp(string $raw): ?int {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false || $timestamp <= 0) {
            return null;
        }

        return $timestamp;
    }
}

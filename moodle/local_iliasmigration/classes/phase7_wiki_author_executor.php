<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Phase 7.6 apply for Wiki current-page metadata.
 *
 * This executor intentionally does not call wiki_save_page(): that API creates
 * a new Moodle revision and would fabricate a version which never existed in
 * the source migration. Only existing page/current-version metadata is
 * reconciled.
 */
final class phase7_wiki_author_executor {
    public function execute(
        array $source,
        int $courseid,
        string $wikiref
    ): array {
        global $DB;

        $resolver = new phase7_wiki_author_resolver();
        $plan = $resolver->resolve(
            $source,
            $courseid,
            $wikiref
        );

        if (empty($plan['ready_for_apply'])) {
            throw new \coding_exception(
                'Phase 7.6 Wiki author plan is not ready for apply.'
            );
        }

        $pagesupdated = 0;
        $versionsupdated = 0;
        $pageskept = 0;
        $writes = false;

        $transaction = $DB->start_delegated_transaction();

        try {
            foreach ($plan['pages'] as $sourcepageid => $pageplan) {
                $action = (string) ($pageplan['action'] ?? '');

                if ($action === 'KEEP') {
                    $pageskept++;
                    continue;
                }

                if ($action !== 'RECONCILE_CURRENT_METADATA') {
                    throw new \coding_exception(
                        'Unexpected Wiki metadata action for source page '
                        . $sourcepageid
                        . ': '
                        . $action
                    );
                }

                if (empty(
                    $pageplan['technical_history']
                        ['safe_for_metadata_reconciliation']
                )) {
                    throw new \coding_exception(
                        'Wiki technical history is not safe for reconciliation: '
                        . $sourcepageid
                    );
                }

                $targetpageid = (int) (
                    $pageplan['target_page_id'] ?? 0
                );
                $currentversionid = (int) (
                    $pageplan['current_version']['id'] ?? 0
                );
                $currentversionnumber = (int) (
                    $pageplan['current_version']['version'] ?? -1
                );
                $targetuserid = (int) (
                    $pageplan['source_last_editor']['target_user_id']
                        ?? 0
                );
                $created = (int) (
                    $pageplan['source_created_timestamp'] ?? 0
                );
                $modified = (int) (
                    $pageplan['source_last_change_timestamp'] ?? 0
                );

                if ($targetpageid <= 0
                        || $currentversionid <= 0
                        || $currentversionnumber !== 1
                        || $targetuserid <= 0
                        || $created <= 0
                        || $modified <= 0) {
                    throw new \coding_exception(
                        'Wiki reconciliation metadata is incomplete for source page '
                        . $sourcepageid
                    );
                }

                $page = $DB->get_record(
                    'wiki_pages',
                    ['id' => $targetpageid],
                    'id,subwikiid,title,timecreated,timemodified,userid',
                    MUST_EXIST
                );

                if ((string) $page->title
                        !== (string) ($pageplan['title'] ?? '')) {
                    throw new \coding_exception(
                        'Wiki page title changed after dry-run for source page '
                        . $sourcepageid
                    );
                }

                $versions = $DB->get_records(
                    'wiki_versions',
                    ['pageid' => $targetpageid],
                    'version ASC',
                    'id,pageid,version,timecreated,userid'
                );

                $numbers = array_values(array_map(
                    static fn(\stdClass $version): int =>
                        (int) $version->version,
                    $versions
                ));
                sort($numbers, SORT_NUMERIC);

                if (count($numbers) !== 2 || $numbers !== [0, 1]) {
                    throw new \coding_exception(
                        'Wiki version history changed after dry-run for source page '
                        . $sourcepageid
                    );
                }

                $current = end($versions);
                if (!$current
                        || (int) $current->id !== $currentversionid
                        || (int) $current->version !== 1) {
                    throw new \coding_exception(
                        'Wiki current version changed after dry-run for source page '
                        . $sourcepageid
                    );
                }

                $pagechanged = (int) $page->userid !== $targetuserid
                    || (int) $page->timecreated !== $created
                    || (int) $page->timemodified !== $modified;

                if ($pagechanged) {
                    $DB->update_record(
                        'wiki_pages',
                        (object) [
                            'id' => $targetpageid,
                            'userid' => $targetuserid,
                            'timecreated' => $created,
                            'timemodified' => $modified,
                        ]
                    );
                    $pagesupdated++;
                    $writes = true;
                }

                $versionchanged = (int) $current->userid !== $targetuserid
                    || (int) $current->timecreated !== $modified;

                if ($versionchanged) {
                    $DB->update_record(
                        'wiki_versions',
                        (object) [
                            'id' => $currentversionid,
                            'userid' => $targetuserid,
                            'timecreated' => $modified,
                        ]
                    );
                    $versionsupdated++;
                    $writes = true;
                }
            }

            $verification = $resolver->resolve(
                $source,
                $courseid,
                $wikiref
            );

            if (empty($verification['ready_for_apply'])
                    || (int) (
                        $verification['counts']
                            ['current_metadata_reconcile'] ?? -1
                    ) !== 0
                    || (int) (
                        $verification['counts']
                            ['current_metadata_keep'] ?? -1
                    ) !== count($verification['pages'])) {
                throw new \coding_exception(
                    'Phase 7.6 Wiki metadata verification failed before commit.'
                );
            }

            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($exception);
            }
            throw $exception;
        }

        $result = $verification;
        $result['mode'] = 'apply';
        $result['apply_implemented'] = true;
        $result['writes_performed'] = $writes;
        $result['apply_counts'] = [
            'pages_updated' => $pagesupdated,
            'current_versions_updated' => $versionsupdated,
            'pages_kept_without_change' => $pageskept,
            'new_pages_created' => 0,
            'new_versions_created' => 0,
            'version_zero_modified' => 0,
            'content_modified' => 0,
            'assets_modified' => 0,
        ];
        $result['apply_policy'] = [
            'wiki_save_page_called' => false,
            'direct_metadata_reconciliation' => true,
            'technical_version_zero_preserved' => true,
            'full_revision_history' => 'HISTORY_ONLY',
            'creation_author_identity' => 'HISTORY_ONLY',
            'verification_before_commit' => true,
        ];

        return $result;
    }
}

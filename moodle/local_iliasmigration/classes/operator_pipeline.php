<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Operator-console pipeline over the validated migration executors.
 *
 * Failures are isolated at object-family granularity in this first version.
 */
final class operator_pipeline {
    /**
     * Ordered pipeline definition.
     *
     * @return array<string, array{label:string,types:array}>
     */
    public static function step_definitions(): array {
        return [
            'structure' => [
                'label' => get_string('step_structure', 'local_iliasmigration'),
                'types' => [],
            ],
            'phase7_identities' => [
                'label' => get_string(
                    'step_phase7_identities',
                    'local_iliasmigration'
                ),
                'types' => [],
            ],
            'resources' => [
                'label' => get_string('step_resources', 'local_iliasmigration'),
                'types' => ['file', 'url', 'html_module'],
            ],
            'scorm' => [
                'label' => get_string('step_scorm', 'local_iliasmigration'),
                'types' => ['scorm'],
            ],
            'learning_modules' => [
                'label' => get_string('step_learningmodules', 'local_iliasmigration'),
                'types' => ['learning_module'],
            ],
            'questions_quiz' => [
                'label' => get_string('step_questionsquiz', 'local_iliasmigration'),
                'types' => ['question_pool', 'test'],
            ],
            'content_pages' => [
                'label' => get_string('step_contentpages', 'local_iliasmigration'),
                'types' => ['content_page'],
            ],
            'glossaries' => [
                'label' => get_string('step_glossaries', 'local_iliasmigration'),
                'types' => ['glossary'],
            ],
            'wikis' => [
                'label' => get_string('step_wikis', 'local_iliasmigration'),
                'types' => ['wiki'],
            ],
            'exercises' => [
                'label' => get_string('step_exercises', 'local_iliasmigration'),
                'types' => ['exercise'],
            ],
            'forums' => [
                'label' => get_string('step_forums', 'local_iliasmigration'),
                'types' => ['forum'],
            ],
            'mediacasts' => [
                'label' => get_string('step_mediacasts', 'local_iliasmigration'),
                'types' => ['mediacast'],
            ],
            'blogs' => [
                'label' => get_string('step_blogs', 'local_iliasmigration'),
                'types' => ['blog'],
            ],
            'media_pools' => [
                'label' => get_string('step_mediapools', 'local_iliasmigration'),
                'types' => ['media_pool'],
            ],
            'item_groups' => [
                'label' => get_string('step_itemgroups', 'local_iliasmigration'),
                'types' => ['itgr'],
            ],
        ];
    }

    /**
     * Inspect normalized source types without writing Moodle data.
     *
     * @return array{document:array,types:array}
     */
    public function inspect_source(string $migrationjson): array {
        $document = (new migration_reader())->read($migrationjson);
        $types = [];

        $this->collect_types(
            is_array($document['course']['items'] ?? null)
                ? $document['course']['items']
                : [],
            $types
        );

        ksort($types);

        return [
            'document' => $document,
            'types' => array_keys($types),
        ];
    }

    public function is_applicable(string $stepkey, array $types): bool {
        $definitions = self::step_definitions();

        if (!isset($definitions[$stepkey])) {
            throw new \coding_exception(
                'Unknown operator pipeline step: ' . $stepkey
            );
        }

        $required = $definitions[$stepkey]['types'];
        if (!$required) {
            return true;
        }

        return (bool) array_intersect($required, $types);
    }

    /**
     * Execute one guarded object-family step.
     */
    public function execute_step(
        string $stepkey,
        string $migrationjson,
        int $categoryid,
        string $categorypath = ''
    ): array {
        $inspection = $this->inspect_source($migrationjson);
        $document = $inspection['document'];

        if (!$this->is_applicable($stepkey, $inspection['types'])) {
            return [
                'operator_status' => 'SKIPPED_NOT_APPLICABLE',
                'writes_performed' => false,
                'reason' => 'No matching source object exists in migration.json.',
            ];
        }

        if ($stepkey !== 'structure' && $categoryid <= 0) {
            throw new \coding_exception(
                'The Moodle target category is unresolved; the structure step must succeed first.'
            );
        }

        $importer = new importer();

        return match ($stepkey) {
            'structure' => $importer->import(
                $migrationjson,
                $categoryid,
                false,
                2,
                $categoryid > 0 ? '' : $categorypath
            ),
            'phase7_identities' => $this->execute_phase7_identities(
                $document,
                $migrationjson
            ),
            'resources' => $importer->import(
                $migrationjson,
                $categoryid,
                false,
                3
            ),
            'scorm' => $importer->import(
                $migrationjson,
                $categoryid,
                false,
                4
            ),
            'learning_modules' => $importer->import(
                $migrationjson,
                $categoryid,
                false,
                5
            ),
            'questions_quiz' => $importer->import(
                $migrationjson,
                $categoryid,
                false,
                6
            ),
            'content_pages' => $importer->import(
                $migrationjson,
                $categoryid,
                false,
                65
            ),
            'glossaries' => $this->execute_glossaries(
                $document,
                $migrationjson,
                $categoryid
            ),
            'wikis' => (
                new phase65_wiki_executor($migrationjson)
            )->execute($document, $categoryid),
            'exercises' => (
                new phase65_exercise_executor($migrationjson)
            )->execute($document, $categoryid),
            'forums' => (
                new phase65_forum_executor($migrationjson)
            )->execute($document, $categoryid),
            'mediacasts' => (
                new phase65_mediacast_executor($migrationjson)
            )->execute($document, $categoryid),
            'blogs' => (
                new phase65_blog_executor($migrationjson)
            )->execute($document, $categoryid),
            'media_pools' => (
                new phase65_media_pool_executor($migrationjson)
            )->execute($document, $categoryid),
            'item_groups' => (
                new phase65_item_group_executor($migrationjson)
            )->execute($document, $categoryid),
            default => throw new \coding_exception(
                'Unknown operator pipeline step: ' . $stepkey
            ),
        };
    }

    /**
     * Compact report safe for persistent storage.
     */
    public function summarize_result(string $stepkey, array $result): array {
        $actions = [];
        $kinds = [];
        $operations = $this->operations_for_step(
            $stepkey,
            (array) ($result['operations'] ?? [])
        );

        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $action = (string) ($operation['action'] ?? 'UNKNOWN');
            $kind = (string) ($operation['kind'] ?? 'unknown');
            $actions[$action] = ($actions[$action] ?? 0) + 1;
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        }

        $warningcodes = [];
        foreach ((array) ($result['warnings'] ?? []) as $warning) {
            if (is_array($warning)) {
                $warningcodes[] = (string) ($warning['code'] ?? 'WARNING');
            } else if (is_string($warning)) {
                $warningcodes[] = 'WARNING';
            }
        }

        $phase7summary = [];
        if ($stepkey === 'phase7_identities') {
            $phase7summary = [
                'identity_counts' => $result['identity_counts']
                    ?? null,
                'account_creation_counts' => $result[
                    'account_creation_counts'
                ] ?? null,
                'membership_counts' => $result[
                    'membership_counts'
                ] ?? null,
                'created_user_count' => (int) (
                    $result['created_user_count']
                    ?? 0
                ),
                'reused_user_count' => (int) (
                    $result['reused_user_count']
                    ?? 0
                ),
                'enrolled_membership_count' => (int) (
                    $result['enrolled_membership_count']
                    ?? 0
                ),
                'updated_membership_count' => (int) (
                    $result['updated_membership_count']
                    ?? 0
                ),
                'ready_for_apply' => $result[
                    'ready_for_apply'
                ] ?? null,
            ];
        }

        return [
            'mode' => $result['mode'] ?? null,
            'phase' => $result['phase'] ?? null,
            'operator_status' => $result['operator_status'] ?? null,
            'writes_performed' => (bool) ($result['writes_performed'] ?? false),
            'ready' => $result['ready'] ?? null,
            'course_target_id' => $result['course']['target_id']
                ?? $result['target_course']['id']
                ?? null,
            'operation_count' => count($operations),
            'actions' => $actions,
            'kinds' => $kinds,
            'warning_count' => count($warningcodes),
            'warning_codes' => array_values(array_unique($warningcodes)),
            'reason' => $result['reason'] ?? null,
        ] + $phase7summary;
    }

    /**
     * Keep only the plan operations that belong to one operator step.
     *
     * Executors often return the complete plan even though they only execute
     * one family. Filtering here keeps the operator report truthful.
     *
     * @param array<int, mixed> $operations
     * @return array<int, array>
     */
    private function operations_for_step(
        string $stepkey,
        array $operations
    ): array {
        $definitions = self::step_definitions();

        if (!isset($definitions[$stepkey])) {
            throw new \coding_exception(
                'Unknown operator pipeline step: ' . $stepkey
            );
        }

        $allowedkinds = $stepkey === 'structure'
            ? ['course', 'section', 'subsection']
            : $definitions[$stepkey]['types'];

        return array_values(array_filter(
            $operations,
            static function($operation) use ($allowedkinds): bool {
                if (!is_array($operation)) {
                    return false;
                }

                return in_array(
                    (string) ($operation['kind'] ?? ''),
                    $allowedkinds,
                    true
                );
            }
        ));
    }

    public function extract_category_id(array $result): int {
        global $DB;

        $candidates = [
            $result['moodle']['category']['id'] ?? null,
            $result['operations'][0]['category_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ((int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        $courseid = (int) (
            $result['course']['target_id']
            ?? $result['operations'][0]['target_id']
            ?? 0
        );

        if ($courseid > 0) {
            $categoryid = (int) $DB->get_field(
                'course',
                'category',
                ['id' => $courseid]
            );

            if ($categoryid > 0) {
                return $categoryid;
            }
        }

        return 0;
    }

    private function execute_phase7_identities(
        array $document,
        string $migrationjson
    ): array {
        global $DB;

        $sourcecourse = trim(
            (string) (
                $document['course']['source_id']
                ?? ''
            )
        );

        if ($sourcecourse === '') {
            throw new \coding_exception(
                'Phase 7.1 cannot resolve the source course ref_id.'
            );
        }

        $mapping = $DB->get_record(
            'local_iliasmigration_map',
            [
                'sourcelms' => 'ILIAS',
                'sourceinstance' => '',
                'sourcecourse' => $sourcecourse,
                'sourceref' => $sourcecourse,
                'targettype' => 'course',
            ],
            'id,targetid,status'
        );

        if (!$mapping
                || (int) $mapping->targetid <= 0
                || (string) $mapping->status !== 'READY') {
            throw new \coding_exception(
                'Phase 7.1 requires the successful Structure step and a READY course mapping.'
            );
        }

        $courseid = (int) $mapping->targetid;

        if (!$DB->record_exists(
            'course',
            ['id' => $courseid]
        )) {
            throw new \coding_exception(
                'Phase 7.1 mapped Moodle course no longer exists.'
            );
        }

        $identityjson = (
            new operator_phase7_identity_queue()
        )->require_identity_for_migration(
            $migrationjson
        );

        $overridejson = $this->phase7_override_path(
            $migrationjson
        );

        return (
            new phase7_identity_executor()
        )->execute(
            $identityjson,
            $courseid,
            $overridejson
        );
    }

    private function phase7_override_path(
        string $migrationjson
    ): ?string {
        $config = get_config(
            'local_iliasmigration'
        );

        $recoveriesroot = realpath(
            trim(
                (string) (
                    $config->recoveriesroot
                    ?? '/var/moodledata/ilias2moodle/recovery'
                )
            )
        );

        if ($recoveriesroot === false
                || !is_dir($recoveriesroot)) {
            return null;
        }

        $package = basename(
            dirname(
                realpath($migrationjson)
                    ?: $migrationjson
            )
        );

        if (!preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',
            $package
        )
                || str_contains($package, '..')) {
            return null;
        }

        $candidate = $recoveriesroot
            . DIRECTORY_SEPARATOR
            . 'identity-overrides'
            . DIRECTORY_SEPARATOR
            . $package
            . '.json';

        if (!is_file($candidate)
                || is_link($candidate)
                || !is_readable($candidate)) {
            return null;
        }

        return $candidate;
    }

    private function execute_glossaries(
        array $document,
        string $migrationjson,
        int $categoryid
    ): array {
        global $DB;

        $executor = new phase65_glossary_executor($migrationjson);
        $reconciler = new phase65_glossary_media_reconciler($migrationjson);

        $transaction = $DB->start_delegated_transaction();

        try {
            $result = $executor->execute($document, $categoryid);
            $result = $reconciler->reconcile($result);
            $transaction->allow_commit();

            return $result;
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
    }

    private function collect_types(array $items, array &$types): void {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = trim((string) ($item['type'] ?? ''));
            if ($type !== '') {
                $types[$type] = true;
            }

            $children = is_array($item['items'] ?? null)
                ? $item['items']
                : [];

            $this->collect_types($children, $types);
        }
    }
}

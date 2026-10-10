<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Safe reset service for mappings left behind after a Moodle course deletion.
 *
 * A reset is offered only when the source course has mappings and its mapped
 * Moodle course no longer exists. The complete mapping scope is snapshotted
 * and audited before deletion.
 */
final class operator_mapping_reset {
    public const STATE_NONE = 'NONE';
    public const STATE_LIVE_TARGET = 'LIVE_TARGET';
    public const STATE_ORPHANED = 'ORPHANED';
    public const STATE_AMBIGUOUS = 'AMBIGUOUS';

    /**
     * Inspect whether a prepared migration package has resettable mappings.
     *
     * @return array{
     *   state:string,
     *   reset_allowed:bool,
     *   sourcepath:string,
     *   sourcelms:string,
     *   sourceinstance:string,
     *   sourcecourse:string,
     *   targetcourseid:?int,
     *   mapping_count:int,
     *   legacy_scope:bool,
     *   mapping_types:array<string,int>,
     *   message:string
     * }
     */
    public function inspect_source(string $sourcepath): array {
        global $DB;

        $resolved = realpath(trim($sourcepath));
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new \coding_exception(
                'migration.json is not readable: ' . $sourcepath
            );
        }

        $document = (new migration_reader())->read($resolved);

        $sourcecourse = trim(
            (string) ($document['course']['source_id'] ?? '')
        );
        $sourceinstance = $this->source_instance($document);

        if ($sourcecourse === '') {
            throw new \coding_exception(
                'Unable to resolve the ILIAS source course for mapping reset.'
            );
        }

        $mappings = $this->mappings_for_course(
            $sourceinstance,
            $sourcecourse
        );
        $legacy = $sourceinstance !== ''
            && $this->has_legacy_scope($mappings);

        if (!$mappings) {
            return $this->inspection(
                self::STATE_NONE,
                false,
                $resolved,
                $sourceinstance,
                $sourcecourse,
                null,
                [],
                $legacy,
                'No persistent mapping exists for this source course.'
            );
        }

        $coursemappings = array_values(array_filter(
            $mappings,
            static fn(\stdClass $mapping): bool =>
                (string) $mapping->targettype === 'course'
        ));

        if (count($coursemappings) !== 1) {
            return $this->inspection(
                self::STATE_AMBIGUOUS,
                false,
                $resolved,
                $sourceinstance,
                $sourcecourse,
                null,
                $mappings,
                $legacy,
                'The mapping scope does not contain exactly one course mapping; automatic reset is refused.'
            );
        }

        $coursemapping = $coursemappings[0];
        $targetcourseid = (int) ($coursemapping->targetid ?? 0);

        if ($targetcourseid > 0
                && $DB->record_exists('course', ['id' => $targetcourseid])) {
            return $this->inspection(
                self::STATE_LIVE_TARGET,
                false,
                $resolved,
                $sourceinstance,
                $sourcecourse,
                $targetcourseid,
                $mappings,
                $legacy,
                'The mapped Moodle course still exists; reset is refused.'
            );
        }

        return $this->inspection(
            self::STATE_ORPHANED,
            true,
            $resolved,
            $scopeinstance,
            $sourcecourse,
            $targetcourseid > 0 ? $targetcourseid : null,
            $mappings,
            $legacy,
            'The mapped Moodle course no longer exists; this mapping scope can be reset explicitly.'
        );
    }

    /**
     * Snapshot, audit and delete one orphaned source-course mapping scope.
     */
    public function reset_source(
        string $sourcepath,
        int $userid
    ): array {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        try {
            // Re-inspect inside the transaction to protect against a stale UI.
            $inspection = $this->inspect_source($sourcepath);

            if (empty($inspection['reset_allowed'])
                    || ($inspection['state'] ?? '') !== self::STATE_ORPHANED) {
                throw new \coding_exception(
                    'Mapping reset is not allowed: '
                    . (string) ($inspection['message'] ?? 'unsafe state')
                );
            }

            $sourceinstance =
                (string) $inspection['sourceinstance'];
            $sourcecourse =
                (string) $inspection['sourcecourse'];

            $mappings = $this->mappings_for_course(
                $sourceinstance,
                $sourcecourse
            );

            if (!$mappings) {
                throw new \coding_exception(
                    'Mapping reset scope became empty before confirmation.'
                );
            }

            $coursemappings = array_values(array_filter(
                $mappings,
                static fn(\stdClass $mapping): bool =>
                    (string) $mapping->targettype === 'course'
            ));

            if (count($coursemappings) !== 1) {
                throw new \coding_exception(
                    'Mapping reset scope changed and is now ambiguous.'
                );
            }

            $targetcourseid =
                (int) ($coursemappings[0]->targetid ?? 0);

            if ($targetcourseid > 0
                    && $DB->record_exists(
                        'course',
                        ['id' => $targetcourseid]
                    )) {
                throw new \coding_exception(
                    'Mapping reset aborted because the mapped Moodle course exists again.'
                );
            }

            $snapshot = $this->snapshot($mappings);
            $snapshotjson = json_encode(
                $snapshot,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            );
            $snapshothash = hash('sha256', $snapshotjson);
            $now = time();

            $auditid = (int) $DB->insert_record(
                'local_iliasmigration_reset',
                (object) [
                    'sourcelms' => 'ILIAS',
                    'sourceinstance' => $sourceinstance,
                    'sourcecourse' => $sourcecourse,
                    'sourcepath' => (string) $inspection['sourcepath'],
                    'targetcourseid' =>
                        $targetcourseid > 0
                            ? $targetcourseid
                            : null,
                    'mappingcount' => count($mappings),
                    'mappingsha256' => $snapshothash,
                    'snapshotjson' => $snapshotjson,
                    'userid' => $userid,
                    'timecreated' => $now,
                ]
            );

            // The audit record is deliberately inserted before deletion.
            [$instancesql, $instanceparams] =
                $this->source_instance_condition($sourceinstance);

            $params = [
                'sourcelms' => 'ILIAS',
                'sourcecourse' => $sourcecourse,
            ] + $instanceparams;

            $DB->delete_records_select(
                'local_iliasmigration_map',
                'sourcelms = :sourcelms'
                    . ' AND sourcecourse = :sourcecourse'
                    . ' AND ' . $instancesql,
                $params
            );

            $remaining = $DB->count_records_select(
                'local_iliasmigration_map',
                'sourcelms = :sourcelms'
                    . ' AND sourcecourse = :sourcecourse'
                    . ' AND ' . $instancesql,
                $params
            );

            if ($remaining !== 0) {
                throw new \coding_exception(
                    'Mapping reset did not remove the complete source-course scope.'
                );
            }

            $transaction->allow_commit();

            return [
                'audit_id' => $auditid,
                'sourceinstance' => $sourceinstance,
                'sourcecourse' => $sourcecourse,
                'targetcourseid' =>
                    $targetcourseid > 0
                        ? $targetcourseid
                        : null,
                'mapping_count' => count($mappings),
                'mapping_sha256' => $snapshothash,
            ];
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
    }

    /**
     * Return mappings belonging to the canonical source instance plus the
     * historical legacy scope sourceinstance=''.
     *
     * Structure mappings created by early plugin versions used the empty
     * sourceinstance while later phases use the canonical ILIAS identity.
     * Reset must therefore treat both as one logical source-course scope.
     *
     * @return array<int, \stdClass>
     */
    private function mappings_for_course(
        string $sourceinstance,
        string $sourcecourse
    ): array {
        global $DB;

        [$instancesql, $instanceparams] =
            $this->source_instance_condition($sourceinstance);

        $params = [
            'sourcelms' => 'ILIAS',
            'sourcecourse' => $sourcecourse,
        ] + $instanceparams;

        return $DB->get_records_select(
            'local_iliasmigration_map',
            'sourcelms = :sourcelms'
                . ' AND sourcecourse = :sourcecourse'
                . ' AND ' . $instancesql,
            $params,
            'id ASC'
        );
    }

    /**
     * SQL condition covering the canonical instance and its legacy blank scope.
     *
     * @return array{0:string,1:array<string,string>}
     */
    private function source_instance_condition(
        string $sourceinstance
    ): array {
        if ($sourceinstance === '') {
            return [
                'sourceinstance = :legacyinstance',
                ['legacyinstance' => ''],
            ];
        }

        return [
            '(sourceinstance = :sourceinstance'
                . ' OR sourceinstance = :legacyinstance)',
            [
                'sourceinstance' => $sourceinstance,
                'legacyinstance' => '',
            ],
        ];
    }

    /**
     * Whether the collected logical scope includes historical blank-instance
     * mappings.
     *
     * @param array<int, \stdClass> $mappings
     */
    private function has_legacy_scope(array $mappings): bool {
        foreach ($mappings as $mapping) {
            if ((string) ($mapping->sourceinstance ?? '') === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the stable source-instance identity exactly like plan_builder.
     */
    private function source_instance(array $document): string {
        $metadata = is_array($document['course']['metadata'] ?? null)
            ? $document['course']['metadata']
            : [];

        $installationurl = trim(
            (string) ($metadata['installation_url'] ?? '')
        );
        if ($installationurl !== '') {
            return rtrim($installationurl, '/');
        }

        $installationid = trim(
            (string) ($metadata['installation_id'] ?? '')
        );
        if ($installationid !== '') {
            return 'installation-id:' . $installationid;
        }

        return 'unknown-ilias-instance';
    }

    /**
     * @param array<int, \stdClass> $mappings
     * @return array<int,array<string,mixed>>
     */
    private function snapshot(array $mappings): array {
        $snapshot = [];

        foreach ($mappings as $mapping) {
            $snapshot[] = [
                'id' => (int) $mapping->id,
                'sourcelms' => (string) $mapping->sourcelms,
                'sourceinstance' => (string) $mapping->sourceinstance,
                'sourceversion' =>
                    (string) ($mapping->sourceversion ?? ''),
                'sourcecourse' => (string) $mapping->sourcecourse,
                'sourceref' => (string) $mapping->sourceref,
                'sourceobj' => (string) ($mapping->sourceobj ?? ''),
                'targettype' => (string) $mapping->targettype,
                'targetid' => $mapping->targetid !== null
                    ? (int) $mapping->targetid
                    : null,
                'status' => (string) $mapping->status,
                'timecreated' => (int) $mapping->timecreated,
                'timemodified' => (int) $mapping->timemodified,
            ];
        }

        return $snapshot;
    }

    private function inspection(
        string $state,
        bool $resetallowed,
        string $sourcepath,
        string $sourceinstance,
        string $sourcecourse,
        ?int $targetcourseid,
        array $mappings,
        bool $legacy,
        string $message
    ): array {
        $types = [];

        foreach ($mappings as $mapping) {
            $type = (string) ($mapping->targettype ?? '');
            if ($type === '') {
                continue;
            }
            $types[$type] = ($types[$type] ?? 0) + 1;
        }
        ksort($types);

        return [
            'state' => $state,
            'reset_allowed' => $resetallowed,
            'sourcepath' => $sourcepath,
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $sourceinstance,
            'sourcecourse' => $sourcecourse,
            'targetcourseid' => $targetcourseid,
            'mapping_count' => count($mappings),
            'legacy_scope' => $legacy,
            'mapping_types' => $types,
            'message' => $message,
        ];
    }
}

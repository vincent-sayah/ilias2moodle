<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle-side entry point for ILIAS2Moodle imports.
 */
final class importer {
    /**
     * Analyse a neutral migration document or apply the validated migration subset.
     *
     * Phase 2 supports dry-run and real structure writes.
     * Phase 3 supports dry-run/package validation and real simple-resource writes.
     *
     * @param string $migrationjson Absolute path to migration.json.
     * @param int $categoryid Moodle target category id.
     * @param bool $dryrun Whether Moodle writes are forbidden.
     * @param int $phase Requested project phase (2 or 3).
     * @return array Plan or execution report.
     */
    public function import(
        string $migrationjson,
        int $categoryid,
        bool $dryrun = true,
        int $phase = 2
    ): array {
        if (!in_array($phase, [2, 3], true)) {
            throw new \coding_exception('Only migration phases 2 and 3 are supported by this plugin version.');
        }

        $reader = new migration_reader();
        $document = $reader->read($migrationjson);

        if ($dryrun) {
            $planner = new plan_builder($categoryid, $phase);
            $plan = $planner->build($document);

            if ($phase === 3) {
                $validator = new phase3_package_validator($migrationjson);
                return $validator->validate($plan);
            }

            return $plan;
        }

        if ($phase === 3) {
            $executor = new resource_executor($migrationjson);
            return $executor->execute($document, $categoryid);
        }

        $executor = new structure_executor();
        return $executor->execute($document, $categoryid);
    }
}

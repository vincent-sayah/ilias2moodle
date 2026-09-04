<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle-side entry point for ILIAS2Moodle imports.
 */
final class importer {
    /**
     * Analyse a neutral migration document or apply the validated Phase 2 subset.
     *
     * Dry-run never writes Moodle content. Apply creates/updates the hidden course,
     * first-level sections and second-level Moodle subsections. Resources remain deferred.
     *
     * @param string $migrationjson Absolute path to migration.json.
     * @param int $categoryid Moodle target category id.
     * @param bool $dryrun Whether Moodle writes are forbidden.
     * @return array Plan or execution report.
     */
    public function import(string $migrationjson, int $categoryid, bool $dryrun = true): array {
        $reader = new migration_reader();
        $document = $reader->read($migrationjson);

        if ($dryrun) {
            $planner = new plan_builder($categoryid);
            return $planner->build($document);
        }

        $executor = new structure_executor();
        return $executor->execute($document, $categoryid);
    }
}

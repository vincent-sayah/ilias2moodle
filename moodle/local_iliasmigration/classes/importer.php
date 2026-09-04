<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle-side entry point for ILIAS2Moodle imports.
 */
final class importer {
    /**
     * Analyse a neutral migration document and build the Moodle execution plan.
     *
     * Phase 2 alpha intentionally supports dry-run only. No Moodle course content is written.
     *
     * @param string $migrationjson Absolute path to migration.json.
     * @param int $categoryid Moodle target category id.
     * @param bool $dryrun Whether Moodle writes are forbidden.
     * @return array Dry-run plan.
     */
    public function import(string $migrationjson, int $categoryid, bool $dryrun = true): array {
        if (!$dryrun) {
            throw new \coding_exception(
                'Phase 2 alpha only supports dry-run. No Moodle writes are enabled yet.'
            );
        }

        $reader = new migration_reader();
        $document = $reader->read($migrationjson);

        $planner = new plan_builder($categoryid);
        return $planner->build($document);
    }
}

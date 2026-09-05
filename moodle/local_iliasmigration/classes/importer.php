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
     * Phase 4 supports dry-run/package validation and real SCORM writes.
     * Phase 5 supports dry-run/package validation and real Learning Module -> Moodle Book writes.
     *
     * @param string $migrationjson Absolute path to migration.json.
     * @param int $categoryid Moodle target category id.
     * @param bool $dryrun Whether Moodle writes are forbidden.
     * @param int $phase Requested project phase (2, 3, 4 or 5).
     * @return array Plan or execution report.
     */
    public function import(
        string $migrationjson,
        int $categoryid,
        bool $dryrun = true,
        int $phase = 2
    ): array {
        if (!in_array($phase, [2, 3, 4, 5], true)) {
            throw new \coding_exception(
                'Only migration phases 2, 3, 4 and 5 are supported by this plugin version.'
            );
        }

        $reader = new migration_reader();
        $document = $reader->read($migrationjson);

        if ($dryrun) {
            if ($phase === 5) {
                $planner = new phase5_plan_builder($categoryid);
                $plan = $planner->build($document);

                // Phase 5 uses a newer complete course export. Revalidate every
                // earlier package type so a new URL/resource/SCORM cannot be
                // skipped before a later Moodle Book apply.
                $phase3validator = new phase3_package_validator($migrationjson);
                $plan = $phase3validator->validate($plan);

                $phase4validator = new phase4_package_validator($migrationjson);
                $plan = $phase4validator->validate($plan);

                $phase5validator = new phase5_package_validator($migrationjson);
                return $phase5validator->validate($plan);
            }

            if ($phase === 4) {
                $planner = new phase4_plan_builder($categoryid);
                $plan = $planner->build($document);

                // A newer export may contain new/changed simple resources in
                // addition to SCORM. Validate them and expose them as Phase 4
                // prerequisites instead of silently skipping them.
                $phase3validator = new phase3_package_validator($migrationjson);
                $plan = $phase3validator->validate($plan);

                $phase4validator = new phase4_package_validator($migrationjson);
                return $phase4validator->validate($plan);
            }

            $planner = new plan_builder($categoryid, $phase);
            $plan = $planner->build($document);

            if ($phase === 3) {
                $validator = new phase3_package_validator($migrationjson);
                return $validator->validate($plan);
            }

            return $plan;
        }

        if ($phase === 5) {
            $executor = new book_executor($migrationjson);
            return $executor->execute($document, $categoryid);
        }

        if ($phase === 4) {
            $executor = new scorm_executor($migrationjson);
            return $executor->execute($document, $categoryid);
        }

        if ($phase === 3) {
            global $CFG;

            // Moodle 5.0 mod_resource update relies on resource_set_mainfile()
            // from locallib.php. Load it explicitly for CLI imports so the
            // UPDATE path is deterministic even when update_module() has not
            // caused the module-local helper to be loaded yet.
            require_once($CFG->dirroot . '/mod/resource/locallib.php');

            $executor = new resource_executor($migrationjson);
            return $executor->execute($document, $categoryid);
        }

        $executor = new structure_executor();
        return $executor->execute($document, $categoryid);
    }
}

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
     * Phase 4 currently supports SCORM dry-run/package validation only.
     *
     * @param string $migrationjson Absolute path to migration.json.
     * @param int $categoryid Moodle target category id.
     * @param bool $dryrun Whether Moodle writes are forbidden.
     * @param int $phase Requested project phase (2, 3 or 4).
     * @return array Plan or execution report.
     */
    public function import(
        string $migrationjson,
        int $categoryid,
        bool $dryrun = true,
        int $phase = 2
    ): array {
        if (!in_array($phase, [2, 3, 4], true)) {
            throw new \coding_exception('Only migration phases 2, 3 and 4 are supported by this plugin version.');
        }

        $reader = new migration_reader();
        $document = $reader->read($migrationjson);

        if ($dryrun) {
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

        if ($phase === 4) {
            throw new \coding_exception(
                'Phase 4 SCORM apply is not enabled yet. Run --phase=4 --dry-run and validate the package first.'
            );
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

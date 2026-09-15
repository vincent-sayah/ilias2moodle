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
     * Phase 6 supports Question Bank + Quiz dry-run validation and guarded real writes.
     * Phase 6.5 (internal code 65) supports Content Page -> Moodle Page dry-run and guarded writes.
     *
     * @param string $migrationjson Absolute path to migration.json.
     * @param int $categoryid Existing Moodle target course category id, or 0 when categorypath is used.
     * @param bool $dryrun Whether Moodle writes are forbidden.
     * @param int $phase Requested project phase (2, 3, 4, 5, 6 or internal 65 for 6.5).
     * @param string $categorypath Optional Phase 2 category path to resolve/create.
     * @return array Plan or execution report.
     */
    public function import(
        string $migrationjson,
        int $categoryid,
        bool $dryrun = true,
        int $phase = 2,
        string $categorypath = ''
    ): array {
        if (!in_array($phase, [2, 3, 4, 5, 6, 65], true)) {
            throw new \coding_exception(
                'Only migration phases 2, 3, 4, 5, 6 and 6.5 are supported by this plugin version.'
            );
        }

        $reader = new migration_reader();
        $document = $reader->read($migrationjson);

        $categorypath = trim($categorypath);
        $categoryresolution = null;

        if ($categorypath !== '') {
            if ($categoryid > 0) {
                throw new \coding_exception('Choose either an existing category id or a category path, not both.');
            }
            if ($phase !== 2) {
                throw new \coding_exception(
                    'Automatic category-path resolution/creation is intentionally limited to Phase 2. '
                    . 'Use --category=ID for Phases 3 to 6.5.'
                );
            }

            $resolver = new category_path_resolver();
            $categoryresolution = $resolver->plan($categorypath);

            if (!$categoryresolution['ready']) {
                return $this->category_preview_report($document, $categoryresolution);
            }

            if ($categoryresolution['target_id'] === null) {
                if ($dryrun) {
                    return $this->category_preview_report($document, $categoryresolution);
                }
                $categoryresolution = $resolver->apply($categorypath);
            }

            $categoryid = (int) $categoryresolution['target_id'];
        }

        if ($categoryid <= 0) {
            throw new \coding_exception('A valid Moodle category id or Phase 2 category path is required.');
        }

        if ($dryrun) {
            if ($phase === 65) {
                $planner = new phase65_plan_builder($categoryid);
                $plan = $planner->build($document);

                $phase3validator = new phase3_package_validator($migrationjson);
                $plan = $phase3validator->validate($plan);

                $phase4validator = new phase4_package_validator($migrationjson);
                $plan = $phase4validator->validate($plan);

                $phase5validator = new phase5_package_validator($migrationjson);
                $plan = $phase5validator->validate($plan);

                $phase6validator = new phase6_package_validator($migrationjson);
                $plan = $phase6validator->validate($plan);

                $scoringvalidator = new phase6_scoring_policy_validator($migrationjson);
                $plan = $scoringvalidator->validate($plan);

                $phase65validator = new phase65_package_validator($migrationjson);
                return $phase65validator->validate($plan);
            }

            if ($phase === 6) {
                $planner = new phase6_plan_builder($categoryid);
                $plan = $planner->build($document);

                $phase3validator = new phase3_package_validator($migrationjson);
                $plan = $phase3validator->validate($plan);

                $phase4validator = new phase4_package_validator($migrationjson);
                $plan = $phase4validator->validate($plan);

                $phase5validator = new phase5_package_validator($migrationjson);
                $plan = $phase5validator->validate($plan);

                $phase6validator = new phase6_package_validator($migrationjson);
                $plan = $phase6validator->validate($plan);

                $scoringvalidator = new phase6_scoring_policy_validator($migrationjson);
                return $scoringvalidator->validate($plan);
            }

            if ($phase === 5) {
                $planner = new phase5_plan_builder($categoryid);
                $plan = $planner->build($document);

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

            if ($categoryresolution !== null) {
                $plan['moodle']['category_resolution'] = $categoryresolution;
            }

            return $plan;
        }

        if ($phase === 65) {
            global $CFG;

            // page_get_editor_options() is defined in mod/page/locallib.php.
            // The CLI apply does not instantiate mod_page's form, so load the
            // helper explicitly before the executor persists draft-area files.
            require_once($CFG->dirroot . '/mod/page/locallib.php');

            $executor = new phase65_executor($migrationjson);
            return $executor->execute($document, $categoryid);
        }

        if ($phase === 6) {
            $executor = new phase6_executor($migrationjson);
            return $executor->execute($document, $categoryid);
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

            require_once($CFG->dirroot . '/mod/resource/locallib.php');

            $executor = new resource_executor($migrationjson);
            return $executor->execute($document, $categoryid);
        }

        $executor = new structure_executor();
        $result = $executor->execute($document, $categoryid);
        if ($categoryresolution !== null) {
            $result['moodle']['category_resolution'] = $categoryresolution;
        }
        return $result;
    }

    /**
     * Return a Phase 2 dry-run report when the category path must be created
     * or cannot be resolved safely yet.
     *
     * No Moodle writes are performed by this method.
     *
     * @param array $document Validated migration document.
     * @param array $resolution Category resolution plan.
     * @return array Dry-run report.
     */
    private function category_preview_report(array $document, array $resolution): array {
        global $CFG;

        $course = $document['course'];
        $sourcecourseid = (string) $course['source_id'];
        $warnings = [];

        foreach (($resolution['blockers'] ?? []) as $blocker) {
            $warnings[] = $blocker;
        }

        $operations = $resolution['operations'] ?? [];
        $operations[] = [
            'kind' => 'course',
            'action' => $resolution['ready'] ? 'WAIT_CATEGORY' : 'BLOCKED',
            'source_ref_id' => $sourcecourseid,
            'source_obj_id' => (string) ($course['metadata']['obj_id'] ?? ''),
            'target_id' => null,
            'fullname' => (string) $course['title'],
            'shortname' => 'ILIAS-' . $sourcecourseid,
            'category_id' => null,
            'category_path' => (string) ($resolution['path'] ?? ''),
            'reason' => $resolution['ready']
                ? 'The category hierarchy must be created before the complete Phase 2 structure can be planned.'
                : 'The category path is ambiguous and must be resolved before any write.',
        ];

        return [
            'mode' => 'dry-run',
            'phase' => 2,
            'writes_performed' => false,
            'ready' => (bool) ($resolution['ready'] ?? false),
            'moodle' => [
                'release' => (string) $CFG->release,
                'version' => (string) $CFG->version,
                'category' => [
                    'id' => null,
                    'name' => (string) ($resolution['target_name'] ?? ''),
                    'visible' => (int) ($resolution['target_visible'] ?? 0),
                ],
                'category_resolution' => $resolution,
            ],
            'source' => $document['source'],
            'course' => [
                'source_id' => $sourcecourseid,
                'title' => (string) $course['title'],
                'shortname' => 'ILIAS-' . $sourcecourseid,
            ],
            'operations' => $operations,
            'warnings' => $warnings,
        ];
    }
}

<?php

namespace local_iliasmigration;

use core_courseformat\formatactions;

defined('MOODLE_INTERNAL') || die();

/**
 * Executes the safe subset of Phase 2 structure operations in Moodle.
 */
final class structure_executor {
    /**
     * Create or update the Moodle course and first-level sections.
     *
     * Resource activities remain deferred. Subsections and deeper folders are
     * deliberately rejected before any write until their Moodle 5 implementation
     * is validated separately.
     *
     * @param array $document Validated migration document.
     * @param int $categoryid Moodle target category id.
     * @return array Execution report.
     */
    public function execute(array $document, int $categoryid): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        $planner = new plan_builder($categoryid);
        $plan = $planner->build($document);
        $this->assert_applyable($plan);

        $sourcecourseid = (string) $document['course']['source_id'];
        $sourceversion = (string) ($document['source']['version'] ?? '');
        $transaction = $DB->start_delegated_transaction();

        try {
            $operations = $plan['operations'];
            $courseoperation = array_shift($operations);
            $courseresult = $this->apply_course($courseoperation, $sourceversion);
            $course = $courseresult['course'];

            $results = [$courseresult['operation']];
            foreach ($operations as $operation) {
                if ($operation['kind'] === 'section') {
                    $results[] = $this->apply_section(
                        $course,
                        $operation,
                        $sourcecourseid,
                        $sourceversion
                    );
                    continue;
                }

                // Resource/activity migration is intentionally deferred to later phases.
                $results[] = $operation;
            }

            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        $plan['mode'] = 'apply';
        $plan['writes_performed'] = true;
        $plan['course']['target_id'] = (int) $course->id;
        $plan['course']['visible'] = (int) $course->visible;
        $plan['operations'] = $results;
        $plan['warnings'][] = [
            'code' => 'COURSE_CREATED_HIDDEN',
            'message' => 'The imported course remains hidden until later migration phases are validated.',
        ];

        return $plan;
    }

    /**
     * Refuse any unsafe or not-yet-supported structural operation before writes.
     *
     * @param array $plan Dry-run plan.
     */
    private function assert_applyable(array $plan): void {
        foreach ($plan['operations'] as $operation) {
            $kind = (string) ($operation['kind'] ?? '');
            $action = (string) ($operation['action'] ?? '');

            if (in_array($kind, ['course', 'section'], true)) {
                if (!in_array($action, ['CREATE', 'UPDATE'], true)) {
                    $reason = (string) ($operation['reason'] ?? 'structural operation is not safe');
                    throw new \moodle_exception(
                        'error',
                        'local_iliasmigration',
                        '',
                        null,
                        "Cannot apply {$kind}: {$action} ({$reason})"
                    );
                }
                continue;
            }

            if ($kind === 'subsection' || $action === 'FLATTEN_REQUIRED' || $action === 'BLOCKED') {
                throw new \moodle_exception(
                    'error',
                    'local_iliasmigration',
                    '',
                    null,
                    'Apply currently supports only the course and first-level sections. '
                        . 'Subsections/deeper folders must be validated separately.'
                );
            }
        }
    }

    /**
     * Create or update the Moodle course using core APIs.
     *
     * @param array $operation Planned course operation.
     * @param string $sourceversion ILIAS source version.
     * @return array Course object and execution operation.
     */
    private function apply_course(array $operation, string $sourceversion): array {
        global $DB;

        $requested = (string) $operation['action'];
        if ($requested === 'CREATE') {
            $course = create_course((object) [
                'fullname' => (string) $operation['fullname'],
                'shortname' => (string) $operation['shortname'],
                'category' => (int) $operation['category_id'],
                'visible' => 0,
                'format' => 'topics',
                'numsections' => 0,
            ]);
            $performed = 'CREATED';
        } else {
            $targetid = (int) $operation['target_id'];
            $DB->get_record('course', ['id' => $targetid], 'id', MUST_EXIST);
            update_course((object) [
                'id' => $targetid,
                'fullname' => (string) $operation['fullname'],
                'shortname' => (string) $operation['shortname'],
                'category' => (int) $operation['category_id'],
            ]);
            $course = $DB->get_record('course', ['id' => $targetid], '*', MUST_EXIST);
            $performed = 'UPDATED';
        }

        $this->save_mapping(
            (string) $operation['source_ref_id'],
            (string) $operation['source_ref_id'],
            (string) ($operation['source_obj_id'] ?? ''),
            'course',
            (int) $course->id,
            $sourceversion
        );

        $result = $operation;
        $result['requested_action'] = $requested;
        $result['action'] = $performed;
        $result['target_id'] = (int) $course->id;
        $result['visible'] = (int) $course->visible;

        return ['course' => $course, 'operation' => $result];
    }

    /**
     * Create/update/reorder a first-level Moodle section using course format APIs.
     *
     * @param \stdClass $course Moodle course.
     * @param array $operation Planned section operation.
     * @param string $sourcecourseid ILIAS course ref_id.
     * @param string $sourceversion ILIAS source version.
     * @return array Execution operation.
     */
    private function apply_section(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion
    ): array {
        global $DB;

        $position = max(1, (int) $operation['position']);
        $requested = (string) $operation['action'];
        $sectionactions = formatactions::section($course);

        if ($requested === 'CREATE') {
            $sectionrecord = course_create_section($course, $position);
            $sectionid = (int) $sectionrecord->id;
            $performed = 'CREATED';
        } else {
            $sectionid = (int) $operation['target_id'];
            $sectionrecord = $DB->get_record(
                'course_sections',
                ['id' => $sectionid],
                'id,course,section',
                MUST_EXIST
            );
            if ((int) $sectionrecord->course !== (int) $course->id) {
                throw new \coding_exception('Mapped section belongs to a different Moodle course.');
            }
            $performed = 'UPDATED';
        }

        rebuild_course_cache($course->id, true);
        $sectioninfo = get_fast_modinfo($course->id)->get_section_info_by_id($sectionid);
        if (!$sectioninfo) {
            throw new \coding_exception('Unable to resolve the Moodle section after creation/update.');
        }

        if ((int) $sectioninfo->section !== $position) {
            $sectionactions->move_at($sectioninfo, $position);
            rebuild_course_cache($course->id, true);
            $sectioninfo = get_fast_modinfo($course->id)->get_section_info_by_id($sectionid);
        }

        if (!$sectioninfo) {
            throw new \coding_exception('Unable to refresh the Moodle section after moving it.');
        }

        $sectionactions->update($sectioninfo, ['name' => (string) $operation['title']]);

        $this->save_mapping(
            $sourcecourseid,
            (string) $operation['source_ref_id'],
            (string) ($operation['source_obj_id'] ?? ''),
            'section',
            $sectionid,
            $sourceversion
        );

        $result = $operation;
        $result['requested_action'] = $requested;
        $result['action'] = $performed;
        $result['target_id'] = $sectionid;

        return $result;
    }

    /**
     * Insert or refresh one row in the plugin-owned mapping table.
     *
     * @param string $sourcecourse ILIAS course ref_id.
     * @param string $sourceref ILIAS object ref_id.
     * @param string $sourceobj ILIAS obj_id.
     * @param string $targettype Moodle target type.
     * @param int $targetid Moodle id.
     * @param string $sourceversion ILIAS version.
     */
    private function save_mapping(
        string $sourcecourse,
        string $sourceref,
        string $sourceobj,
        string $targettype,
        int $targetid,
        string $sourceversion
    ): void {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => $targettype,
        ];
        $existing = $DB->get_record('local_iliasmigration_map', $conditions);
        $now = time();

        $record = (object) ($conditions + [
            'sourceversion' => $sourceversion !== '' ? $sourceversion : null,
            'sourceobj' => $sourceobj !== '' ? $sourceobj : null,
            'targetid' => $targetid,
            'status' => 'READY',
            'timemodified' => $now,
        ]);

        if ($existing) {
            $record->id = $existing->id;
            $record->timecreated = $existing->timecreated;
            $DB->update_record('local_iliasmigration_map', $record);
            return;
        }

        $record->timecreated = $now;
        $DB->insert_record('local_iliasmigration_map', $record);
    }
}

<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Apply ILIAS MediaCast -> Moodle mod_data.
 *
 * One ILIAS MediaCast becomes one Database activity and every source item
 * becomes one Database record. Local MP4 files are copied through the Moodle
 * Files API; external references remain URL fields.
 */
final class phase65_mediacast_executor {
    private string $packageroot;
    private string $migrationjson;
    private string $sourceinstance = '';

    private const FIELD_SPECS = [
        'source_entry_id' => ['type' => 'text', 'description' => 'ILIAS MediaCast source entry id'],
        'position' => ['type' => 'number', 'description' => 'ILIAS MediaCast source order'],
        'title' => ['type' => 'text', 'description' => 'Media title'],
        'description' => ['type' => 'textarea', 'description' => 'Media description'],
        'source_type' => ['type' => 'text', 'description' => 'local_file or external_url'],
        'duration' => ['type' => 'text', 'description' => 'Source playtime'],
        'media_file' => ['type' => 'file', 'description' => 'Recovered local MP4'],
        'external_url' => ['type' => 'url', 'description' => 'External media URL'],
    ];

    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        $file = realpath($migrationjson);

        if ($root === false || !is_dir($root)
                || $file === false || !is_file($file)) {
            throw new \coding_exception(
                'Unable to resolve migration.json or its package directory.'
            );
        }

        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
        $this->migrationjson = $file;
    }

    public function execute(array $document, int $categoryid): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/data/lib.php');
        require_once($CFG->libdir . '/filelib.php');

        $plan = (new phase6_plan_builder($categoryid))->build($document);
        $plan['phase'] = '6.5.6';
        $plan = (
            new phase65_mediacast_package_validator($this->migrationjson)
        )->validate($plan);

        $this->assert_applyable($plan);

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourseid = (string) ($document['course']['source_id'] ?? '');
        $sourceversion = (string) ($document['source']['version'] ?? '');

        $courseoperation = $plan['operations'][0] ?? null;
        if (!is_array($courseoperation)
                || ($courseoperation['kind'] ?? '') !== 'course') {
            throw new \coding_exception(
                'MediaCast plan has no Moodle course operation.'
            );
        }

        $courseid = (int) ($courseoperation['target_id'] ?? 0);
        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            '*',
            MUST_EXIST
        );

        $originaluser = $USER;
        \core\session\manager::set_user(get_admin());

        try {
            $transaction = $DB->start_delegated_transaction();

            try {
                $results = [];

                foreach ($plan['operations'] as $operation) {
                    if (($operation['kind'] ?? '') !== 'mediacast') {
                        $results[] = $operation;
                        continue;
                    }

                    if (($operation['mediacast_validation']['status'] ?? '')
                            === 'SKIPPED_INCREMENTAL') {
                        $results[] = $operation;
                        continue;
                    }

                    $results[] = $this->apply_mediacast(
                        $course,
                        $operation,
                        $sourcecourseid,
                        $sourceversion
                    );
                }

                rebuild_course_cache($course->id, true);
                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
        } finally {
            if ($originaluser instanceof \stdClass) {
                \core\session\manager::set_user($originaluser);
            }
        }

        $plan['mode'] = 'apply';
        $plan['writes_performed'] = true;
        $plan['phase'] = '6.5.6';
        $plan['phase65_object'] = 'mediacast';
        $plan['operations'] = $results;

        return $plan;
    }

    private function assert_applyable(array $plan): void {
        $package = $plan['phase65_mediacast_package'] ?? [];

        if (empty($package['ready'])
                || empty($package['apply_ready'])
                || empty($package['apply_implemented'])
                || !empty($package['blocked_mediacasts'])) {
            throw new \coding_exception(
                'MediaCast package is not ready for apply.'
            );
        }

        foreach ($plan['operations'] as $operation) {
            if (($operation['kind'] ?? '') !== 'mediacast') {
                continue;
            }

            $validation = $operation['mediacast_validation'] ?? [];

            if (($validation['status'] ?? '') === 'SKIPPED_INCREMENTAL') {
                continue;
            }

            if (($validation['status'] ?? '') !== 'READY'
                    || ($validation['code'] ?? '') !== 'MEDIACAST_READY') {
                throw new \coding_exception(
                    'MediaCast validation is not ready.'
                );
            }

            if (!in_array(
                (string) ($operation['action'] ?? ''),
                ['CREATE', 'UPDATE'],
                true
            )) {
                throw new \coding_exception(
                    'MediaCast action is not CREATE/UPDATE.'
                );
            }
        }
    }

    private function apply_mediacast(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion
    ): array {
        global $DB;

        $requested = (string) ($operation['action'] ?? '');
        $sectionnumber = (int) (
            $operation['mediacast_parent_validation']['section_number']
            ?? 0
        );
        $structure = $this->load_structure($operation);
        $description = (string) ($structure['description'] ?? '');

        if ($requested === 'CREATE') {
            $moduledata = (object) [
                'modulename' => 'data',
                'course' => (int) $course->id,
                'section' => $sectionnumber,
                'visible' => 1,
                'name' => (string) ($operation['title'] ?? ''),
                'introeditor' => $this->intro_editor($description),
                'intro' => $description,
                'introformat' => FORMAT_HTML,
                'comments' => 0,
                'timeavailablefrom' => 0,
                'timeavailableto' => 0,
                'timeviewfrom' => 0,
                'timeviewto' => 0,
                'requiredentries' => 0,
                'requiredentriestoview' => 0,
                'maxentries' => 0,
                'rssarticles' => 0,
                'approval' => 0,
                'manageapproved' => 0,
                'entriesperpage' => 10,
                'assessed' => 0,
                'scale' => 0,
                'notification' => 0,
                'completionentries' => 0,
            ];

            $created = create_module($moduledata);
            $cmid = (int) $created->coursemodule;
            $instanceid = (int) $created->instance;
            $performed = 'CREATED';
        } else {
            $cmid = (int) ($operation['target_id'] ?? 0);
            $cm = get_coursemodule_from_id(
                'data',
                $cmid,
                (int) $course->id,
                false,
                MUST_EXIST
            );

            $section = $DB->get_record(
                'course_sections',
                [
                    'id' => (int) $cm->section,
                    'course' => (int) $course->id,
                ],
                'id,section',
                MUST_EXIST
            );

            if ((int) $section->section !== $sectionnumber) {
                throw new \coding_exception(
                    'Moving an existing MediaCast Database activity to another Moodle section is not supported.'
                );
            }

            [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
            $moduleinfo->name = (string) ($operation['title'] ?? '');
            $moduleinfo->introeditor = $this->intro_editor($description);
            update_moduleinfo($cm, $moduleinfo, $course, null);

            $instanceid = (int) $cm->instance;
            $performed = 'UPDATED';
        }

        $data = $DB->get_record(
            'data',
            ['id' => $instanceid],
            '*',
            MUST_EXIST
        );
        $data->cmid = $cmid;

        $fields = $this->ensure_fields(
            $data,
            $requested === 'CREATE'
        );

        $DB->set_field(
            'data',
            'defaultsort',
            (int) $fields['position']->id,
            ['id' => $instanceid]
        );
        $DB->set_field(
            'data',
            'defaultsortdir',
            0,
            ['id' => $instanceid]
        );

        if ($requested === 'CREATE') {
            $this->configure_templates($data);
        }

        $context = \context_module::instance($cmid);
        $recordscreated = 0;
        $recordsupdated = 0;
        $fileswritten = 0;
        $urlswritten = 0;
        $recordids = [];

        foreach ((array) ($structure['entries'] ?? []) as $entry) {
            if (!is_array($entry)) {
                throw new \coding_exception(
                    'Invalid MediaCast entry during apply.'
                );
            }

            $entryid = trim((string) ($entry['source_id'] ?? ''));
            $record = $this->find_record_by_source_entry(
                $instanceid,
                (int) $fields['source_entry_id']->id,
                $entryid
            );

            if ($record === false) {
                $recordid = (int) data_add_record($data, 0);
                if ($recordid <= 0) {
                    throw new \coding_exception(
                        'Unable to create a Moodle Database record for MediaCast.'
                    );
                }
                $recordscreated++;
            } else {
                $recordid = (int) $record->id;
                $DB->set_field(
                    'data_records',
                    'timemodified',
                    time(),
                    ['id' => $recordid]
                );
                $recordsupdated++;
            }

            $this->upsert_content(
                (int) $fields['source_entry_id']->id,
                $recordid,
                $entryid
            );
            $this->upsert_content(
                (int) $fields['position']->id,
                $recordid,
                (string) ((int) ($entry['position'] ?? 0))
            );
            $this->upsert_content(
                (int) $fields['title']->id,
                $recordid,
                clean_param(
                    (string) ($entry['title'] ?? ''),
                    PARAM_TEXT
                )
            );
            $this->upsert_content(
                (int) $fields['description']->id,
                $recordid,
                clean_text(
                    (string) ($entry['description'] ?? ''),
                    FORMAT_HTML
                ),
                (string) FORMAT_HTML
            );
            $this->upsert_content(
                (int) $fields['source_type']->id,
                $recordid,
                clean_param(
                    (string) ($entry['source_kind'] ?? ''),
                    PARAM_ALPHAEXT
                )
            );
            $this->upsert_content(
                (int) $fields['duration']->id,
                $recordid,
                clean_param(
                    (string) ($entry['playtime'] ?? ''),
                    PARAM_TEXT
                )
            );

            $kind = (string) ($entry['source_kind'] ?? '');
            if ($kind === 'local_file') {
                $sourcefile = $this->resolve_relative_file(
                    (string) ($entry['migration_path'] ?? '')
                );
                $filename = basename(
                    (string) ($entry['location'] ?? '')
                );
                $this->write_file_content(
                    $context,
                    (int) $fields['media_file']->id,
                    $recordid,
                    $sourcefile,
                    $filename
                );
                $this->upsert_content(
                    (int) $fields['external_url']->id,
                    $recordid,
                    '',
                    ''
                );
                $fileswritten++;
            } else if ($kind === 'external_url') {
                $this->clear_file_content(
                    $context,
                    (int) $fields['media_file']->id,
                    $recordid
                );
                $this->upsert_content(
                    (int) $fields['external_url']->id,
                    $recordid,
                    clean_param(
                        (string) ($entry['location'] ?? ''),
                        PARAM_URL
                    ),
                    clean_param(
                        (string) ($entry['title'] ?? ''),
                        PARAM_TEXT
                    )
                );
                $urlswritten++;
            } else {
                throw new \coding_exception(
                    'Unsupported MediaCast source kind reached apply.'
                );
            }

            $this->save_record_mapping(
                $sourcecourseid,
                (string) ($operation['source_ref_id'] ?? ''),
                $entryid,
                (string) ($entry['mob_id'] ?? ''),
                $recordid,
                $sourceversion
            );
            $recordids[$entryid] = $recordid;
        }

        $this->save_activity_mapping(
            $sourcecourseid,
            (string) ($operation['source_ref_id'] ?? ''),
            (string) ($operation['source_obj_id'] ?? ''),
            $cmid,
            $sourceversion
        );

        $result = $operation;
        $result['requested_action'] = $requested;
        $result['action'] = $performed;
        $result['target_id'] = $cmid;
        $result['instance_id'] = $instanceid;
        $result['moodle_section'] = $sectionnumber;
        $result['data_record_ids'] = $recordids;
        $result['records_created'] = $recordscreated;
        $result['records_updated'] = $recordsupdated;
        $result['local_media_files_written'] = $fileswritten;
        $result['external_urls_written'] = $urlswritten;
        $result['preview_policy'] = 'PACKAGE_ONLY_NOT_IMPORTED';
        $result['preview_count_preserved_in_package'] = (int) (
            $operation['mediacast_validation']['preview_count'] ?? 0
        );

        return $result;
    }

    private function ensure_fields(
        \stdClass $data,
        bool $allowcreate
    ): array {
        global $DB;

        $result = [];

        foreach (self::FIELD_SPECS as $name => $spec) {
            $existing = $DB->get_record(
                'data_fields',
                [
                    'dataid' => (int) $data->id,
                    'name' => $name,
                ]
            );

            if ($existing) {
                if ((string) $existing->type
                        !== (string) $spec['type']) {
                    throw new \coding_exception(
                        "MediaCast field {$name} has an unexpected Moodle type."
                    );
                }
                $result[$name] = $existing;
                continue;
            }

            if (!$allowcreate) {
                throw new \coding_exception(
                    "MediaCast field {$name} is missing from the existing Moodle Database activity."
                );
            }

            $field = data_get_field_new(
                (string) $spec['type'],
                $data
            );
            $details = (object) [
                'd' => (int) $data->id,
                'mode' => 'add',
                'type' => (string) $spec['type'],
                'sesskey' => sesskey(),
                'name' => $name,
                'description' => (string) $spec['description'],
                'required' => 0,
            ];

            if ((string) $spec['type'] === 'file') {
                $details->param3 = '0';
            }
            if ((string) $spec['type'] === 'url') {
                $details->param1 = '1';
                $details->param2 = '';
                $details->param3 = '1';
            }

            $field->define_field($details);
            if (!$field->insert_field()) {
                throw new \coding_exception(
                    "Unable to create MediaCast field {$name}."
                );
            }

            $result[$name] = $DB->get_record(
                'data_fields',
                [
                    'dataid' => (int) $data->id,
                    'name' => $name,
                ],
                '*',
                MUST_EXIST
            );
        }

        return $result;
    }

    private function configure_templates(\stdClass $data): void {
        global $DB;

        $list = <<<'HTML'
<div class="ilias2moodle-mediacast-entry">
  <h4>[[title]]</h4>
  <div>[[description]]</div>
  <div><strong>Durée :</strong> [[duration]]</div>
  <div>[[media_file]]</div>
  <div>[[external_url]]</div>
</div>
<hr>
HTML;

        $single = <<<'HTML'
<div class="ilias2moodle-mediacast-entry">
  <h3>[[title]]</h3>
  <div>[[description]]</div>
  <p><strong>Durée :</strong> [[duration]]</p>
  <div>[[media_file]]</div>
  <div>[[external_url]]</div>
</div>
HTML;

        $DB->update_record(
            'data',
            (object) [
                'id' => (int) $data->id,
                'listtemplate' => $list,
                'singletemplate' => $single,
            ]
        );

        $fresh = $DB->get_record(
            'data',
            ['id' => (int) $data->id],
            '*',
            MUST_EXIST
        );
        data_generate_default_template(
            $fresh,
            'addtemplate'
        );
        data_generate_default_template(
            $fresh,
            'asearchtemplate'
        );
        data_generate_default_template(
            $fresh,
            'rsstemplate'
        );
    }

    private function find_record_by_source_entry(
        int $dataid,
        int $sourcefieldid,
        string $entryid
    ): \stdClass|false {
        global $DB;

        $records = $DB->get_records_sql(
            'SELECT r.*
               FROM {data_records} r
               JOIN {data_content} c ON c.recordid = r.id
              WHERE r.dataid = ?
                AND c.fieldid = ?
                AND c.content = ?',
            [$dataid, $sourcefieldid, $entryid]
        );

        if (count($records) > 1) {
            throw new \coding_exception(
                'Duplicate Moodle Database records exist for one MediaCast source entry.'
            );
        }

        return $records ? reset($records) : false;
    }

    private function upsert_content(
        int $fieldid,
        int $recordid,
        string $content,
        ?string $content1 = null
    ): int {
        global $DB;

        $existing = $DB->get_record(
            'data_content',
            [
                'fieldid' => $fieldid,
                'recordid' => $recordid,
            ]
        );

        $value = (object) [
            'fieldid' => $fieldid,
            'recordid' => $recordid,
            'content' => $content,
        ];
        if ($content1 !== null) {
            $value->content1 = $content1;
        }

        if ($existing) {
            $value->id = (int) $existing->id;
            $DB->update_record('data_content', $value);
            return (int) $existing->id;
        }

        return (int) $DB->insert_record(
            'data_content',
            $value
        );
    }

    private function write_file_content(
        \context_module $context,
        int $fieldid,
        int $recordid,
        string $sourcefile,
        string $filename
    ): void {
        global $DB;

        if ($filename === '' || basename($filename) !== $filename) {
            throw new \coding_exception(
                'Unsafe MediaCast target filename.'
            );
        }

        $contentid = $this->upsert_content(
            $fieldid,
            $recordid,
            $filename
        );

        $fs = get_file_storage();
        $fs->delete_area_files(
            $context->id,
            'mod_data',
            'content',
            $contentid
        );

        $filerecord = [
            'contextid' => $context->id,
            'component' => 'mod_data',
            'filearea' => 'content',
            'itemid' => $contentid,
            'filepath' => '/',
            'filename' => $filename,
        ];

        $stored = $fs->create_file_from_pathname(
            $filerecord,
            $sourcefile
        );

        if (!$stored
                || (int) $stored->get_filesize() !== filesize($sourcefile)) {
            throw new \coding_exception(
                'Moodle Files API did not persist the complete MediaCast MP4.'
            );
        }

        $DB->set_field(
            'data_content',
            'content',
            $filename,
            ['id' => $contentid]
        );
    }

    private function clear_file_content(
        \context_module $context,
        int $fieldid,
        int $recordid
    ): void {
        global $DB;

        $content = $DB->get_record(
            'data_content',
            [
                'fieldid' => $fieldid,
                'recordid' => $recordid,
            ]
        );

        if (!$content) {
            $this->upsert_content(
                $fieldid,
                $recordid,
                ''
            );
            return;
        }

        get_file_storage()->delete_area_files(
            $context->id,
            'mod_data',
            'content',
            (int) $content->id
        );
        $DB->set_field(
            'data_content',
            'content',
            '',
            ['id' => (int) $content->id]
        );
    }

    private function load_structure(array $operation): array {
        $path = $this->resolve_relative_file(
            (string) ($operation['migration_structure_path'] ?? '')
        );
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \coding_exception(
                'Unable to read MediaCast structure.json.'
            );
        }

        try {
            $decoded = json_decode(
                $raw,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \coding_exception(
                'Invalid MediaCast structure.json: '
                . $exception->getMessage()
            );
        }

        if (!is_array($decoded)) {
            throw new \coding_exception(
                'MediaCast structure.json must be an object.'
            );
        }

        return $decoded;
    }

    private function intro_editor(string $text): array {
        return [
            'text' => clean_text($text, FORMAT_HTML),
            'format' => FORMAT_HTML,
            'itemid' => file_get_unused_draft_itemid(),
        ];
    }

    private function save_activity_mapping(
        string $sourcecourse,
        string $sourceref,
        string $sourceobj,
        int $targetid,
        string $sourceversion
    ): void {
        $this->save_mapping(
            $sourcecourse,
            $sourceref,
            $sourceobj,
            'data',
            $targetid,
            $sourceversion
        );
    }

    private function save_record_mapping(
        string $sourcecourse,
        string $mediacastref,
        string $entryid,
        string $mobid,
        int $recordid,
        string $sourceversion
    ): void {
        $this->save_mapping(
            $sourcecourse,
            $mediacastref . ':' . $entryid,
            $mobid,
            'data_record',
            $recordid,
            $sourceversion
        );
    }

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
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => $targettype,
        ];

        $existing = $DB->get_record(
            'local_iliasmigration_map',
            $conditions
        );

        if (!$existing && $this->sourceinstance !== '') {
            $legacy = $conditions;
            $legacy['sourceinstance'] = '';
            $existing = $DB->get_record(
                'local_iliasmigration_map',
                $legacy
            );
        }

        $now = time();
        $record = (object) ($conditions + [
            'sourceversion' =>
                $sourceversion !== '' ? $sourceversion : null,
            'sourceobj' =>
                $sourceobj !== '' ? $sourceobj : null,
            'targetid' => $targetid,
            'status' => 'READY',
            'timemodified' => $now,
        ]);

        if ($existing) {
            $record->id = (int) $existing->id;
            $record->timecreated = (int) $existing->timecreated;
            $DB->update_record(
                'local_iliasmigration_map',
                $record
            );
        } else {
            $record->timecreated = $now;
            $DB->insert_record(
                'local_iliasmigration_map',
                $record
            );
        }
    }

    private function resolve_relative_file(string $relative): string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === ''
                || str_starts_with($relative, '/')
                || str_contains($relative, '../')) {
            throw new \coding_exception(
                'Unsafe MediaCast package-relative path.'
            );
        }

        $candidate = realpath(
            $this->packageroot
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative)
        );

        if ($candidate === false || !is_file($candidate)) {
            throw new \coding_exception(
                'Validated MediaCast package file is missing.'
            );
        }

        if (!str_starts_with(
            $candidate,
            $this->packageroot . DIRECTORY_SEPARATOR
        )) {
            throw new \coding_exception(
                'MediaCast package path escapes the package root.'
            );
        }

        return $candidate;
    }
}

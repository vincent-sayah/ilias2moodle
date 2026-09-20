<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Apply ILIAS Media Pool -> Moodle mod_data.
 *
 * One Media Pool becomes one Database activity and every visible content tree
 * item (mob/pg) becomes one Database record. Folders are preserved as record
 * metadata; technical dummy nodes are not materialized.
 */
final class phase65_media_pool_executor {
    private string $packageroot;
    private string $migrationjson;
    private string $sourceinstance = '';

    private const FIELD_SPECS = [
        'source_tree_id' => [
            'type' => 'text',
            'description' => 'ILIAS Media Pool tree node id',
        ],
        'position' => [
            'type' => 'number',
            'description' => 'ILIAS Media Pool source order',
        ],
        'item_type' => [
            'type' => 'text',
            'description' => 'ILIAS Media Pool normalized item type',
        ],
        'title' => [
            'type' => 'text',
            'description' => 'Media Pool item title',
        ],
        'folder_path' => [
            'type' => 'text',
            'description' => 'ILIAS Media Pool folder path',
        ],
        'media_source_id' => [
            'type' => 'text',
            'description' => 'ILIAS MediaObject source id when applicable',
        ],
        'content' => [
            'type' => 'textarea',
            'description' => 'Rendered Media Pool item content',
        ],
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
        $plan['phase'] = '6.5.8';
        $plan = (
            new phase65_media_pool_package_validator(
                $this->migrationjson
            )
        )->validate($plan);

        $this->assert_applyable($plan);

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourseid = (string) ($document['course']['source_id'] ?? '');
        $sourceversion = (string) ($document['source']['version'] ?? '');

        $courseoperation = $plan['operations'][0] ?? null;
        if (!is_array($courseoperation)
                || ($courseoperation['kind'] ?? '') !== 'course') {
            throw new \coding_exception(
                'Media Pool plan has no Moodle course operation.'
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
                    if (($operation['kind'] ?? '') !== 'media_pool') {
                        $results[] = $operation;
                        continue;
                    }

                    if (($operation['media_pool_validation']['status'] ?? '')
                            === 'SKIPPED_INCREMENTAL') {
                        $results[] = $operation;
                        continue;
                    }

                    $results[] = $this->apply_media_pool(
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
        $plan['phase'] = '6.5.8';
        $plan['phase65_object'] = 'media_pool';
        $plan['operations'] = $results;

        return $plan;
    }

    private function assert_applyable(array $plan): void {
        $package = $plan['phase65_media_pool_package'] ?? [];

        if (empty($package['ready'])
                || empty($package['apply_ready'])
                || empty($package['apply_implemented'])
                || !empty($package['blocked_media_pools'])) {
            throw new \coding_exception(
                'Media Pool package is not ready for apply.'
            );
        }

        foreach ($plan['operations'] as $operation) {
            if (($operation['kind'] ?? '') !== 'media_pool') {
                continue;
            }

            $validation = is_array(
                $operation['media_pool_validation'] ?? null
            ) ? $operation['media_pool_validation'] : [];

            if (($validation['status'] ?? '') === 'SKIPPED_INCREMENTAL') {
                continue;
            }

            if (($validation['status'] ?? '') !== 'READY'
                    || ($validation['code'] ?? '') !== 'MEDIA_POOL_READY') {
                throw new \coding_exception(
                    'Media Pool validation is not ready.'
                );
            }

            if (!in_array(
                (string) ($operation['action'] ?? ''),
                ['CREATE', 'UPDATE'],
                true
            )) {
                throw new \coding_exception(
                    'Media Pool action is not CREATE/UPDATE.'
                );
            }
        }
    }

    private function apply_media_pool(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion
    ): array {
        global $DB;

        $requested = (string) ($operation['action'] ?? '');
        $sectionnumber = (int) (
            $operation['media_pool_parent_validation']['section_number']
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
                    'Moving an existing Media Pool Database activity is not supported.'
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

        $fields = $this->ensure_fields($data);
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

        $this->configure_templates($data);

        $context = \context_module::instance($cmid);
        $recordscreated = 0;
        $recordsupdated = 0;
        $fileswritten = 0;
        $imageswritten = 0;
        $videoswritten = 0;
        $recordids = [];

        foreach ((array) ($structure['records'] ?? []) as $record) {
            if (!is_array($record)) {
                throw new \coding_exception(
                    'Invalid Media Pool record during apply.'
                );
            }

            $treeid = trim((string) ($record['source_tree_id'] ?? ''));
            $existing = $this->find_record_by_source_tree(
                $instanceid,
                (int) $fields['source_tree_id']->id,
                $treeid
            );

            if ($existing === false) {
                $recordid = (int) data_add_record($data, 0);
                if ($recordid <= 0) {
                    throw new \coding_exception(
                        'Unable to create a Moodle Database record for Media Pool.'
                    );
                }
                $recordscreated++;
            } else {
                $recordid = (int) $existing->id;
                $DB->set_field(
                    'data_records',
                    'timemodified',
                    time(),
                    ['id' => $recordid]
                );
                $recordsupdated++;
            }

            $this->upsert_content(
                (int) $fields['source_tree_id']->id,
                $recordid,
                $treeid
            );
            $this->upsert_content(
                (int) $fields['position']->id,
                $recordid,
                (string) ((int) ($record['position'] ?? 0))
            );
            $this->upsert_content(
                (int) $fields['item_type']->id,
                $recordid,
                clean_param(
                    (string) ($record['item_type'] ?? ''),
                    PARAM_ALPHAEXT
                )
            );
            $this->upsert_content(
                (int) $fields['title']->id,
                $recordid,
                clean_param(
                    (string) ($record['title'] ?? ''),
                    PARAM_TEXT
                )
            );

            $folderpath = array_values(array_filter(
                array_map(
                    static fn($part): string => trim((string) $part),
                    (array) ($record['folder_path'] ?? [])
                ),
                static fn(string $part): bool => $part !== ''
            ));
            $this->upsert_content(
                (int) $fields['folder_path']->id,
                $recordid,
                clean_param(
                    implode(' / ', $folderpath),
                    PARAM_TEXT
                )
            );
            $this->upsert_content(
                (int) $fields['media_source_id']->id,
                $recordid,
                clean_param(
                    (string) ($record['media_source_id'] ?? ''),
                    PARAM_TEXT
                )
            );

            $contentid = $this->upsert_content(
                (int) $fields['content']->id,
                $recordid,
                '',
                (string) FORMAT_HTML
            );

            get_file_storage()->delete_area_files(
                $context->id,
                'mod_data',
                'content',
                $contentid
            );

            $single = $structure;
            $single['records'] = [$record];

            $asseturls = [];
            $renderer = new phase65_media_pool_renderer();
            $render = $renderer->render(
                $single,
                function(
                    string $path,
                    array $asset
                ) use (
                    $context,
                    $contentid,
                    &$asseturls,
                    &$fileswritten,
                    &$imageswritten,
                    &$videoswritten,
                    $operation
                ): string {
                    if (isset($asseturls[$path])) {
                        return $asseturls[$path];
                    }

                    $url = $this->write_asset_file(
                        $context,
                        $contentid,
                        $path,
                        (string) ($operation['source_ref_id'] ?? '')
                    );
                    $asseturls[$path] = $url;
                    $fileswritten++;

                    $mime = strtolower(
                        trim((string) ($asset['mime_type'] ?? ''))
                    );
                    if (str_starts_with($mime, 'image/')) {
                        $imageswritten++;
                    } else if ($mime === 'video/mp4') {
                        $videoswritten++;
                    }

                    return $url;
                }
            );

            $rendered = is_array($render['records'] ?? null)
                ? $render['records']
                : [];
            $html = (string) ($rendered[0]['html'] ?? '');
            if (trim($html) === '') {
                throw new \coding_exception(
                    'Media Pool record rendered empty HTML during apply.'
                );
            }

            $this->upsert_content(
                (int) $fields['content']->id,
                $recordid,
                $html,
                (string) FORMAT_HTML
            );

            $sourceobj = (string) ($record['media_source_id'] ?? '');
            if ($sourceobj === '') {
                $sourceobj = $treeid;
            }

            $this->save_record_mapping(
                $sourcecourseid,
                (string) ($operation['source_ref_id'] ?? ''),
                $treeid,
                $sourceobj,
                $recordid,
                $sourceversion
            );
            $recordids[$treeid] = $recordid;
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
        $result['media_files_written'] = $fileswritten;
        $result['image_files_written'] = $imageswritten;
        $result['video_files_written'] = $videoswritten;
        $result['folder_policy'] = 'PRESERVED_AS_RECORD_METADATA';
        $result['preview_policy'] = 'DO_NOT_IMPORT';

        return $result;
    }

    private function ensure_fields(\stdClass $data): array {
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
                if ((string) $existing->type !== (string) $spec['type']) {
                    throw new \coding_exception(
                        "Media Pool field {$name} has an unexpected Moodle type."
                    );
                }
                $result[$name] = $existing;
                continue;
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
            $field->define_field($details);

            if (!$field->insert_field()) {
                throw new \coding_exception(
                    "Unable to create Media Pool field {$name}."
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
<article class="ilias2moodle-media-pool-record">
  <h4>[[title]]</h4>
  <p><small>[[folder_path]]</small></p>
  <div>[[content]]</div>
</article>
<hr>
HTML;

        $single = <<<'HTML'
<article class="ilias2moodle-media-pool-record">
  <h3>[[title]]</h3>
  <p><small>[[folder_path]]</small></p>
  <div>[[content]]</div>
</article>
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
        data_generate_default_template($fresh, 'addtemplate');
        data_generate_default_template($fresh, 'asearchtemplate');
        data_generate_default_template($fresh, 'rsstemplate');
    }

    private function find_record_by_source_tree(
        int $dataid,
        int $sourcefieldid,
        string $treeid
    ): \stdClass|false {
        global $DB;

        $records = $DB->get_records_sql(
            'SELECT r.*
               FROM {data_records} r
               JOIN {data_content} c ON c.recordid = r.id
              WHERE r.dataid = ?
                AND c.fieldid = ?
                AND c.content = ?',
            [$dataid, $sourcefieldid, $treeid]
        );

        if (count($records) > 1) {
            throw new \coding_exception(
                'Duplicate Moodle Database records exist for one Media Pool tree item.'
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

    private function write_asset_file(
        \context_module $context,
        int $contentid,
        string $migrationpath,
        string $mediapoolref
    ): string {
        $sourcefile = $this->resolve_relative_file($migrationpath);
        $relative = ltrim(str_replace('\\', '/', $migrationpath), '/');
        $prefix = $mediapoolref !== ''
            ? 'media_pools/' . $mediapoolref . '/'
            : '';

        if ($prefix !== '' && str_starts_with($relative, $prefix)) {
            $relative = substr($relative, strlen($prefix));
        }

        if ($relative === '' || str_contains($relative, '../')) {
            throw new \coding_exception(
                'Unsafe Media Pool asset target path.'
            );
        }

        $filename = basename($relative);
        $dirname = str_replace('\\', '/', dirname($relative));
        $filepath = ($dirname === '.' || $dirname === '')
            ? '/'
            : '/' . trim($dirname, '/') . '/';

        $stored = get_file_storage()->create_file_from_pathname(
            [
                'contextid' => $context->id,
                'component' => 'mod_data',
                'filearea' => 'content',
                'itemid' => $contentid,
                'filepath' => $filepath,
                'filename' => $filename,
            ],
            $sourcefile
        );

        if (!$stored
                || (int) $stored->get_filesize() !== filesize($sourcefile)) {
            throw new \coding_exception(
                'Moodle Files API did not persist the complete Media Pool asset.'
            );
        }

        return \moodle_url::make_pluginfile_url(
            $stored->get_contextid(),
            $stored->get_component(),
            $stored->get_filearea(),
            $stored->get_itemid(),
            $stored->get_filepath(),
            $stored->get_filename()
        )->out(false);
    }

    private function load_structure(array $operation): array {
        $path = $this->resolve_relative_file(
            (string) ($operation['migration_structure_path'] ?? '')
        );
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new \coding_exception(
                'Unable to read Media Pool structure.json.'
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
                'Invalid Media Pool structure.json: '
                . $exception->getMessage()
            );
        }

        if (!is_array($decoded)) {
            throw new \coding_exception(
                'Media Pool structure.json must be an object.'
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
        string $mediapoolref,
        string $treeid,
        string $sourceobj,
        int $recordid,
        string $sourceversion
    ): void {
        $this->save_mapping(
            $sourcecourse,
            $mediapoolref . ':' . $treeid,
            $sourceobj,
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
                'Unsafe Media Pool package-relative path.'
            );
        }

        $candidate = realpath(
            $this->packageroot
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative)
        );

        if ($candidate === false || !is_file($candidate)) {
            throw new \coding_exception(
                'Validated Media Pool package file is missing.'
            );
        }

        if (!str_starts_with(
            $candidate,
            $this->packageroot . DIRECTORY_SEPARATOR
        )) {
            throw new \coding_exception(
                'Media Pool package path escapes the package root.'
            );
        }

        return $candidate;
    }
}

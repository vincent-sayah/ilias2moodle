<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Apply ILIAS Blog -> Moodle mod_data.
 *
 * One ILIAS Blog becomes one Database activity and every Blog posting becomes
 * one Database record. Source author identifiers are preserved in a field but
 * are not mapped to Moodle users until Phase 7.
 */
final class phase65_blog_executor {
    private string $packageroot;
    private string $migrationjson;
    private string $sourceinstance = '';

    private const FIELD_SPECS = [
        'source_posting_id' => [
            'type' => 'text',
            'description' => 'ILIAS Blog posting source id',
        ],
        'position' => [
            'type' => 'number',
            'description' => 'ILIAS Blog posting source order',
        ],
        'title' => [
            'type' => 'text',
            'description' => 'Blog posting title',
        ],
        'created' => [
            'type' => 'text',
            'description' => 'ILIAS Blog posting creation timestamp',
        ],
        'source_author' => [
            'type' => 'text',
            'description' => 'ILIAS source author export identifier',
        ],
        'keywords' => [
            'type' => 'text',
            'description' => 'ILIAS Blog posting keywords',
        ],
        'content' => [
            'type' => 'textarea',
            'description' => 'Rendered ILIAS Blog posting content',
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
        $plan['phase'] = '6.5.7';
        $plan = (
            new phase65_blog_package_validator($this->migrationjson)
        )->validate($plan);

        $this->assert_applyable($plan);

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourseid = (string) ($document['course']['source_id'] ?? '');
        $sourceversion = (string) ($document['source']['version'] ?? '');

        $courseoperation = $plan['operations'][0] ?? null;
        if (!is_array($courseoperation)
                || ($courseoperation['kind'] ?? '') !== 'course') {
            throw new \coding_exception(
                'Blog plan has no Moodle course operation.'
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
                    if (($operation['kind'] ?? '') !== 'blog') {
                        $results[] = $operation;
                        continue;
                    }

                    if (($operation['blog_validation']['status'] ?? '')
                            === 'SKIPPED_INCREMENTAL') {
                        $results[] = $operation;
                        continue;
                    }

                    $results[] = $this->apply_blog(
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
        $plan['phase'] = '6.5.7';
        $plan['phase65_object'] = 'blog';
        $plan['operations'] = $results;

        return $plan;
    }

    private function assert_applyable(array $plan): void {
        $package = $plan['phase65_blog_package'] ?? [];

        if (empty($package['ready'])
                || empty($package['apply_ready'])
                || empty($package['apply_implemented'])
                || !empty($package['blocked_blogs'])) {
            throw new \coding_exception(
                'Blog package is not ready for apply.'
            );
        }

        foreach ($plan['operations'] as $operation) {
            if (($operation['kind'] ?? '') !== 'blog') {
                continue;
            }

            $validation = is_array($operation['blog_validation'] ?? null)
                ? $operation['blog_validation']
                : [];

            if (($validation['status'] ?? '') === 'SKIPPED_INCREMENTAL') {
                continue;
            }

            if (($validation['status'] ?? '') !== 'READY'
                    || ($validation['code'] ?? '') !== 'BLOG_READY') {
                throw new \coding_exception(
                    'Blog validation is not ready.'
                );
            }

            if (!in_array(
                (string) ($operation['action'] ?? ''),
                ['CREATE', 'UPDATE'],
                true
            )) {
                throw new \coding_exception(
                    'Blog action is not CREATE/UPDATE.'
                );
            }
        }
    }

    private function apply_blog(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion
    ): array {
        global $DB;

        $requested = (string) ($operation['action'] ?? '');
        $sectionnumber = (int) (
            $operation['blog_parent_validation']['section_number']
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
                    'Moving an existing Blog Database activity to another Moodle section is not supported.'
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
        $recordids = [];

        foreach ((array) ($structure['postings'] ?? []) as $posting) {
            if (!is_array($posting)) {
                throw new \coding_exception(
                    'Invalid Blog posting during apply.'
                );
            }

            $postingid = trim((string) ($posting['source_id'] ?? ''));
            $record = $this->find_record_by_source_posting(
                $instanceid,
                (int) $fields['source_posting_id']->id,
                $postingid
            );

            if ($record === false) {
                $recordid = (int) data_add_record($data, 0);
                if ($recordid <= 0) {
                    throw new \coding_exception(
                        'Unable to create a Moodle Database record for Blog.'
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
                (int) $fields['source_posting_id']->id,
                $recordid,
                $postingid
            );
            $this->upsert_content(
                (int) $fields['position']->id,
                $recordid,
                (string) ((int) ($posting['position'] ?? 0))
            );
            $this->upsert_content(
                (int) $fields['title']->id,
                $recordid,
                clean_param(
                    (string) ($posting['title'] ?? ''),
                    PARAM_TEXT
                )
            );
            $this->upsert_content(
                (int) $fields['created']->id,
                $recordid,
                clean_param(
                    (string) ($posting['created'] ?? ''),
                    PARAM_TEXT
                )
            );
            $this->upsert_content(
                (int) $fields['source_author']->id,
                $recordid,
                clean_param(
                    (string) ($posting['author_source'] ?? ''),
                    PARAM_TEXT
                )
            );

            $keywords = array_values(array_filter(
                array_map(
                    static fn($keyword): string =>
                        trim((string) $keyword),
                    (array) ($posting['keywords'] ?? [])
                ),
                static fn(string $keyword): bool => $keyword !== ''
            ));
            $this->upsert_content(
                (int) $fields['keywords']->id,
                $recordid,
                clean_param(
                    implode(', ', $keywords),
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

            $asseturls = [];
            $renderer = new phase65_blog_renderer();
            $render = $renderer->render(
                $posting,
                function(
                    string $path,
                    array $asset
                ) use (
                    $context,
                    $contentid,
                    &$asseturls,
                    &$fileswritten,
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
                    return $url;
                }
            );

            $html = (string) ($render['html'] ?? '');
            if (trim($html) === '') {
                throw new \coding_exception(
                    'Blog posting rendered empty HTML during apply.'
                );
            }

            $this->upsert_content(
                (int) $fields['content']->id,
                $recordid,
                $html,
                (string) FORMAT_HTML
            );

            $this->save_record_mapping(
                $sourcecourseid,
                (string) ($operation['source_ref_id'] ?? ''),
                $postingid,
                $recordid,
                $sourceversion
            );
            $recordids[$postingid] = $recordid;
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
        $result['embedded_image_files_written'] = $fileswritten;
        $result['source_author_policy'] =
            'PRESERVED_FIELD_ONLY_MOODLE_USER_MAPPING_PHASE7';
        $result['record_owner_policy'] =
            'TECHNICAL_MIGRATION_USER_NOT_SOURCE_AUTHOR';

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
                if ((string) $existing->type
                        !== (string) $spec['type']) {
                    throw new \coding_exception(
                        "Blog field {$name} has an unexpected Moodle type."
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
                    "Unable to create Blog field {$name}."
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
<article class="ilias2moodle-blog-posting">
  <h4>[[title]]</h4>
  <p><small>[[created]]</small></p>
  <div>[[content]]</div>
  <p>[[keywords]]</p>
</article>
<hr>
HTML;

        $single = <<<'HTML'
<article class="ilias2moodle-blog-posting">
  <h3>[[title]]</h3>
  <p><small>[[created]]</small></p>
  <div>[[content]]</div>
  <p>[[keywords]]</p>
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

    private function find_record_by_source_posting(
        int $dataid,
        int $sourcefieldid,
        string $postingid
    ): \stdClass|false {
        global $DB;

        $records = $DB->get_records_sql(
            'SELECT r.*
               FROM {data_records} r
               JOIN {data_content} c ON c.recordid = r.id
              WHERE r.dataid = ?
                AND c.fieldid = ?
                AND c.content = ?',
            [$dataid, $sourcefieldid, $postingid]
        );

        if (count($records) > 1) {
            throw new \coding_exception(
                'Duplicate Moodle Database records exist for one Blog posting.'
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

        return (int) $DB->insert_record('data_content', $value);
    }

    private function write_asset_file(
        \context_module $context,
        int $contentid,
        string $migrationpath,
        string $blogref
    ): string {
        $sourcefile = $this->resolve_relative_file($migrationpath);
        $relative = ltrim(str_replace('\\', '/', $migrationpath), '/');
        $prefix = $blogref !== '' ? 'blogs/' . $blogref . '/' : '';

        if ($prefix !== '' && str_starts_with($relative, $prefix)) {
            $relative = substr($relative, strlen($prefix));
        }

        if ($relative === ''
                || str_contains($relative, '../')) {
            throw new \coding_exception(
                'Unsafe Blog asset target path.'
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
                'Moodle Files API did not persist the complete Blog asset.'
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
                'Unable to read Blog structure.json.'
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
                'Invalid Blog structure.json: '
                . $exception->getMessage()
            );
        }

        if (!is_array($decoded)) {
            throw new \coding_exception(
                'Blog structure.json must be an object.'
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
        string $blogref,
        string $postingid,
        int $recordid,
        string $sourceversion
    ): void {
        $this->save_mapping(
            $sourcecourse,
            $blogref . ':' . $postingid,
            $postingid,
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
                'Unsafe Blog package-relative path.'
            );
        }

        $candidate = realpath(
            $this->packageroot
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative)
        );

        if ($candidate === false || !is_file($candidate)) {
            throw new \coding_exception(
                'Validated Blog package file is missing.'
            );
        }

        if (!str_starts_with(
            $candidate,
            $this->packageroot . DIRECTORY_SEPARATOR
        )) {
            throw new \coding_exception(
                'Blog package path escapes the package root.'
            );
        }

        return $candidate;
    }
}

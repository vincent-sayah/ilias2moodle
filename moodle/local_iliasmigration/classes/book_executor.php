<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Executes Phase 5 ILIAS Learning Module -> Moodle Book migration.
 *
 * Book activity creation/update uses Moodle's module APIs. Chapter creation is
 * delegated to the core booktool_importhtml implementation so this local plugin
 * does not write pedagogical Book content directly to Moodle core tables.
 *
 * For the first POC, an unchanged mapped Book is idempotent: the deterministic
 * import source paths contain a source-content fingerprint, so a second apply
 * reuses the same CMID/instance without appending duplicate chapters. A changed
 * Learning Module is refused until Moodle exposes (or the project implements)
 * an equally safe in-place chapter replacement path.
 */
final class book_executor {
    /** @var string Canonical migration package root. */
    private string $packageroot;

    /** @var string Absolute migration.json path used by validators. */
    private string $migrationjson;

    /** @var string Stable ILIAS source instance identity. */
    private string $sourceinstance = '';

    /**
     * @param string $migrationjson Absolute path to migration.json.
     */
    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        $file = realpath($migrationjson);
        if ($root === false || !is_dir($root) || $file === false || !is_file($file)) {
            throw new \coding_exception('Unable to resolve migration.json or its package directory.');
        }
        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
        $this->migrationjson = $file;
    }

    /**
     * Create or verify/update Phase 5 Moodle Book activities.
     *
     * @param array $document Validated migration document.
     * @param int $categoryid Moodle target course category id.
     * @return array Execution report.
     */
    public function execute(array $document, int $categoryid): array {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/book/lib.php');
        require_once($CFG->dirroot . '/mod/book/locallib.php');
        require_once($CFG->libdir . '/filelib.php');

        $importhtmllib = $CFG->dirroot . '/mod/book/tool/importhtml/locallib.php';
        if (!is_readable($importhtmllib)) {
            throw new \coding_exception(
                'Moodle core booktool_importhtml is required for Phase 5 Book chapter creation.'
            );
        }
        require_once($importhtmllib);

        $planner = new phase5_plan_builder($categoryid);
        $plan = $planner->build($document);
        $phase3validator = new phase3_package_validator($this->migrationjson);
        $plan = $phase3validator->validate($plan);
        $phase4validator = new phase4_package_validator($this->migrationjson);
        $plan = $phase4validator->validate($plan);
        $phase5validator = new phase5_package_validator($this->migrationjson);
        $plan = $phase5validator->validate($plan);
        $this->assert_applyable($plan);

        $this->sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourseid = (string) $document['course']['source_id'];
        $sourceversion = (string) ($document['source']['version'] ?? '');

        $courseoperation = $plan['operations'][0] ?? null;
        if (!is_array($courseoperation) || ($courseoperation['kind'] ?? '') !== 'course') {
            throw new \coding_exception('Phase 5 execution plan does not contain a Moodle course operation.');
        }
        $courseid = (int) ($courseoperation['target_id'] ?? 0);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        $originaluser = $USER;
        \core\session\manager::set_user(get_admin());

        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $results = [];
                foreach ($plan['operations'] as $operation) {
                    if (($operation['kind'] ?? '') !== 'learning_module') {
                        $results[] = $operation;
                        continue;
                    }

                    $sectionnumber = $this->resolve_parent_section_number(
                        $course,
                        (string) ($operation['parent_source_ref_id'] ?? ''),
                        $sourcecourseid
                    );
                    $results[] = $this->apply_book(
                        $course,
                        $operation,
                        $sourcecourseid,
                        $sourceversion,
                        $sectionnumber
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
        $plan['course']['target_id'] = (int) $course->id;
        $plan['operations'] = $results;
        $plan['phase5_package']['ready'] = true;
        $plan['warnings'][] = [
            'code' => 'COURSE_REMAINS_HIDDEN',
            'message' => 'The imported course remains hidden until later migration phases are validated.',
        ];

        return $plan;
    }

    /** Refuse apply unless all earlier phases and Learning Module checks are ready. */
    private function assert_applyable(array $plan): void {
        if (empty($plan['phase5_package']['ready'])
                || !empty($plan['phase5_package']['blocked_learning_modules'])) {
            throw new \coding_exception('Phase 5 package validation failed; apply is refused.');
        }
        if (empty($plan['phase5_prerequisites']['ready'])
                || !empty($plan['phase5_prerequisites']['pending_count'])) {
            throw new \coding_exception('Phase 5 prerequisites are not synchronized; apply is refused.');
        }

        $bookcount = 0;
        foreach ($plan['operations'] as $operation) {
            $kind = (string) ($operation['kind'] ?? '');
            $action = (string) ($operation['action'] ?? '');

            if (in_array(
                $kind,
                ['course', 'section', 'subsection', 'file', 'url', 'html_module', 'scorm'],
                true
            )) {
                if ($action !== 'UPDATE' || empty($operation['target_id'])) {
                    throw new \coding_exception(
                        'Phase 5 requires Phase 2/3/4 objects to exist first; synchronize earlier phases before Book.'
                    );
                }
                continue;
            }

            if ($kind === 'learning_module') {
                $bookcount++;
                if (!in_array($action, ['CREATE', 'UPDATE'], true)) {
                    throw new \coding_exception("Cannot apply Phase 5 Book: planned action is {$action}.");
                }
                $internal = (int) (
                    $operation['structure_validation']['block_counts']['internal_link'] ?? 0
                );
                if ($internal > 0) {
                    throw new \coding_exception(
                        'Phase 5 apply currently refuses Learning Modules containing internal links until '
                        . 'their target representation is normalized and tested.'
                    );
                }
                continue;
            }

            if (in_array(
                $action,
                ['BLOCKED', 'FLATTEN_REQUIRED', 'ERROR_STALE_MAPPING', 'ERROR_MAPPING_TYPE', 'CONFLICT'],
                true
            )) {
                throw new \coding_exception("Cannot apply Phase 5 while operation {$kind} is {$action}.");
            }
        }

        if ($bookcount === 0) {
            throw new \coding_exception('Phase 5 apply requires at least one Learning Module CREATE/UPDATE operation.');
        }
    }

    /** Create or idempotently update one Moodle Book. */
    private function apply_book(
        \stdClass $course,
        array $operation,
        string $sourcecourseid,
        string $sourceversion,
        int $sectionnumber
    ): array {
        global $DB;

        $requested = (string) $operation['action'];
        $structure = $this->load_structure($operation);
        $import = $this->build_import_plan($operation, $structure);
        $description = (string) ($operation['description'] ?? '');
        $config = get_config('book');
        $numbering = isset($config->numbering) ? (int) $config->numbering : 0;

        $moduledata = (object) [
            'modulename' => 'book',
            'course' => (int) $course->id,
            'section' => $sectionnumber,
            'visible' => 1,
            'name' => (string) $operation['title'],
            'introeditor' => $this->build_intro_editor($description),
            'intro' => $description,
            'introformat' => FORMAT_HTML,
            'numbering' => $numbering,
            'customtitles' => 0,
        ];

        $contentreimported = false;
        if ($requested === 'CREATE') {
            $created = create_module($moduledata);
            $cmid = (int) $created->coursemodule;
            $instanceid = (int) $created->instance;
            $performed = 'CREATED';

            $book = $DB->get_record(
                'book',
                ['id' => $instanceid, 'course' => (int) $course->id],
                '*',
                MUST_EXIST
            );
            $context = \context_module::instance($cmid);
            $package = $this->build_import_zip($operation, $import);
            try {
                toolbook_importhtml_import_chapters($package, 2, $book, $context, false);
                $contentreimported = true;
            } finally {
                $package->delete();
            }
        } else {
            $cmid = (int) $operation['target_id'];
            $cm = get_coursemodule_from_id('book', $cmid, $course->id, false, MUST_EXIST);
            $this->assert_module_section($cm, $course, $sectionnumber);
            $instanceid = (int) $cm->instance;
            $book = $DB->get_record(
                'book',
                ['id' => $instanceid, 'course' => (int) $course->id],
                '*',
                MUST_EXIST
            );

            if (!$this->existing_chapters_match_import($book, $import)) {
                throw new \coding_exception(
                    'Phase 5 mapped Book content differs from the current Learning Module fingerprint. '
                    . 'Safe in-place chapter replacement is not enabled yet; apply is refused to avoid duplicates.'
                );
            }

            $metadatachanged = (string) $book->name !== $moduledata->name
                || (string) $book->intro !== $description;
            if ($metadatachanged) {
                [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
                $moduleinfo->name = $moduledata->name;
                $moduleinfo->introeditor = $moduledata->introeditor;
                $moduleinfo->numbering = (int) $book->numbering;
                $moduleinfo->customtitles = (int) $book->customtitles;
                update_module($moduleinfo);
            }
            $performed = 'UPDATED';
        }

        $verification = $this->verify_book(
            $course,
            $cmid,
            $instanceid,
            $sectionnumber,
            $import
        );

        $this->save_mapping(
            $sourcecourseid,
            (string) $operation['source_ref_id'],
            (string) ($operation['source_obj_id'] ?? ''),
            'book',
            $cmid,
            $sourceversion
        );

        $result = $operation;
        $result['requested_action'] = $requested;
        $result['action'] = $performed;
        $result['target_id'] = $cmid;
        $result['instance_id'] = $instanceid;
        $result['moodle_section'] = $sectionnumber;
        $result['source_content_fingerprint'] = $import['fingerprint'];
        $result['content_reimported'] = $contentreimported;
        $result['book_revision'] = $verification['revision'];
        $result['moodle_chapter_count'] = $verification['chapter_count'];
        $result['moodle_subchapter_count'] = $verification['subchapter_count'];
        $result['moodle_chapter_file_count'] = $verification['chapter_file_count'];
        $result['first_chapter_id'] = $verification['first_chapter_id'];

        return $result;
    }

    /** Build the editor payload required by create_module()/update_module(). */
    private function build_intro_editor(string $text): array {
        return [
            'text' => $text,
            'format' => FORMAT_HTML,
            'itemid' => file_get_unused_draft_itemid(),
        ];
    }

    /** Load and revalidate one normalized structure.json during apply. */
    private function load_structure(array $operation): array {
        $relative = (string) ($operation['migration_structure_path'] ?? '');
        $resolved = $this->resolve_relative_file($relative);
        $raw = file_get_contents($resolved);
        if ($raw === false) {
            throw new \coding_exception('Unable to read validated Learning Module structure.json during apply.');
        }
        try {
            $structure = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \coding_exception('Learning Module structure.json became invalid before apply.');
        }
        if (!is_array($structure) || ($structure['schema_version'] ?? '') !== '1.0') {
            throw new \coding_exception('Learning Module structure schema changed before apply.');
        }
        return $structure;
    }

    /**
     * Build deterministic Book HTML chapter files and referenced asset archive paths.
     *
     * @return array Import descriptor.
     */
    private function build_import_plan(array $operation, array $structure): array {
        $preview = is_array($operation['book_preview'] ?? null) ? $operation['book_preview'] : [];
        $entries = is_array($preview['entries'] ?? null) ? $preview['entries'] : [];
        if (!$entries) {
            throw new \coding_exception('Validated Learning Module does not contain a Moodle Book preview.');
        }

        $fingerprint = $this->content_fingerprint($structure);
        $archivefiles = [];
        $expected = [];
        $assetreferences = 0;
        $pages = is_array($structure['pages'] ?? null) ? $structure['pages'] : [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new \coding_exception('Invalid Moodle Book preview entry during apply.');
            }
            $pagenum = (int) ($entry['pagenum'] ?? 0);
            $sourceid = (string) ($entry['source_id'] ?? '');
            $title = (string) ($entry['title'] ?? '');
            $subchapter = !empty($entry['subchapter']) ? 1 : 0;
            if ($pagenum <= 0 || $sourceid === '' || $title === '') {
                throw new \coding_exception('Moodle Book preview entry is incomplete during apply.');
            }

            $safeid = preg_replace('/[^A-Za-z0-9_-]+/', '_', $sourceid);
            if ($safeid === null || $safeid === '') {
                $safeid = 'node';
            }
            $suffix = $subchapter ? '_sub' : '';
            $filename = sprintf('%03d_%s_%s%s.html', $pagenum, $safeid, $fingerprint, $suffix);

            $chapterassets = [];
            if (($entry['source_type'] ?? '') === 'chapter') {
                $body = '<p class="ilias2moodle-chapter-marker" data-source-id="'
                    . s($sourceid) . '">&nbsp;</p>';
            } else {
                $page = is_array($pages[$sourceid] ?? null) ? $pages[$sourceid] : [];
                $blocks = is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
                $body = $this->render_blocks($blocks, $structure, $archivefiles, $chapterassets);
                if (trim($body) === '') {
                    $body = '<p>&nbsp;</p>';
                }
            }

            $archivefiles[$filename] = [$this->html_document($title, $body)];
            $expected[] = [
                'pagenum' => $pagenum,
                'source_id' => $sourceid,
                'title' => $title,
                'subchapter' => $subchapter,
                'importsrc' => '/' . $filename,
                'asset_reference_count' => count($chapterassets),
            ];
            $assetreferences += count($chapterassets);
        }

        return [
            'fingerprint' => $fingerprint,
            'archive_files' => $archivefiles,
            'expected_chapters' => $expected,
            'expected_asset_reference_count' => $assetreferences,
        ];
    }

    /** Compute a content fingerprint including actual asset bytes. */
    private function content_fingerprint(array $structure): string {
        $canonical = [
            'nodes' => $structure['nodes'] ?? [],
            'pages' => $structure['pages'] ?? [],
            'media' => $structure['media'] ?? [],
            'files' => $structure['files'] ?? [],
        ];
        $encoded = json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($encoded === false) {
            throw new \coding_exception('Unable to serialize Learning Module content for fingerprinting.');
        }

        $parts = [hash('sha256', $encoded)];
        $assetpaths = [];
        foreach ((array) ($structure['media'] ?? []) as $descriptor) {
            if (!is_array($descriptor)) {
                continue;
            }
            foreach ((array) ($descriptor['items'] ?? []) as $item) {
                if (is_array($item) && !empty($item['migration_path'])) {
                    $assetpaths[] = (string) $item['migration_path'];
                }
            }
        }
        foreach ((array) ($structure['files'] ?? []) as $descriptor) {
            if (is_array($descriptor) && !empty($descriptor['migration_path'])) {
                $assetpaths[] = (string) $descriptor['migration_path'];
            }
        }
        sort($assetpaths, SORT_STRING);
        foreach ($assetpaths as $relative) {
            $resolved = $this->resolve_relative_file($relative);
            $hash = hash_file('sha256', $resolved);
            if ($hash === false) {
                throw new \coding_exception('Unable to hash a Learning Module asset during apply.');
            }
            $parts[] = $relative . ':' . $hash;
        }

        return hash('sha256', implode("\n", $parts));
    }

    /** Render a list of neutral blocks into Moodle-safe HTML. */
    private function render_blocks(
        array $blocks,
        array $structure,
        array &$archivefiles,
        array &$chapterassets
    ): string {
        $html = '';
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $html .= $this->render_block($block, $structure, $archivefiles, $chapterassets);
        }
        return $html;
    }

    /** Render one supported neutral block. */
    private function render_block(
        array $block,
        array $structure,
        array &$archivefiles,
        array &$chapterassets
    ): string {
        $type = (string) ($block['type'] ?? '');

        if ($type === 'paragraph') {
            $text = (string) ($block['text'] ?? '');
            return '<p>' . nl2br(s($text), false) . '</p>';
        }

        if ($type === 'media') {
            return $this->render_media($block, $structure, $archivefiles, $chapterassets);
        }

        if ($type === 'file_list') {
            return $this->render_file_list($block, $structure, $archivefiles, $chapterassets);
        }

        if ($type === 'table') {
            $html = '<table><tbody>';
            foreach ((array) ($block['rows'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>' . $this->render_blocks(
                        is_array($cell) ? $cell : [],
                        $structure,
                        $archivefiles,
                        $chapterassets
                    ) . '</td>';
                }
                $html .= '</tr>';
            }
            return $html . '</tbody></table>';
        }

        if ($type === 'section') {
            $characteristic = clean_param((string) ($block['characteristic'] ?? ''), PARAM_ALPHANUMEXT);
            $class = 'ilias-section';
            if ($characteristic !== '') {
                $class .= ' ilias-section-' . strtolower($characteristic);
            }
            return '<div class="' . s($class) . '">'
                . $this->render_blocks(
                    is_array($block['blocks'] ?? null) ? $block['blocks'] : [],
                    $structure,
                    $archivefiles,
                    $chapterassets
                )
                . '</div>';
        }

        if ($type === 'internal_link') {
            throw new \coding_exception(
                'Internal Learning Module links reached the renderer although Phase 5 apply should have refused them.'
            );
        }

        throw new \coding_exception("Unsupported Learning Module block reached renderer: {$type}.");
    }

    /** Render a media block and add the selected media file to the import ZIP. */
    private function render_media(
        array $block,
        array $structure,
        array &$archivefiles,
        array &$chapterassets
    ): string {
        $sourceid = (string) ($block['source_id'] ?? '');
        $media = is_array($structure['media'][$sourceid] ?? null)
            ? $structure['media'][$sourceid]
            : [];
        $items = array_values(array_filter((array) ($media['items'] ?? []), 'is_array'));
        if (!$items) {
            throw new \coding_exception("Learning Module media {$sourceid} has no usable item during apply.");
        }

        usort($items, static function(array $left, array $right): int {
            $leftstandard = strcasecmp((string) ($left['purpose'] ?? ''), 'Standard') === 0 ? 0 : 1;
            $rightstandard = strcasecmp((string) ($right['purpose'] ?? ''), 'Standard') === 0 ? 0 : 1;
            return $leftstandard <=> $rightstandard;
        });
        $item = $items[0];
        $relative = (string) ($item['migration_path'] ?? '');
        $resolved = $this->resolve_relative_file($relative);
        $filename = clean_filename(basename($resolved));
        if ($filename === '') {
            $filename = 'media-' . $sourceid;
        }
        $archivepath = 'assets/media/' . rawurlencode($sourceid) . '/' . $filename;
        $archivefiles[$archivepath] = $resolved;
        $chapterassets[$archivepath] = true;

        $mime = strtolower((string) ($item['mime_type'] ?? ''));
        $alt = (string) ($item['text_representation'] ?? '');
        if ($alt === '') {
            $alt = (string) ($media['title'] ?? $filename);
        }

        if (str_starts_with($mime, 'image/')) {
            return '<p><img src="' . s($archivepath) . '" alt="' . s($alt) . '"></p>';
        }
        if (str_starts_with($mime, 'audio/')) {
            return '<p><audio controls src="' . s($archivepath) . '">'
                . s($alt) . '</audio></p>';
        }
        if (str_starts_with($mime, 'video/')) {
            return '<p><video controls src="' . s($archivepath) . '">'
                . s($alt) . '</video></p>';
        }

        return '<p><a href="' . s($archivepath) . '">' . s($alt) . '</a></p>';
    }

    /** Render a FileList and add referenced files to the import ZIP. */
    private function render_file_list(
        array $block,
        array $structure,
        array &$archivefiles,
        array &$chapterassets
    ): string {
        $html = '';
        $title = trim((string) ($block['title'] ?? ''));
        if ($title !== '') {
            $html .= '<h3>' . s($title) . '</h3>';
        }
        $html .= '<ul>';

        foreach ((array) ($block['files'] ?? []) as $fileitem) {
            if (!is_array($fileitem)) {
                continue;
            }
            $sourceid = (string) ($fileitem['source_id'] ?? '');
            $descriptor = is_array($structure['files'][$sourceid] ?? null)
                ? $structure['files'][$sourceid]
                : [];
            $relative = (string) ($descriptor['migration_path'] ?? '');
            $resolved = $this->resolve_relative_file($relative);
            $filename = clean_filename(basename($resolved));
            if ($filename === '') {
                $filename = 'file-' . $sourceid;
            }
            $archivepath = 'assets/files/' . rawurlencode($sourceid) . '/' . $filename;
            $archivefiles[$archivepath] = $resolved;
            $chapterassets[$archivepath] = true;

            $label = (string) ($descriptor['title'] ?? '');
            if ($label === '') {
                $label = (string) ($descriptor['filename'] ?? $filename);
            }
            $html .= '<li><a href="' . s($archivepath) . '">' . s($label) . '</a></li>';
        }

        return $html . '</ul>';
    }

    /** Wrap rendered chapter content in the HTML document expected by booktool_importhtml. */
    private function html_document(string $title, string $body): string {
        $title = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $title) ?? $title;
        return '<!doctype html><html><head><meta charset="UTF-8"><title>'
            . s($title) . '</title></head><body>' . $body . '</body></html>';
    }

    /** Create a Moodle user-draft ZIP consumed by core booktool_importhtml. */
    private function build_import_zip(array $operation, array $import): \stored_file {
        global $USER;

        $files = is_array($import['archive_files'] ?? null) ? $import['archive_files'] : [];
        if (!$files) {
            throw new \coding_exception('Learning Module Book import contains no files.');
        }

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($USER->id);
        $filename = clean_filename(
            'ilias2moodle-book-' . (string) ($operation['source_ref_id'] ?? 'module') . '.zip'
        );
        $packer = get_file_packer('application/zip');
        $stored = $packer->archive_to_storage(
            $files,
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            '/',
            $filename,
            $USER->id,
            false
        );
        if (!$stored instanceof \stored_file) {
            throw new \coding_exception('Moodle could not build the temporary Book import ZIP.');
        }
        return $stored;
    }

    /** Verify an UPDATE points at exactly the deterministic chapters already imported. */
    private function existing_chapters_match_import(\stdClass $book, array $import): bool {
        global $DB;

        $existing = array_values($DB->get_records(
            'book_chapters',
            ['bookid' => (int) $book->id],
            'pagenum ASC'
        ));
        $expected = is_array($import['expected_chapters'] ?? null)
            ? $import['expected_chapters']
            : [];
        if (count($existing) !== count($expected)) {
            return false;
        }

        foreach ($expected as $index => $descriptor) {
            $chapter = $existing[$index];
            if ((int) $chapter->pagenum !== (int) $descriptor['pagenum']
                    || (int) $chapter->subchapter !== (int) $descriptor['subchapter']
                    || (string) $chapter->title !== (string) $descriptor['title']
                    || (string) $chapter->importsrc !== (string) $descriptor['importsrc']) {
                return false;
            }
        }
        return true;
    }

    /** Verify the Book module, deterministic TOC and File API assets. */
    private function verify_book(
        \stdClass $course,
        int $cmid,
        int $instanceid,
        int $expectedsectionnumber,
        array $import
    ): array {
        global $DB;

        $cm = get_coursemodule_from_id('book', $cmid, $course->id, false, MUST_EXIST);
        $this->assert_module_section($cm, $course, $expectedsectionnumber);
        if ((int) $cm->instance !== $instanceid) {
            throw new \coding_exception('Created/updated Book course-module instance does not match the expected instance id.');
        }

        $book = $DB->get_record(
            'book',
            ['id' => $instanceid, 'course' => (int) $course->id],
            '*',
            MUST_EXIST
        );
        if (!$this->existing_chapters_match_import($book, $import)) {
            throw new \coding_exception('Moodle Book chapter order/content identity differs from the deterministic Phase 5 plan.');
        }

        $chapters = array_values($DB->get_records(
            'book_chapters',
            ['bookid' => $instanceid],
            'pagenum ASC'
        ));
        $subchapters = 0;
        foreach ($chapters as $chapter) {
            $subchapters += !empty($chapter->subchapter) ? 1 : 0;
            if (trim((string) $chapter->content) === '') {
                throw new \coding_exception('Moodle Book contains an unexpectedly empty imported chapter.');
            }
        }

        $context = \context_module::instance($cmid);
        $fs = get_file_storage();
        $chapterfiles = $fs->get_area_files(
            $context->id,
            'mod_book',
            'chapter',
            false,
            'id',
            false
        );
        $expectedfiles = (int) ($import['expected_asset_reference_count'] ?? 0);
        if (count($chapterfiles) !== $expectedfiles) {
            throw new \coding_exception(
                'Moodle Book File API chapter asset count differs from the deterministic import plan.'
            );
        }

        return [
            'revision' => (int) $book->revision,
            'chapter_count' => count($chapters),
            'subchapter_count' => $subchapters,
            'chapter_file_count' => count($chapterfiles),
            'first_chapter_id' => $chapters ? (int) $chapters[0]->id : null,
        ];
    }

    /** Resolve the Moodle section number for an activity parent. */
    private function resolve_parent_section_number(
        \stdClass $course,
        string $parentsourceref,
        string $sourcecourseid
    ): int {
        global $DB;

        if ($parentsourceref === '') {
            return 0;
        }

        $sectionmapping = $this->find_mapping($sourcecourseid, $parentsourceref, 'section');
        if ($sectionmapping) {
            $section = $DB->get_record(
                'course_sections',
                ['id' => (int) $sectionmapping->targetid, 'course' => (int) $course->id],
                'id,section',
                MUST_EXIST
            );
            return (int) $section->section;
        }

        $subsectionmapping = $this->find_mapping($sourcecourseid, $parentsourceref, 'subsection');
        if ($subsectionmapping) {
            $cm = get_coursemodule_from_id(
                'subsection',
                (int) $subsectionmapping->targetid,
                (int) $course->id,
                false,
                MUST_EXIST
            );
            $delegated = $DB->get_record(
                'course_sections',
                [
                    'course' => (int) $course->id,
                    'component' => 'mod_subsection',
                    'itemid' => (int) $cm->instance,
                ],
                'id,section',
                MUST_EXIST
            );
            return (int) $delegated->section;
        }

        throw new \coding_exception("No Moodle section mapping exists for ILIAS parent ref_id {$parentsourceref}.");
    }

    /** Ensure an updated Book remains in its expected section. */
    private function assert_module_section(
        \stdClass $cm,
        \stdClass $course,
        int $expectedsectionnumber
    ): void {
        global $DB;

        $section = $DB->get_record(
            'course_sections',
            ['id' => (int) $cm->section, 'course' => (int) $course->id],
            'id,section',
            MUST_EXIST
        );
        if ((int) $section->section !== $expectedsectionnumber) {
            throw new \coding_exception('Moving an existing Phase 5 Book to another Moodle section is not supported yet.');
        }
    }

    /** Find a mapping for the current source instance, with legacy fallback. */
    private function find_mapping(
        string $sourcecourse,
        string $sourceref,
        string $targettype
    ): \stdClass|false {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $sourceref,
            'targettype' => $targettype,
        ];
        $mapping = $DB->get_record('local_iliasmigration_map', $conditions);
        if ($mapping) {
            return $mapping;
        }

        $conditions['sourceinstance'] = '';
        return $DB->get_record('local_iliasmigration_map', $conditions);
    }

    /** Insert or refresh one Moodle Book mapping row. */
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
        $existing = $DB->get_record('local_iliasmigration_map', $conditions);
        if (!$existing && $this->sourceinstance !== '') {
            $legacy = $conditions;
            $legacy['sourceinstance'] = '';
            $existing = $DB->get_record('local_iliasmigration_map', $legacy);
        }

        $now = time();
        $record = (object) ($conditions + [
            'sourceversion' => $sourceversion !== '' ? $sourceversion : null,
            'sourceobj' => $sourceobj !== '' ? $sourceobj : null,
            'targetid' => $targetid,
            'status' => 'READY',
            'timemodified' => $now,
        ]);

        if ($existing) {
            $record->id = (int) $existing->id;
            $record->timecreated = (int) $existing->timecreated;
            $DB->update_record('local_iliasmigration_map', $record);
            return;
        }

        $record->timecreated = $now;
        $DB->insert_record('local_iliasmigration_map', $record);
    }

    /** Resolve and revalidate a package-relative Learning Module file during apply. */
    private function resolve_relative_file(string $relative): string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_starts_with($relative, '/')) {
            throw new \coding_exception('Unsafe empty/absolute Learning Module migration path.');
        }
        if (preg_match('/^[A-Za-z]:\//', $relative) === 1) {
            throw new \coding_exception('Unsafe Windows absolute Learning Module migration path.');
        }

        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \coding_exception('Unsafe Learning Module migration path traversal.');
            }
        }

        $candidate = $this->packageroot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        $resolved = realpath($candidate);
        if ($resolved === false
                || !str_starts_with($resolved, $this->packageroot . DIRECTORY_SEPARATOR)
                || !is_file($resolved)
                || is_link($candidate)) {
            throw new \coding_exception(
                'Learning Module migration path is missing or resolves outside the package root.'
            );
        }
        return $resolved;
    }
}

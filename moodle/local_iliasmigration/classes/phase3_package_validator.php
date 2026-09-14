<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates Phase 3 resource inputs without writing Moodle content.
 */
final class phase3_package_validator {
    /** @var string Canonical migration package root. */
    private string $packageroot;

    /** @var string Canonical source ILIAS instance URL. */
    private string $sourceinstance = '';

    /** @var string ILIAS source course ref_id. */
    private string $sourcecourse = '';

    /** @var int Existing Moodle target course id, when already mapped. */
    private int $targetcourseid = 0;

    /**
     * @param string $migrationjson Absolute path to migration.json.
     */
    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        if ($root === false || !is_dir($root)) {
            throw new \coding_exception('Unable to resolve the migration package directory.');
        }
        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
    }

    /**
     * Validate all Phase 3 resource operations and annotate the dry-run plan.
     *
     * Invalid resources are marked BLOCKED. No file is copied into Moodle.
     *
     * @param array $plan Dry-run plan.
     * @return array Annotated plan.
     */
    public function validate(array $plan): array {
        $blocked = 0;
        $checked = 0;
        $this->sourceinstance = rtrim(trim((string) ($plan['source']['instance'] ?? '')), '/');
        $this->sourcecourse = (string) ($plan['course']['source_id'] ?? '');
        $this->targetcourseid = $this->target_course_id($plan);

        foreach ($plan['operations'] as &$operation) {
            $kind = (string) ($operation['kind'] ?? '');
            $action = (string) ($operation['action'] ?? '');

            if (!in_array($kind, ['file', 'url', 'html_module'], true)
                    || !in_array($action, ['CREATE', 'UPDATE'], true)) {
                continue;
            }

            $checked++;
            if ($kind === 'url') {
                $this->validate_url($operation);
                if (($operation['package_validation']['code'] ?? '') === 'ILIAS_INTERNAL_LINK') {
                    $decision = $this->rewrite_internal_url($operation);
                    if (($decision['action'] ?? '') !== 'REWRITE') {
                        $reason = (string) ($decision['reason'] ?? 'TARGET_NOT_MIGRATED');
                        $message = $reason === 'AMBIGUOUS_TARGET'
                            ? 'Multiple migrated Moodle targets match this ILIAS internal Web Link; '
                                . 'the source ILIAS fallback is preserved instead of guessing.'
                            : 'No unique migrated Moodle target exists yet for this ILIAS internal Web Link; '
                                . 'the source ILIAS fallback is preserved.';
                        $plan['warnings'][] = [
                            'code' => 'ILIAS_INTERNAL_LINK_PRESERVED',
                            'source_ref_id' => (string) ($operation['source_ref_id'] ?? ''),
                            'source_target' => (string) ($operation['source_url'] ?? ''),
                            'resolved_url' => (string) ($operation['resolved_url'] ?? ''),
                            'reason' => $reason,
                            'message' => $message,
                        ];
                    }
                }
            } else if ($kind === 'file') {
                $this->validate_file($operation);
            } else {
                $this->validate_html_module($operation);
            }

            if (($operation['action'] ?? '') === 'BLOCKED') {
                $blocked++;
            }
        }
        unset($operation);

        $plan['phase3_package'] = [
            'root' => $this->packageroot,
            'checked_resources' => $checked,
            'blocked_resources' => $blocked,
            'ready' => $blocked === 0,
        ];

        return $plan;
    }

    /**
     * Validate one URL resource.
     *
     * ILIAS exports two relevant Web Link forms:
     * - external links as regular http/https URLs;
     * - internal links as "type|ref_id" (for example "blog|131").
     *
     * Internal links first resolve to an ILIAS permanent-link fallback. The
     * validator then replaces that fallback with a Moodle URL when one unique,
     * already migrated target mapping can be proven safe.
     *
     * @param array $operation Operation being validated.
     */
    private function validate_url(array &$operation): void {
        $url = trim((string) ($operation['source_url'] ?? ''));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false
                && in_array($scheme, ['http', 'https'], true)) {
            $operation['resolved_url'] = $url;
            $operation['package_validation'] = [
                'status' => 'OK',
                'code' => 'EXTERNAL_URL',
                'resolved_url' => $url,
                'url_scheme' => $scheme,
            ];
            return;
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*)\|([1-9][0-9]*)$/', $url, $matches) === 1) {
            $basescheme = strtolower((string) parse_url($this->sourceinstance, PHP_URL_SCHEME));
            if ($this->sourceinstance === ''
                    || filter_var($this->sourceinstance, FILTER_VALIDATE_URL) === false
                    || !in_array($basescheme, ['http', 'https'], true)) {
                $this->block(
                    $operation,
                    'ILIAS_INTERNAL_LINK_NO_SOURCE_INSTANCE',
                    'An ILIAS internal Web Link requires a valid source instance http/https URL.'
                );
                return;
            }

            $type = strtolower((string) $matches[1]);
            $refid = (string) $matches[2];
            $target = $type . '_' . $refid;
            $resolved = $this->sourceinstance . '/goto.php?target=' . rawurlencode($target);

            $operation['resolved_url'] = $resolved;
            $operation['ilias_internal_target'] = [
                'type' => $type,
                'ref_id' => $refid,
            ];
            $operation['package_validation'] = [
                'status' => 'OK',
                'code' => 'ILIAS_INTERNAL_LINK',
                'source_target' => $url,
                'resolved_url' => $resolved,
                'url_scheme' => $basescheme,
            ];
            return;
        }

        $this->block(
            $operation,
            'URL_MISSING_OR_INVALID',
            'A valid http/https URL or ILIAS internal target type|ref_id is required.'
        );
    }

    /**
     * Rewrite one validated ILIAS internal link when a unique Moodle target exists.
     *
     * @param array $operation Validated URL operation.
     * @return array Policy decision.
     */
    private function rewrite_internal_url(array &$operation): array {
        $target = is_array($operation['ilias_internal_target'] ?? null)
            ? $operation['ilias_internal_target']
            : [];
        $refid = (string) ($target['ref_id'] ?? '');
        $fallback = (string) ($operation['resolved_url'] ?? '');

        $candidates = $refid !== '' ? $this->mapped_target_candidates($refid) : [];
        $decision = phase3_internal_link_policy::choose($candidates);

        $operation['internal_link_resolution'] = [
            'status' => ($decision['action'] ?? '') === 'REWRITE' ? 'REWRITTEN' : 'PRESERVED',
            'reason' => $decision['reason'] ?? null,
            'candidate_count' => count($candidates),
            'fallback_url' => $fallback,
        ];

        if (($decision['action'] ?? '') !== 'REWRITE'
                || !is_array($decision['candidate'] ?? null)) {
            return $decision;
        }

        $candidate = $decision['candidate'];
        $resolved = (string) $candidate['url'];
        $operation['resolved_url'] = $resolved;
        $operation['moodle_internal_target'] = [
            'target_type' => (string) ($candidate['target_type'] ?? ''),
            'target_id' => (int) ($candidate['target_id'] ?? 0),
            'module' => $candidate['module'] ?? null,
            'url' => $resolved,
        ];
        $operation['package_validation']['code'] = 'ILIAS_INTERNAL_LINK_REWRITTEN';
        $operation['package_validation']['fallback_url'] = $fallback;
        $operation['package_validation']['resolved_url'] = $resolved;
        $operation['package_validation']['url_scheme'] = strtolower(
            (string) parse_url($resolved, PHP_URL_SCHEME)
        );
        $operation['package_validation']['moodle_target_type'] = (string) ($candidate['target_type'] ?? '');
        $operation['package_validation']['moodle_target_id'] = (int) ($candidate['target_id'] ?? 0);
        if (!empty($candidate['module'])) {
            $operation['package_validation']['moodle_module'] = (string) $candidate['module'];
        }

        return $decision;
    }

    /**
     * Return valid Moodle URL candidates for one ILIAS ref_id mapping.
     *
     * @param string $refid ILIAS target ref_id.
     * @return array Candidate descriptors.
     */
    private function mapped_target_candidates(string $refid): array {
        global $DB;

        if ($this->sourcecourse === '' || $this->targetcourseid <= 0) {
            return [];
        }

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $this->sourceinstance,
            'sourcecourse' => $this->sourcecourse,
            'sourceref' => $refid,
        ];
        $mappings = $DB->get_records('local_iliasmigration_map', $conditions);

        if (!$mappings && $this->sourceinstance !== '') {
            $conditions['sourceinstance'] = '';
            $mappings = $DB->get_records('local_iliasmigration_map', $conditions);
        }

        $candidates = [];
        foreach ($mappings as $mapping) {
            $candidate = $this->mapping_candidate($mapping);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }
        return $candidates;
    }

    /**
     * Convert one mapping row into a verified Moodle URL candidate.
     *
     * @param \stdClass $mapping Mapping row.
     * @return array|null Candidate descriptor or null when not linkable here.
     */
    private function mapping_candidate(\stdClass $mapping): ?array {
        global $DB;

        $targettype = (string) ($mapping->targettype ?? '');
        $targetid = (int) ($mapping->targetid ?? 0);
        if ($targetid <= 0) {
            return null;
        }

        if ($targettype === 'course') {
            if (!$DB->record_exists('course', ['id' => $targetid])) {
                return null;
            }
            $url = new \moodle_url('/course/view.php', ['id' => $targetid]);
            return [
                'target_type' => $targettype,
                'target_id' => $targetid,
                'module' => null,
                'url' => $url->out(false),
            ];
        }

        if ($targettype === 'section') {
            $section = $DB->get_record(
                'course_sections',
                ['id' => $targetid, 'course' => $this->targetcourseid],
                'id,section'
            );
            if (!$section) {
                return null;
            }
            $url = new \moodle_url('/course/view.php', ['id' => $this->targetcourseid]);
            $url->set_anchor('section-' . (int) $section->section);
            return [
                'target_type' => $targettype,
                'target_id' => $targetid,
                'module' => null,
                'url' => $url->out(false),
            ];
        }

        $coursemoduletypes = [
            'subsection',
            'url',
            'file',
            'html_module',
            'scorm',
            'book',
            'qbank',
            'quiz',
        ];
        if (!in_array($targettype, $coursemoduletypes, true)) {
            return null;
        }

        $cm = $DB->get_record(
            'course_modules',
            ['id' => $targetid, 'course' => $this->targetcourseid],
            'id,module'
        );
        if (!$cm) {
            return null;
        }
        $module = $DB->get_record('modules', ['id' => (int) $cm->module], 'id,name');
        if (!$module || trim((string) $module->name) === '') {
            return null;
        }

        $modulename = (string) $module->name;
        $url = new \moodle_url('/mod/' . $modulename . '/view.php', ['id' => (int) $cm->id]);
        return [
            'target_type' => $targettype,
            'target_id' => (int) $cm->id,
            'module' => $modulename,
            'url' => $url->out(false),
        ];
    }

    /**
     * Resolve the existing Moodle course id from the first course operation.
     *
     * @param array $plan Import plan.
     * @return int Moodle course id or 0 when Phase 2 has not been applied yet.
     */
    private function target_course_id(array $plan): int {
        foreach (($plan['operations'] ?? []) as $operation) {
            if (is_array($operation) && ($operation['kind'] ?? '') === 'course') {
                return (int) ($operation['target_id'] ?? 0);
            }
        }
        return 0;
    }

    /**
     * Validate one single-file Moodle resource.
     *
     * @param array $operation Operation being validated.
     */
    private function validate_file(array &$operation): void {
        $relative = (string) ($operation['migration_path'] ?? '');
        $resolved = $this->resolve_relative($relative, false);
        if ($resolved === null) {
            $this->block(
                $operation,
                'PACKAGE_FILE_MISSING',
                'The migration_path is missing, unsafe or not present in the package.'
            );
            return;
        }

        $operation['package_validation'] = [
            'status' => 'OK',
            'relative_path' => $relative,
            'size' => filesize($resolved) ?: 0,
        ];
    }

    /**
     * Validate one exported ILIAS HTML module.
     *
     * @param array $operation Operation being validated.
     */
    private function validate_html_module(array &$operation): void {
        $dirrelative = (string) ($operation['migration_content_dir'] ?? '');
        $startrelative = (string) ($operation['migration_start_file'] ?? '');

        $directory = $this->resolve_relative($dirrelative, true);
        $startfile = $this->resolve_relative($startrelative, false);

        if ($directory === null) {
            $this->block(
                $operation,
                'HTML_CONTENT_DIR_MISSING',
                'The HTML module content directory is missing, unsafe or not present in the package.'
            );
            return;
        }
        if ($startfile === null) {
            $this->block(
                $operation,
                'HTML_START_FILE_MISSING',
                'The HTML module start file is missing, unsafe or not present in the package.'
            );
            return;
        }

        $directoryprefix = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($startfile, $directoryprefix)) {
            $this->block(
                $operation,
                'HTML_START_FILE_OUTSIDE_CONTENT',
                'The HTML module start file must be located inside its content directory.'
            );
            return;
        }

        $filecount = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                $this->block(
                    $operation,
                    'HTML_SYMLINK_BLOCKED',
                    'Symbolic links are not accepted in HTML module packages.'
                );
                return;
            }
            if ($entry->isFile()) {
                $entryreal = $entry->getRealPath();
                if ($entryreal === false || !$this->is_inside_package($entryreal)) {
                    $this->block(
                        $operation,
                        'HTML_FILE_OUTSIDE_PACKAGE',
                        'An HTML module file resolves outside the migration package.'
                    );
                    return;
                }
                $filecount++;
            }
        }

        if ($filecount === 0) {
            $this->block($operation, 'HTML_CONTENT_EMPTY', 'The HTML module content directory is empty.');
            return;
        }

        $operation['package_validation'] = [
            'status' => 'OK',
            'content_dir' => $dirrelative,
            'start_file' => $startrelative,
            'file_count' => $filecount,
        ];
    }

    /**
     * Resolve a package-relative path and ensure it stays inside the package.
     *
     * @param string $relative Package-relative path.
     * @param bool $directory Whether a directory is required.
     * @return string|null Canonical path or null.
     */
    private function resolve_relative(string $relative, bool $directory): ?string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_starts_with($relative, '/')) {
            return null;
        }

        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }

        $candidate = $this->packageroot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        $resolved = realpath($candidate);
        if ($resolved === false || !$this->is_inside_package($resolved)) {
            return null;
        }

        if ($directory && !is_dir($resolved)) {
            return null;
        }
        if (!$directory && !is_file($resolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * Check whether a canonical path is inside the package root.
     *
     * @param string $path Canonical path.
     * @return bool
     */
    private function is_inside_package(string $path): bool {
        return $path === $this->packageroot
            || str_starts_with($path, $this->packageroot . DIRECTORY_SEPARATOR);
    }

    /**
     * Mark one resource operation as blocked.
     *
     * @param array $operation Operation being validated.
     * @param string $code Stable validation code.
     * @param string $message Human-readable reason.
     */
    private function block(array &$operation, string $code, string $message): void {
        $requested = (string) ($operation['action'] ?? '');
        $operation['requested_action'] = $requested;
        $operation['action'] = 'BLOCKED';
        $operation['reason'] = $code;
        $operation['package_validation'] = [
            'status' => 'ERROR',
            'code' => $code,
            'message' => $message,
        ];
    }
}

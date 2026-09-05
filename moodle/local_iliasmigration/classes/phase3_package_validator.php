<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates Phase 3 resource inputs without writing Moodle content.
 */
final class phase3_package_validator {
    /** @var string Canonical migration package root. */
    private string $packageroot;

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
     * @param array $operation Operation being validated.
     */
    private function validate_url(array &$operation): void {
        $url = trim((string) ($operation['source_url'] ?? ''));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false
                || !in_array($scheme, ['http', 'https'], true)) {
            $this->block($operation, 'URL_MISSING_OR_INVALID', 'A valid http/https URL is required.');
            return;
        }

        $operation['package_validation'] = [
            'status' => 'OK',
            'url_scheme' => $scheme,
        ];
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

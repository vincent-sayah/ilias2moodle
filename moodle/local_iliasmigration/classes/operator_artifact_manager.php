<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Operator-managed storage for native ILIAS exports and prepared packages.
 *
 * Files are always constrained to the configured roots. Uploads never
 * overwrite an existing export, package deletion is refused while a run
 * references its migration.json, and symlinks are never followed.
 */
final class operator_artifact_manager {
    /**
     * Store one native ILIAS ZIP uploaded from the operator workstation.
     *
     * @param array<string,mixed> $upload One entry from $_FILES.
     * @return array{name:string,path:string,size:int}
     */
    public function upload_export(array $upload): array {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \coding_exception(
                'ILIAS export upload failed with PHP error code ' . $error . '.'
            );
        }

        $tmpname = trim((string) ($upload['tmp_name'] ?? ''));
        $name = trim((string) ($upload['name'] ?? ''));

        if ($tmpname === '' || !is_uploaded_file($tmpname)) {
            throw new \coding_exception('Uploaded ILIAS export is missing.');
        }

        if (!$this->safe_export_name($name)) {
            throw new \coding_exception(
                'ILIAS export filename is unsafe. Use a simple .zip filename.'
            );
        }

        $size = filesize($tmpname);
        if ($size === false || $size <= 0) {
            throw new \coding_exception('Uploaded ILIAS export is empty.');
        }

        $this->assert_zip_signature($tmpname);

        $importsroot = $this->writable_directory(
            (string) (
                get_config('local_iliasmigration')->importsroot
                ?? '/var/moodledata/ilias2moodle/imports'
            ),
            'ILIAS imports root'
        );

        $destination = $importsroot . DIRECTORY_SEPARATOR . $name;
        if (file_exists($destination) || is_link($destination)) {
            throw new \coding_exception(
                'An ILIAS export with this filename already exists. Delete it first or upload it under another name.'
            );
        }

        $temporary = $importsroot
            . DIRECTORY_SEPARATOR
            . '.upload-'
            . bin2hex(random_bytes(8))
            . '.zip';

        if (!move_uploaded_file($tmpname, $temporary)) {
            throw new \coding_exception(
                'Unable to move the uploaded ILIAS export into the imports directory.'
            );
        }

        $stored = false;
        try {
            @chmod($temporary, 0640);
            $this->assert_zip_signature($temporary);

            if (!rename($temporary, $destination)) {
                throw new \coding_exception(
                    'Unable to finalize the uploaded ILIAS export.'
                );
            }
            $stored = true;
        } finally {
            if (!$stored && file_exists($temporary)) {
                @unlink($temporary);
            }
        }

        return [
            'name' => $name,
            'path' => $destination,
            'size' => (int) filesize($destination),
        ];
    }

    /**
     * Delete one operator-managed native export.
     */
    public function delete_export(string $name): void {
        if (!$this->safe_export_name($name)) {
            throw new \coding_exception('Unsafe ILIAS export filename.');
        }

        $importsroot = $this->existing_directory(
            (string) (
                get_config('local_iliasmigration')->importsroot
                ?? '/var/moodledata/ilias2moodle/imports'
            ),
            'ILIAS imports root'
        );

        $candidate = $importsroot . DIRECTORY_SEPARATOR . $name;
        if (is_link($candidate)) {
            throw new \coding_exception('Symlink exports cannot be deleted from the console.');
        }

        $resolved = realpath($candidate);
        if ($resolved === false
                || !is_file($resolved)
                || !$this->is_inside($resolved, $importsroot)) {
            throw new \coding_exception('Selected ILIAS export does not exist.');
        }

        if (!unlink($resolved)) {
            throw new \coding_exception('Unable to delete the selected ILIAS export.');
        }
    }

    /**
     * List prepared packages under packagesroot.
     *
     * @return array<string,array<string,mixed>>
     */
    public function available_packages(): array {
        $packagesroot = $this->existing_directory(
            (string) (
                get_config('local_iliasmigration')->packagesroot
                ?? '/var/moodledata/ilias2moodle/packages'
            ),
            'ILIAS2Moodle packages root'
        );

        $entries = scandir($packagesroot);
        if ($entries === false) {
            throw new \coding_exception(
                'Unable to read ILIAS2Moodle packages root.'
            );
        }

        $result = [];

        foreach ($entries as $entry) {
            if (!$this->safe_package_name($entry)) {
                continue;
            }

            $candidate = $packagesroot . DIRECTORY_SEPARATOR . $entry;
            if (is_link($candidate)) {
                continue;
            }

            $resolved = realpath($candidate);
            if ($resolved === false
                    || !is_dir($resolved)
                    || !$this->is_inside($resolved, $packagesroot)) {
                continue;
            }

            $packagepath = $resolved . DIRECTORY_SEPARATOR . 'package.json';
            $migrationpath = $resolved . DIRECTORY_SEPARATOR . 'migration.json';

            $package = $this->read_json($packagepath);
            $migration = $this->read_json($migrationpath);

            $complete = is_array($package) && is_array($migration);
            $missingcount = $complete
                ? (int) (
                    $package['missing_count']
                    ?? count(is_array($package['missing'] ?? null)
                        ? $package['missing']
                        : [])
                )
                : null;

            $course = $complete && is_array($migration['course'] ?? null)
                ? $migration['course']
                : [];

            $result[$entry] = [
                'path' => $resolved,
                'migration_json' => $complete ? $migrationpath : null,
                'source_archive' => $complete
                    ? (string) ($package['source_archive'] ?? '')
                    : '',
                'source_course' => $complete
                    ? (string) ($course['source_id'] ?? '')
                    : '',
                'title' => $complete
                    ? (string) ($course['title'] ?? '')
                    : '',
                'missing_count' => $missingcount,
                'ready' => $complete && $missingcount === 0,
                'status' => !$complete
                    ? 'INCOMPLETE'
                    : ($missingcount === 0 ? 'READY' : 'RECOVERY_REQUIRED'),
                'mtime' => (int) filemtime($resolved),
                'size' => $this->directory_size($resolved),
            ];
        }

        ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
        return $result;
    }

    /**
     * Resolve a ready package to its migration.json.
     */
    public function package_migration_path(string $name): string {
        $packages = $this->available_packages();

        if (!isset($packages[$name])) {
            throw new \coding_exception('Selected prepared package does not exist.');
        }

        if (empty($packages[$name]['ready'])) {
            throw new \coding_exception(
                'Selected prepared package is not ready. Resolve every missing dependency first.'
            );
        }

        $path = realpath((string) $packages[$name]['migration_json']);
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new \coding_exception(
                'Selected prepared package has no readable migration.json.'
            );
        }

        return $path;
    }

    /**
     * Delete one prepared package that is not referenced by an operator run.
     */
    public function delete_package(string $name): void {
        global $DB;

        if (!$this->safe_package_name($name)) {
            throw new \coding_exception('Unsafe prepared package name.');
        }

        $packagesroot = $this->existing_directory(
            (string) (
                get_config('local_iliasmigration')->packagesroot
                ?? '/var/moodledata/ilias2moodle/packages'
            ),
            'ILIAS2Moodle packages root'
        );

        $candidate = $packagesroot . DIRECTORY_SEPARATOR . $name;
        if (is_link($candidate)) {
            throw new \coding_exception('Symlink packages cannot be deleted from the console.');
        }

        $resolved = realpath($candidate);
        if ($resolved === false
                || !is_dir($resolved)
                || !$this->is_inside($resolved, $packagesroot)) {
            throw new \coding_exception('Selected prepared package does not exist.');
        }

        $migrationjson = $resolved . DIRECTORY_SEPARATOR . 'migration.json';
        if (is_file($migrationjson)
                && $DB->record_exists(
                    'local_iliasmigration_run',
                    ['sourcepath' => $migrationjson]
                )) {
            throw new \coding_exception(
                'This package is referenced by an operator run and cannot be deleted.'
            );
        }

        $this->remove_tree($resolved);
        if (file_exists($resolved)) {
            throw new \coding_exception('Unable to delete the selected prepared package.');
        }
    }

    private function safe_export_name(string $name): bool {
        return preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}\\.zip$/i',
            $name
        ) === 1
            && basename($name) === $name
            && !str_contains($name, '..');
    }

    private function safe_package_name(string $name): bool {
        return preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/',
            $name
        ) === 1
            && basename($name) === $name
            && !str_contains($name, '..');
    }

    private function assert_zip_signature(string $path): void {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \coding_exception('Unable to read uploaded ZIP signature.');
        }

        try {
            $signature = fread($handle, 4);
        } finally {
            fclose($handle);
        }

        if (!in_array($signature, [
            "PK\x03\x04",
            "PK\x05\x06",
            "PK\x07\x08",
        ], true)) {
            throw new \coding_exception(
                'Uploaded file does not have a valid ZIP signature.'
            );
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function read_json(string $path): ?array {
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            return null;
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function directory_size(string $root): int {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                continue;
            }
            if ($entry->isFile()) {
                $size += (int) $entry->getSize();
            }
        }

        return $size;
    }

    private function remove_tree(string $path): void {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                if (!@unlink($entry->getPathname())) {
                    throw new \coding_exception(
                        'Unable to delete package file: ' . $entry->getFilename()
                    );
                }
            } else if (!@rmdir($entry->getPathname())) {
                throw new \coding_exception(
                    'Unable to delete package directory: ' . $entry->getFilename()
                );
            }
        }

        if (!@rmdir($path)) {
            throw new \coding_exception('Unable to delete package root.');
        }
    }

    private function writable_directory(string $path, string $label): string {
        $path = rtrim(trim($path), DIRECTORY_SEPARATOR);
        if ($path === '') {
            throw new \coding_exception($label . ' must not be empty.');
        }

        if (!is_dir($path)) {
            if (!mkdir($path, 0750, true) && !is_dir($path)) {
                throw new \coding_exception('Unable to create ' . $label . '.');
            }
        }

        $resolved = realpath($path);
        if ($resolved === false
                || !is_dir($resolved)
                || !is_writable($resolved)) {
            throw new \coding_exception(
                $label . ' is not a writable directory: ' . $path
            );
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function existing_directory(string $path, string $label): string {
        $resolved = realpath(trim($path));
        if ($resolved === false || !is_dir($resolved)) {
            throw new \coding_exception($label . ' does not exist: ' . $path);
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function is_inside(string $path, string $root): bool {
        return $path === $root
            || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}

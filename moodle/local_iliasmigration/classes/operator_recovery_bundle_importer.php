<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Safely import a source recovery bundle and atomically re-prepare a package.
 *
 * The bundle is never trusted as a command source. It is inspected before
 * extraction, only regular files/directories are accepted, expected manifests
 * must match the current recovery-plan.json, and prepare-export is rerun in a
 * temporary package before replacing the existing package.
 */
final class operator_recovery_bundle_importer {
    /** @var array<string,string> */
    private const OPTION_TO_KEY = [
        '--exercise-irss-recovery' => 'exercise_irss_recovery',
        '--mediacast-media-recovery' => 'mediacast_media_recovery',
        '--mediaobject-recovery' => 'mediaobject_recovery',
        '--forum-attachment-recovery' => 'forum_attachment_recovery',
        '--wiki-content-recovery' => 'wiki_content_recovery',
    ];

    private const MAX_BUNDLE_BYTES = 536870912;
    private const MAX_ARCHIVE_ENTRIES = 10000;
    private const SERVER_BUNDLES_DIRECTORY = 'bundles';

    /**
     * List recovery bundles already deposited on the Moodle server.
     *
     * Only direct regular .tar.gz/.tgz files from the controlled bundles
     * directory are exposed. Symlinks and nested/arbitrary paths are ignored.
     *
     * @return array<string,array{path:string,size:int,mtime:int}>
     */
    public function available_server_bundles(): array {
        $config = get_config('local_iliasmigration');

        $recoveriesroot = $this->writable_directory(
            (string) (
                $config->recoveriesroot
                ?? '/var/moodledata/ilias2moodle/recovery'
            ),
            'ILIAS2Moodle recoveries root'
        );

        $bundlesroot = $recoveriesroot
            . DIRECTORY_SEPARATOR
            . self::SERVER_BUNDLES_DIRECTORY;

        if (!is_dir($bundlesroot)) {
            if (!mkdir($bundlesroot, 0750, true)
                    && !is_dir($bundlesroot)) {
                throw new \coding_exception(
                    'Unable to create recovery bundles directory: '
                    . $bundlesroot
                );
            }
        }

        $resolvedroot = realpath($bundlesroot);
        if ($resolvedroot === false
                || !is_dir($resolvedroot)
                || !$this->is_inside(
                    $resolvedroot,
                    $recoveriesroot
                )) {
            throw new \coding_exception(
                'Recovery bundles directory is invalid.'
            );
        }

        $entries = scandir($resolvedroot);
        if ($entries === false) {
            throw new \coding_exception(
                'Unable to read recovery bundles directory: '
                . $resolvedroot
            );
        }

        $result = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (basename($entry) !== $entry
                    || !$this->is_bundle_filename($entry)) {
                continue;
            }

            $candidate = $resolvedroot
                . DIRECTORY_SEPARATOR
                . $entry;

            if (is_link($candidate)) {
                continue;
            }

            $resolved = realpath($candidate);
            if ($resolved === false
                    || !is_file($resolved)
                    || !is_readable($resolved)
                    || !$this->is_inside(
                        $resolved,
                        $resolvedroot
                    )) {
                continue;
            }

            $size = filesize($resolved);
            if ($size === false
                    || $size <= 0
                    || $size > self::MAX_BUNDLE_BYTES) {
                continue;
            }

            $result[$entry] = [
                'path' => $resolved,
                'size' => (int) $size,
                'mtime' => (int) filemtime($resolved),
            ];
        }

        ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
        return $result;
    }

    /**
     * Import one .tar.gz recovery bundle and re-prepare an existing package.
     *
     * @return array<string,mixed>
     */
    public function import_and_reprepare(
        string $zippath,
        string $outputname,
        string $bundlepath,
        string $bundlename
    ): array {
        $preparer = new operator_package_preparer();

        // Reuse mode performs all existing package/source archive guards
        // without modifying the package.
        $existing = $preparer->prepare(
            $zippath,
            $outputname
        );

        $this->assert_package_not_in_use(
            (string) (
                $existing['migration_json']
                ?? ''
            )
        );

        $previousmissing = (int) (
            $existing['missing_count'] ?? 0
        );

        if ($previousmissing <= 0
                || empty($existing['recovery_required'])) {
            throw new \coding_exception(
                'The prepared package does not require source recovery.'
            );
        }

        $plan = is_array(
            $existing['recovery_plan'] ?? null
        )
            ? $existing['recovery_plan']
            : [];

        if ((int) ($plan['unresolved_count'] ?? 0) > 0) {
            throw new \coding_exception(
                'Recovery bundle import is blocked while the recovery plan contains unresolved dependencies.'
            );
        }

        $requests = is_array(
            $plan['requests'] ?? null
        )
            ? $plan['requests']
            : [];

        if (!$requests) {
            throw new \coding_exception(
                'The recovery plan contains no executable recovery request.'
            );
        }

        $this->validate_bundle(
            $bundlepath,
            $bundlename
        );

        $config = get_config('local_iliasmigration');

        $packagesroot = $this->existing_directory(
            (string) (
                $config->packagesroot
                ?? '/var/moodledata/ilias2moodle/packages'
            ),
            'ILIAS2Moodle packages root'
        );

        $recoveriesroot = $this->writable_directory(
            (string) (
                $config->recoveriesroot
                ?? '/var/moodledata/ilias2moodle/recovery'
            ),
            'ILIAS2Moodle recoveries root'
        );

        $output = realpath(
            (string) ($existing['package_root'] ?? '')
        );

        if ($output === false
                || !is_dir($output)
                || !$this->is_inside(
                    $output,
                    $packagesroot
                )) {
            throw new \coding_exception(
                'Existing prepared package is outside the configured packages root.'
            );
        }

        $token = bin2hex(random_bytes(8));
        $extractbase = $recoveriesroot
            . DIRECTORY_SEPARATOR
            . 'bundle-'
            . $token;
        $tempname = 'recovery-'
            . substr(
                hash(
                    'sha256',
                    $outputname . ':' . $token
                ),
                0,
                24
            );
        $temppackage = $packagesroot
            . DIRECTORY_SEPARATOR
            . $tempname;

        if (!mkdir($extractbase, 0750, true)
                && !is_dir($extractbase)) {
            throw new \coding_exception(
                'Unable to create recovery extraction directory.'
            );
        }

        $prepared = null;

        try {
            $this->safe_extract_bundle(
                $bundlepath,
                $extractbase
            );

            $recoveryroot = $this->locate_recovery_root(
                $extractbase,
                $requests
            );

            $recoveries = $this->recovery_options(
                $requests,
                $recoveryroot
            );

            $prepared = $preparer->prepare(
                $zippath,
                $tempname,
                $recoveries
            );

            $newmissing = (int) (
                $prepared['missing_count'] ?? 0
            );

            if ($newmissing >= $previousmissing) {
                throw new \coding_exception(
                    'Recovery bundle did not reduce the unresolved dependency count.'
                );
            }

            $preparedroot = realpath(
                (string) (
                    $prepared['package_root']
                    ?? ''
                )
            );

            if ($preparedroot === false
                    || $preparedroot !== realpath($temppackage)
                    || !$this->is_inside(
                        $preparedroot,
                        $packagesroot
                    )) {
                throw new \coding_exception(
                    'Temporary prepared package path is invalid.'
                );
            }

            $backup = $packagesroot
                . DIRECTORY_SEPARATOR
                . '.recovery-backup-'
                . $token;

            if (!rename($output, $backup)) {
                throw new \coding_exception(
                    'Unable to stage the existing package for atomic replacement.'
                );
            }

            $replacementok = false;
            try {
                if (!rename(
                    $preparedroot,
                    $output
                )) {
                    throw new \coding_exception(
                        'Unable to atomically replace the prepared package.'
                    );
                }
                $replacementok = true;
            } finally {
                if (!$replacementok) {
                    if (is_dir($output)) {
                        $this->remove_tree($output);
                    }
                    @rename($backup, $output);
                }
            }

            $this->remove_tree($backup);

            $prepared['output'] = $output;
            $prepared['package_root'] = $output;
            $prepared['migration_json'] = $output
                . DIRECTORY_SEPARATOR
                . 'migration.json';
            $prepared['recovery_plan_path'] = is_file(
                $output
                . DIRECTORY_SEPARATOR
                . 'recovery-plan.json'
            )
                ? $output
                    . DIRECTORY_SEPARATOR
                    . 'recovery-plan.json'
                : null;
            $prepared['reprepared_existing'] = true;
            $prepared['previous_missing_count'] = (
                $previousmissing
            );
            $prepared['recovery_bundle_name'] = basename(
                $bundlename
            );

            return $prepared;
        } finally {
            if (is_dir($temppackage)) {
                $this->remove_tree($temppackage);
            }
            if (is_dir($extractbase)) {
                $this->remove_tree($extractbase);
            }
        }
    }

    private function assert_package_not_in_use(
        string $migrationjson
    ): void {
        global $DB;

        $migrationjson = trim($migrationjson);

        if ($migrationjson === '') {
            throw new \coding_exception(
                'Prepared package migration.json path is missing.'
            );
        }

        if ($DB->record_exists(
            'local_iliasmigration_run',
            ['sourcepath' => $migrationjson]
        )) {
            throw new \coding_exception(
                'Recovery bundle import is refused because this migration.json is already referenced by an operator run.'
            );
        }
    }

    private function validate_bundle(
        string $bundlepath,
        string $bundlename
    ): void {
        if (!is_file($bundlepath)
                || !is_readable($bundlepath)) {
            throw new \coding_exception(
                'Recovery bundle is missing or unreadable.'
            );
        }

        $name = basename($bundlename);
        if (!$this->is_bundle_filename($name)) {
            throw new \coding_exception(
                'Recovery bundle must be a .tar.gz or .tgz archive.'
            );
        }

        $size = filesize($bundlepath);
        if ($size === false
                || $size <= 0
                || $size > self::MAX_BUNDLE_BYTES) {
            throw new \coding_exception(
                'Recovery bundle size is invalid or exceeds the 512 MiB safety limit.'
            );
        }
    }

    /**
     * Safely inspect and extract a gzip-compressed tar archive.
     */
    private function safe_extract_bundle(
        string $bundlepath,
        string $destination
    ): void {
        $tar = $this->tar_executable();

        $list = $this->run_process([
            $tar,
            '--list',
            '--gzip',
            '--file',
            $bundlepath,
        ]);

        $entries = preg_split(
            '/\R/',
            trim($list['stdout'])
        ) ?: [];

        if (!$entries
                || count($entries)
                    > self::MAX_ARCHIVE_ENTRIES) {
            throw new \coding_exception(
                'Recovery bundle entry count is invalid.'
            );
        }

        foreach ($entries as $entry) {
            $this->validate_archive_entry(
                (string) $entry
            );
        }

        $verbose = $this->run_process([
            $tar,
            '--list',
            '--verbose',
            '--gzip',
            '--file',
            $bundlepath,
        ]);

        foreach (
            preg_split('/\R/', trim(
                $verbose['stdout']
            )) ?: []
            as $line
        ) {
            if ($line === '') {
                continue;
            }

            $type = substr($line, 0, 1);
            if ($type !== '-' && $type !== 'd') {
                throw new \coding_exception(
                    'Recovery bundle contains a non-regular archive entry.'
                );
            }
        }

        $this->run_process([
            $tar,
            '--extract',
            '--gzip',
            '--file',
            $bundlepath,
            '--directory',
            $destination,
            '--no-same-owner',
            '--no-same-permissions',
        ]);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $destination,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new \coding_exception(
                    'Recovery bundle extraction produced a symbolic link.'
                );
            }

            $resolved = realpath(
                $entry->getPathname()
            );

            if ($resolved === false
                    || !$this->is_inside(
                        $resolved,
                        $destination
                    )) {
                throw new \coding_exception(
                    'Recovery bundle extraction escaped its destination.'
                );
            }
        }
    }

    private function validate_archive_entry(
        string $entry
    ): void {
        $entry = str_replace('\\', '/', trim($entry));

        while (str_starts_with($entry, './')) {
            $entry = substr($entry, 2);
        }

        $entry = rtrim($entry, '/');

        if ($entry === '') {
            return;
        }

        if (str_starts_with($entry, '/')
                || preg_match(
                    '/^[A-Za-z]:\//',
                    $entry
                )) {
            throw new \coding_exception(
                'Recovery bundle contains an absolute path.'
            );
        }

        $parts = explode('/', $entry);
        if (in_array('..', $parts, true)) {
            throw new \coding_exception(
                'Recovery bundle contains a parent path traversal.'
            );
        }
    }

    /**
     * @param array<int,mixed> $requests
     */
    private function locate_recovery_root(
        string $extractbase,
        array $requests
    ): string {
        $expected = [];

        foreach ($requests as $request) {
            if (!is_array($request)) {
                throw new \coding_exception(
                    'Recovery plan request is invalid.'
                );
            }

            $manifest = str_replace(
                '\\',
                '/',
                trim((string) (
                    $request['expected_manifest']
                    ?? ''
                ))
            );

            if ($manifest === '') {
                throw new \coding_exception(
                    'Recovery plan request has no expected manifest.'
                );
            }

            $this->validate_archive_entry(
                $manifest
            );

            $expected[] = $manifest;
        }

        $candidates = [$extractbase];
        foreach (
            scandir($extractbase) ?: []
            as $entry
        ) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $candidate = $extractbase
                . DIRECTORY_SEPARATOR
                . $entry;

            if (is_dir($candidate)
                    && !is_link($candidate)) {
                $candidates[] = $candidate;
            }
        }

        $matching = [];
        foreach ($candidates as $candidate) {
            $allpresent = true;

            foreach ($expected as $manifest) {
                $path = $candidate
                    . DIRECTORY_SEPARATOR
                    . str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $manifest
                    );

                if (!is_file($path)
                        || is_link($path)) {
                    $allpresent = false;
                    break;
                }
            }

            if ($allpresent) {
                $matching[] = $candidate;
            }
        }

        if (count($matching) !== 1) {
            throw new \coding_exception(
                'Recovery bundle does not expose exactly one root containing every expected manifest.'
            );
        }

        return (string) realpath($matching[0]);
    }

    /**
     * @param array<int,mixed> $requests
     * @return array<string,string>
     */
    private function recovery_options(
        array $requests,
        string $recoveryroot
    ): array {
        $recoveries = [];

        foreach ($requests as $request) {
            if (!is_array($request)) {
                continue;
            }

            $option = trim((string) (
                $request['recovery_option']
                ?? ''
            ));

            if (!array_key_exists(
                $option,
                self::OPTION_TO_KEY
            )) {
                throw new \coding_exception(
                    'Recovery plan contains an unsupported recovery option: '
                    . $option
                );
            }

            $recoveries[
                self::OPTION_TO_KEY[$option]
            ] = $recoveryroot;
        }

        if (!$recoveries) {
            throw new \coding_exception(
                'Recovery plan produced no supported prepare-export option.'
            );
        }

        return $recoveries;
    }

    /**
     * @return array{stdout:string,stderr:string}
     */
    private function run_process(array $command): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            throw new \coding_exception(
                'Unable to start recovery bundle archive worker.'
            );
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitcode = proc_close($process);

        if ($exitcode !== 0) {
            throw new \coding_exception(
                'Recovery bundle archive command failed'
                . ' (exit=' . $exitcode . '): '
                . trim((string) $stderr)
            );
        }

        return [
            'stdout' => (string) $stdout,
            'stderr' => trim((string) $stderr),
        ];
    }

    private function tar_executable(): string {
        foreach ([
            '/bin/tar',
            '/usr/bin/tar',
        ] as $candidate) {
            if (is_file($candidate)
                    && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new \coding_exception(
            'GNU tar executable was not found.'
        );
    }

    private function is_bundle_filename(
        string $filename
    ): bool {
        $filename = strtolower(
            trim($filename)
        );

        return $filename !== ''
            && basename($filename) === $filename
            && (
                str_ends_with(
                    $filename,
                    '.tar.gz'
                )
                || str_ends_with(
                    $filename,
                    '.tgz'
                )
            );
    }

    private function writable_directory(
        string $path,
        string $label
    ): string {
        $path = rtrim(
            trim($path),
            DIRECTORY_SEPARATOR
        );

        if ($path === '') {
            throw new \coding_exception(
                $label . ' must not be empty.'
            );
        }

        if (!is_dir($path)) {
            if (!mkdir($path, 0750, true)
                    && !is_dir($path)) {
                throw new \coding_exception(
                    'Unable to create '
                    . $label
                    . ': '
                    . $path
                );
            }
        }

        $resolved = realpath($path);

        if ($resolved === false
                || !is_dir($resolved)
                || !is_writable($resolved)) {
            throw new \coding_exception(
                $label
                . ' is not a writable directory: '
                . $path
            );
        }

        return rtrim(
            $resolved,
            DIRECTORY_SEPARATOR
        );
    }

    private function existing_directory(
        string $path,
        string $label
    ): string {
        $resolved = realpath(trim($path));

        if ($resolved === false
                || !is_dir($resolved)) {
            throw new \coding_exception(
                $label . ' does not exist: ' . $path
            );
        }

        return rtrim(
            $resolved,
            DIRECTORY_SEPARATOR
        );
    }

    private function remove_tree(string $path): void {
        if (!file_exists($path)) {
            return;
        }

        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink()
                    || $entry->isFile()) {
                @unlink($entry->getPathname());
            } else {
                @rmdir($entry->getPathname());
            }
        }

        @rmdir($path);
    }

    private function is_inside(
        string $path,
        string $root
    ): bool {
        return $path === $root
            || str_starts_with(
                $path,
                $root . DIRECTORY_SEPARATOR
            );
    }
}

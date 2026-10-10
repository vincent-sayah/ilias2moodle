<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle-side worker that consumes source recovery bundles automatically.
 */
final class operator_recovery_reprepare_worker {
    /**
     * Process queued packages whose deterministic recovery bundle has arrived.
     *
     * @return array<string,mixed>
     */
    public function run(int $maxjobs = 5): array {
        if ($maxjobs < 1 || $maxjobs > 50) {
            throw new \coding_exception(
                'Recovery reprepare max jobs must be between 1 and 50.'
            );
        }

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

        $requestsroot = $this->writable_directory(
            $recoveriesroot
                . DIRECTORY_SEPARATOR
                . 'requests',
            'ILIAS2Moodle recovery requests root'
        );
        $processedroot = $this->writable_directory(
            $recoveriesroot
                . DIRECTORY_SEPARATOR
                . 'processed',
            'ILIAS2Moodle processed recovery root'
        );
        $stateroot = $this->writable_directory(
            $recoveriesroot
                . DIRECTORY_SEPARATOR
                . 'reprepare-state',
            'ILIAS2Moodle recovery reprepare state root'
        );

        $lockfactory = \core\lock\lock_config::get_lock_factory(
            'local_iliasmigration'
        );
        $lock = $lockfactory->get_lock(
            'automatic_recovery_reprepare',
            0
        );

        if (!$lock) {
            return [
                'success' => true,
                'already_running' => true,
                'processed_count' => 0,
                'failed_count' => 0,
                'waiting_count' => 0,
                'skipped_count' => 0,
                'results' => [],
            ];
        }

        try {
            return $this->run_locked(
                $maxjobs,
                $packagesroot,
                $recoveriesroot,
                $requestsroot,
                $processedroot,
                $stateroot
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function run_locked(
        int $maxjobs,
        string $packagesroot,
        string $recoveriesroot,
        string $requestsroot,
        string $processedroot,
        string $stateroot
    ): array {
        $preparer = new operator_package_preparer();
        $importer = new operator_recovery_bundle_importer();
        $queue = new operator_recovery_queue();

        $imports = $preparer->available_imports();
        $serverbundles = $importer->available_server_bundles();

        $markers = glob(
            $requestsroot
                . DIRECTORY_SEPARATOR
                . '*.json'
        ) ?: [];

        sort(
            $markers,
            SORT_NATURAL | SORT_FLAG_CASE
        );

        $processed = 0;
        $failed = 0;
        $waiting = 0;
        $skipped = 0;
        $results = [];

        foreach ($markers as $marker) {
            if ($processed >= $maxjobs) {
                break;
            }

            $package = pathinfo(
                $marker,
                PATHINFO_FILENAME
            );

            try {
                $request = $this->load_request(
                    $marker,
                    $package
                );

                $zipname = (string) $request['zip_name'];
                $plansha = (string) $request['plan_sha256'];
                $bundlename = $package
                    . '_recovery.tar.gz';

                if (!array_key_exists(
                    $zipname,
                    $imports
                )) {
                    throw new \coding_exception(
                        'Queued recovery references an unavailable ILIAS ZIP: '
                        . $zipname
                    );
                }

                $planpath = $this->current_plan_path(
                    $packagesroot,
                    $package
                );
                $currentsha = hash_file(
                    'sha256',
                    $planpath
                );

                if ($currentsha === false
                        || !hash_equals(
                            $plansha,
                            $currentsha
                        )) {
                    throw new \coding_exception(
                        'Queued recovery plan SHA-256 is stale.'
                    );
                }

                if (!array_key_exists(
                    $bundlename,
                    $serverbundles
                )) {
                    $waiting++;
                    $results[] = [
                        'package_name' => $package,
                        'status' => 'WAITING_BUNDLE',
                        'plan_sha256' => $plansha,
                        'bundle_name' => $bundlename,
                    ];
                    continue;
                }

                $bundle = $serverbundles[
                    $bundlename
                ];

                $bundlepath = (string) (
                    $bundle['path']
                    ?? ''
                );

                if ($bundlepath === ''
                        || !is_file($bundlepath)
                        || is_link($bundlepath)) {
                    throw new \coding_exception(
                        'Queued recovery bundle path is invalid.'
                    );
                }

                $bundlesha = hash_file(
                    'sha256',
                    $bundlepath
                );

                if ($bundlesha === false) {
                    throw new \coding_exception(
                        'Unable to hash queued recovery bundle.'
                    );
                }

                $statepath = $stateroot
                    . DIRECTORY_SEPARATOR
                    . $package
                    . '.json';

                $previous = $this->load_state(
                    $statepath
                );

                if (($previous['status'] ?? '') === 'FAILED'
                        && ($previous['plan_sha256'] ?? '') === $plansha
                        && ($previous['bundle_sha256'] ?? '') === $bundlesha) {
                    $skipped++;
                    $results[] = [
                        'package_name' => $package,
                        'status' => 'SKIPPED_FAILED_SAME_INPUT',
                        'plan_sha256' => $plansha,
                        'bundle_sha256' => $bundlesha,
                    ];
                    continue;
                }

                $this->write_state(
                    $statepath,
                    [
                        'schema_version' => '1.0',
                        'package_name' => $package,
                        'status' => 'RUNNING',
                        'plan_sha256' => $plansha,
                        'bundle_sha256' => $bundlesha,
                        'started_at' => gmdate('c'),
                    ]
                );

                $result = $importer->import_and_reprepare(
                    (string) $imports[
                        $zipname
                    ]['path'],
                    $package,
                    $bundlepath,
                    $bundlename
                );

                $nextrequest = $queue->sync(
                    $package,
                    $zipname,
                    $result
                );

                $archivepath = $this->archive_bundle(
                    $bundlepath,
                    $processedroot,
                    $package,
                    $plansha,
                    $bundlesha
                );

                $this->write_state(
                    $statepath,
                    [
                        'schema_version' => '1.0',
                        'package_name' => $package,
                        'status' => 'SUCCESS',
                        'plan_sha256' => $plansha,
                        'bundle_sha256' => $bundlesha,
                        'archive_path' => $archivepath,
                        'previous_missing_count' => (int) (
                            $result[
                                'previous_missing_count'
                            ]
                            ?? 0
                        ),
                        'missing_count' => (int) (
                            $result['missing_count']
                            ?? 0
                        ),
                        'recovery_required' => !empty(
                            $result[
                                'recovery_required'
                            ]
                        ),
                        'next_request_queued' => (
                            $nextrequest !== null
                        ),
                        'finished_at' => gmdate('c'),
                    ]
                );

                $processed++;
                $results[] = [
                    'package_name' => $package,
                    'status' => 'SUCCESS',
                    'plan_sha256' => $plansha,
                    'bundle_sha256' => $bundlesha,
                    'archive_path' => $archivepath,
                    'previous_missing_count' => (int) (
                        $result[
                            'previous_missing_count'
                        ]
                        ?? 0
                    ),
                    'missing_count' => (int) (
                        $result['missing_count']
                        ?? 0
                    ),
                    'recovery_required' => !empty(
                        $result[
                            'recovery_required'
                        ]
                    ),
                    'next_request_queued' => (
                        $nextrequest !== null
                    ),
                ];
            } catch (\Throwable $exception) {
                $failed++;

                $statepath = $stateroot
                    . DIRECTORY_SEPARATOR
                    . $package
                    . '.json';

                $state = [
                    'schema_version' => '1.0',
                    'package_name' => $package,
                    'status' => 'FAILED',
                    'finished_at' => gmdate('c'),
                    'error' => (
                        $exception::class
                        . ': '
                        . $exception->getMessage()
                    ),
                ];

                if (isset($plansha)) {
                    $state['plan_sha256'] = $plansha;
                }
                if (isset($bundlesha)) {
                    $state['bundle_sha256'] = $bundlesha;
                }

                $this->write_state(
                    $statepath,
                    $state
                );

                $results[] = [
                    'package_name' => $package,
                    'status' => 'FAILED',
                    'error' => $state['error'],
                ];
            }
        }

        return [
            'success' => $failed === 0,
            'already_running' => false,
            'processed_count' => $processed,
            'failed_count' => $failed,
            'waiting_count' => $waiting,
            'skipped_count' => $skipped,
            'results' => $results,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function load_request(
        string $path,
        string $expectedpackage
    ): array {
        if (is_link($path)
                || !is_file($path)
                || !is_readable($path)) {
            throw new \coding_exception(
                'Recovery queue marker is not a readable regular file.'
            );
        }

        try {
            $request = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \coding_exception(
                'Recovery queue marker contains invalid JSON.'
            );
        }

        if (!is_array($request)
                || (string) (
                    $request['schema_version']
                    ?? ''
                ) !== '1.0') {
            throw new \coding_exception(
                'Recovery queue marker schema is invalid.'
            );
        }

        $package = $this->safe_name(
            (string) (
                $request['package_name']
                ?? ''
            ),
            'package'
        );

        if ($package !== $expectedpackage) {
            throw new \coding_exception(
                'Recovery queue marker package mismatch.'
            );
        }

        $zipname = basename(
            trim(
                (string) (
                    $request['zip_name']
                    ?? ''
                )
            )
        );

        if ($zipname === ''
                || strtolower(
                    pathinfo(
                        $zipname,
                        PATHINFO_EXTENSION
                    )
                ) !== 'zip') {
            throw new \coding_exception(
                'Recovery queue marker ZIP name is invalid.'
            );
        }

        $plansha = strtolower(
            trim(
                (string) (
                    $request['plan_sha256']
                    ?? ''
                )
            )
        );

        if (!preg_match(
            '/^[0-9a-f]{64}$/',
            $plansha
        )) {
            throw new \coding_exception(
                'Recovery queue marker plan SHA-256 is invalid.'
            );
        }

        $request['package_name'] = $package;
        $request['zip_name'] = $zipname;
        $request['plan_sha256'] = $plansha;

        return $request;
    }

    private function current_plan_path(
        string $packagesroot,
        string $package
    ): string {
        $package = $this->safe_name(
            $package,
            'package'
        );

        $packageroot = realpath(
            $packagesroot
                . DIRECTORY_SEPARATOR
                . $package
        );

        if ($packageroot === false
                || !is_dir($packageroot)
                || is_link($packageroot)
                || !$this->is_inside(
                    $packageroot,
                    $packagesroot
                )) {
            throw new \coding_exception(
                'Queued recovery package directory is invalid.'
            );
        }

        $planpath = $packageroot
            . DIRECTORY_SEPARATOR
            . 'recovery-plan.json';

        if (!is_file($planpath)
                || is_link($planpath)
                || !is_readable($planpath)) {
            throw new \coding_exception(
                'Queued recovery plan is missing or unreadable.'
            );
        }

        return $planpath;
    }

    /**
     * @return array<string,mixed>
     */
    private function load_state(
        string $path
    ): array {
        if (!is_file($path)
                || is_link($path)
                || !is_readable($path)) {
            return [];
        }

        try {
            $state = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            return [];
        }

        return is_array($state)
            ? $state
            : [];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function write_state(
        string $path,
        array $state
    ): void {
        $tmp = $path
            . '.tmp.'
            . bin2hex(random_bytes(6));

        $encoded = json_encode(
            $state,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        if (file_put_contents(
            $tmp,
            $encoded,
            LOCK_EX
        ) === false) {
            throw new \coding_exception(
                'Unable to write recovery reprepare state.'
            );
        }

        @chmod($tmp, 0640);

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \coding_exception(
                'Unable to publish recovery reprepare state.'
            );
        }
    }

    private function archive_bundle(
        string $bundlepath,
        string $processedroot,
        string $package,
        string $plansha,
        string $bundlesha
    ): string {
        $timestamp = gmdate(
            'Ymd\THis\Z'
        );
        $name = $timestamp
            . '--'
            . substr($plansha, 0, 12)
            . '--'
            . substr($bundlesha, 0, 12)
            . '--'
            . $package
            . '_recovery.tar.gz';

        $destination = $processedroot
            . DIRECTORY_SEPARATOR
            . $name;

        if (!rename(
            $bundlepath,
            $destination
        )) {
            throw new \coding_exception(
                'Recovery bundle was applied but could not be archived.'
            );
        }

        @chmod($destination, 0640);

        return $destination;
    }

    private function safe_name(
        string $value,
        string $label
    ): string {
        $candidate = trim($value);

        if (!preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',
            $candidate
        )
                || str_contains(
                    $candidate,
                    '..'
                )) {
            throw new \coding_exception(
                'Unsafe automatic recovery '
                . $label
                . ' name.'
            );
        }

        return $candidate;
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

        if (is_link($path)) {
            throw new \coding_exception(
                $label . ' must not be a symlink.'
            );
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
        $resolved = realpath(
            trim($path)
        );

        if ($resolved === false
                || !is_dir($resolved)) {
            throw new \coding_exception(
                $label
                . ' does not exist: '
                . $path
            );
        }

        return rtrim(
            $resolved,
            DIRECTORY_SEPARATOR
        );
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

<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Bridge from Moodle to the existing Python prepare-export worker.
 *
 * The PHP layer validates paths and arguments but deliberately does not
 * duplicate any ILIAS parsing logic.
 */
final class operator_package_preparer {
    /** @var array<string,string> */
    private const RECOVERY_OPTIONS = [
        'exercise_irss_recovery' => '--exercise-irss-recovery',
        'mediacast_media_recovery' => '--mediacast-media-recovery',
        'mediaobject_recovery' => '--mediaobject-recovery',
        'forum_attachment_recovery' => '--forum-attachment-recovery',
        'wiki_content_recovery' => '--wiki-content-recovery',
    ];


    /**
     * List readable native ILIAS ZIP exports from the configured imports root.
     *
     * @return array<string,array{path:string,size:int,mtime:int}>
     */
    public function available_imports(): array {
        $config = get_config('local_iliasmigration');
        $importsroot = $this->existing_directory(
            (string) (
                $config->importsroot
                ?? '/var/moodledata/ilias2moodle/imports'
            ),
            'ILIAS imports root'
        );

        $result = [];
        $entries = scandir($importsroot);

        if ($entries === false) {
            throw new \coding_exception(
                'Unable to read ILIAS imports root: '
                . $importsroot
            );
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (basename($entry) !== $entry
                    || strtolower(
                        pathinfo($entry, PATHINFO_EXTENSION)
                    ) !== 'zip') {
                continue;
            }

            $candidate = realpath(
                $importsroot
                . DIRECTORY_SEPARATOR
                . $entry
            );

            if ($candidate === false
                    || !is_file($candidate)
                    || !is_readable($candidate)
                    || !$this->is_inside(
                        $candidate,
                        $importsroot
                    )) {
                continue;
            }

            $result[$entry] = [
                'path' => $candidate,
                'size' => (int) filesize($candidate),
                'mtime' => (int) filemtime($candidate),
            ];
        }

        ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
        return $result;
    }

    /**
     * Prepare a normalized migration package from one native ILIAS ZIP.
     *
     * @param string $zippath Absolute ZIP path under configured imports root.
     * @param string $outputname Directory name to create under packages root.
     * @param array<string,string> $recoveries Optional complementary recovery roots.
     * @return array<string,mixed> Parsed prepare-export summary.
     */
    public function prepare(
        string $zippath,
        string $outputname,
        array $recoveries = []
    ): array {
        $config = get_config('local_iliasmigration');

        $projectroot = $this->existing_directory(
            (string) ($config->projectroot ?? '/opt/ilias2moodle'),
            'ILIAS2Moodle project root'
        );
        $importsroot = $this->existing_directory(
            (string) ($config->importsroot ?? '/var/moodledata/ilias2moodle/imports'),
            'ILIAS imports root'
        );
        $packagesroot = $this->existing_directory(
            (string) ($config->packagesroot ?? '/var/moodledata/ilias2moodle/packages'),
            'ILIAS2Moodle packages root'
        );

        if (!is_writable($packagesroot)) {
            throw new \coding_exception(
                'ILIAS2Moodle packages root is not writable: '
                . $packagesroot
            );
        }

        $worker = $projectroot
            . DIRECTORY_SEPARATOR
            . 'tools'
            . DIRECTORY_SEPARATOR
            . 'run-ilias2moodle.sh';

        if (!is_file($worker) || !is_executable($worker)) {
            throw new \coding_exception(
                'ILIAS2Moodle worker is missing or not executable: '
                . $worker
            );
        }

        $zip = realpath(trim($zippath));
        if ($zip === false || !is_file($zip) || !is_readable($zip)) {
            throw new \coding_exception(
                'ILIAS export ZIP is missing or unreadable: '
                . $zippath
            );
        }

        if (!$this->is_inside($zip, $importsroot)) {
            throw new \coding_exception(
                'ILIAS export ZIP must be located under the configured imports root.'
            );
        }

        if (strtolower(pathinfo($zip, PATHINFO_EXTENSION)) !== 'zip') {
            throw new \coding_exception(
                'Only native ILIAS ZIP exports are accepted.'
            );
        }

        $outputname = trim($outputname);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $outputname)
                || str_contains($outputname, '..')) {
            throw new \coding_exception(
                'Unsafe package output name.'
            );
        }

        $output = $packagesroot
            . DIRECTORY_SEPARATOR
            . $outputname;

        if (file_exists($output)) {
            throw new \coding_exception(
                'Prepared package output already exists: '
                . $output
            );
        }

        $iliasversion = trim(
            (string) ($config->iliasversion ?? '10.5')
        );
        if ($iliasversion === '') {
            throw new \coding_exception(
                'Default ILIAS source version must not be empty.'
            );
        }

        $command = [
            $worker,
            'prepare-export',
            '--zip',
            $zip,
            '--output',
            $output,
            '--ilias-version',
            $iliasversion,
        ];

        foreach (self::RECOVERY_OPTIONS as $key => $option) {
            $value = trim((string) ($recoveries[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $directory = $this->existing_directory(
                $value,
                $key
            );
            $command[] = $option;
            $command[] = $directory;
        }

        $result = $this->run($command, $projectroot);

        $migrationjson = $output
            . DIRECTORY_SEPARATOR
            . 'migration.json';

        if (!is_file($migrationjson) || !is_readable($migrationjson)) {
            throw new \coding_exception(
                'prepare-export completed without creating migration.json.'
            );
        }

        $recoveryplan = $output
            . DIRECTORY_SEPARATOR
            . 'recovery-plan.json';

        if (!empty($result['recovery_required'])
                && (!is_file($recoveryplan)
                    || !is_readable($recoveryplan))) {
            throw new \coding_exception(
                'prepare-export reported recovery requirements without creating recovery-plan.json.'
            );
        }

        $result['migration_json'] = $migrationjson;
        $result['recovery_plan_path'] = is_file($recoveryplan)
            ? $recoveryplan
            : null;
        $result['package_root'] = $output;
        $result['worker'] = $worker;

        return $result;
    }

    /**
     * @param list<string> $command
     * @return array<string,mixed>
     */
    private function run(array $command, string $cwd): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Child processes inherit the current umask. Keep generated
        // packages private to the service account and its group even when
        // the caller has an unusually permissive shell umask.
        $previousumask = umask(0027);
        try {
            $process = proc_open(
                $command,
                $descriptors,
                $pipes,
                $cwd
            );
        } finally {
            umask($previousumask);
        }

        if (!is_resource($process)) {
            throw new \coding_exception(
                'Unable to start ILIAS2Moodle preparation worker.'
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
                'ILIAS2Moodle preparation worker failed'
                . ' (exit=' . $exitcode . '): '
                . trim((string) $stderr)
            );
        }

        $stdout = trim((string) $stdout);
        $jsonstart = strpos($stdout, '{');
        if ($jsonstart === false) {
            throw new \coding_exception(
                'ILIAS2Moodle preparation worker returned no JSON summary.'
            );
        }

        $json = substr($stdout, $jsonstart);

        try {
            $summary = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \coding_exception(
                'Invalid JSON summary returned by ILIAS2Moodle preparation worker: '
                . $exception->getMessage()
            );
        }

        if (!is_array($summary)
                || ($summary['mode'] ?? '') !== 'prepare_export') {
            throw new \coding_exception(
                'Unexpected ILIAS2Moodle preparation worker summary.'
            );
        }

        $summary['worker_stdout'] = $stdout;
        $summary['worker_stderr'] = trim((string) $stderr);
        $summary['exit_code'] = $exitcode;

        return $summary;
    }

    private function existing_directory(
        string $path,
        string $label
    ): string {
        $resolved = realpath(trim($path));

        if ($resolved === false || !is_dir($resolved)) {
            throw new \coding_exception(
                $label . ' does not exist: ' . $path
            );
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
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

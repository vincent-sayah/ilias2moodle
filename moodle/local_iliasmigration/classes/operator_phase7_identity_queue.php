<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Queue and validate the read-only Phase 7.1 identity artifact for one package.
 */
final class operator_phase7_identity_queue {
    private const REQUESTS_DIRECTORY = 'identity-requests';
    private const IDENTITIES_DIRECTORY = 'identities';

    /**
     * Ensure one package has a source identity request or a valid artifact.
     *
     * @param array<string,mixed> $prepare Prepare-export summary.
     * @return array<string,mixed>
     */
    public function sync(
        string $package_name,
        string $zip_name,
        array $prepare
    ): array {
        $package_name = $this->safe_name(
            $package_name,
            'package'
        );
        $zip_name = basename(trim($zip_name));

        if ($zip_name === ''
                || strtolower(
                    pathinfo(
                        $zip_name,
                        PATHINFO_EXTENSION
                    )
                ) !== 'zip') {
            throw new \coding_exception(
                'Phase 7.1 identity queue ZIP name is invalid.'
            );
        }

        $migrationjson = realpath(
            (string) (
                $prepare['migration_json']
                ?? ''
            )
        );

        if ($migrationjson === false
                || !is_file($migrationjson)
                || !is_readable($migrationjson)
                || is_link($migrationjson)) {
            throw new \coding_exception(
                'Phase 7.1 identity queue cannot read migration.json.'
            );
        }

        try {
            $document = json_decode(
                (string) file_get_contents(
                    $migrationjson
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \coding_exception(
                'Phase 7.1 identity queue found invalid migration.json.'
            );
        }

        if (!is_array($document)
                || (string) (
                    $document['source']['lms']
                    ?? ''
                ) !== 'ILIAS') {
            throw new \coding_exception(
                'Phase 7.1 identity queue requires an ILIAS migration document.'
            );
        }

        $course_ref = trim(
            (string) (
                $document['course']['source_id']
                ?? ''
            )
        );
        $course_obj = trim(
            (string) (
                $document['course']['metadata']['obj_id']
                ?? ''
            )
        );
        $source_version = trim(
            (string) (
                $document['source']['version']
                ?? ''
            )
        );

        if ($course_ref === ''
                || !ctype_digit($course_ref)
                || (int) $course_ref <= 0) {
            throw new \coding_exception(
                'Phase 7.1 identity queue requires a positive ILIAS course ref_id.'
            );
        }

        $identitypath = $this->identity_path(
            $package_name
        );

        if ($this->identity_is_valid(
            $identitypath,
            $course_ref
        )) {
            $this->remove_request(
                $package_name
            );

            return [
                'ready' => true,
                'queued' => false,
                'package_name' => $package_name,
                'identity_path' => $identitypath,
                'course_ref' => $course_ref,
                'course_obj' => $course_obj,
                'source_version' => $source_version,
            ];
        }

        $request = [
            'schema_version' => '1.0',
            'kind' => 'phase7_identity',
            'package_name' => $package_name,
            'zip_name' => $zip_name,
            'course_ref' => $course_ref,
            'course_obj' => $course_obj,
            'source_version' => $source_version,
            'migration_sha256' => hash_file(
                'sha256',
                $migrationjson
            ) ?: '',
            'queued_at' => gmdate('c'),
        ];

        $requestpath = $this->request_path(
            $package_name
        );
        $tmp = $requestpath
            . '.tmp.'
            . bin2hex(random_bytes(6));

        $encoded = json_encode(
            $request,
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
                'Unable to write Phase 7.1 identity request.'
            );
        }

        @chmod($tmp, 0640);

        if (!rename(
            $tmp,
            $requestpath
        )) {
            @unlink($tmp);
            throw new \coding_exception(
                'Unable to publish Phase 7.1 identity request.'
            );
        }

        return [
            'ready' => false,
            'queued' => true,
            'package_name' => $package_name,
            'identity_path' => $identitypath,
            'request_path' => $requestpath,
            'course_ref' => $course_ref,
            'course_obj' => $course_obj,
            'source_version' => $source_version,
        ];
    }

    /**
     * Resolve a valid identity artifact for one prepared package.
     */
    public function require_identity_for_migration(
        string $migrationjson
    ): string {
        $migrationjson = realpath(
            trim($migrationjson)
        );

        if ($migrationjson === false
                || !is_file($migrationjson)
                || !is_readable($migrationjson)) {
            throw new \coding_exception(
                'Phase 7.1 cannot resolve the prepared migration document.'
            );
        }

        $package = basename(
            dirname($migrationjson)
        );
        $package = $this->safe_name(
            $package,
            'package'
        );

        try {
            $document = json_decode(
                (string) file_get_contents(
                    $migrationjson
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new \coding_exception(
                'Phase 7.1 cannot decode migration.json.'
            );
        }

        $course_ref = trim(
            (string) (
                $document['course']['source_id']
                ?? ''
            )
        );

        $identitypath = $this->identity_path(
            $package
        );

        if (!$this->identity_is_valid(
            $identitypath,
            $course_ref
        )) {
            throw new \coding_exception(
                'Phase 7.1 identity artifact is not ready for package '
                . $package
                . '. Wait for the ILIAS source worker and retry.'
            );
        }

        return $identitypath;
    }

    private function identity_is_valid(
        string $path,
        string $course_ref
    ): bool {
        if (!is_file($path)
                || is_link($path)
                || !is_readable($path)) {
            return false;
        }

        try {
            $identity = json_decode(
                (string) file_get_contents(
                    $path
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            return false;
        }

        return is_array($identity)
            && (string) (
                $identity['schema_version']
                ?? ''
            ) === '1.0'
            && (string) (
                $identity['phase']
                ?? ''
            ) === '7.1'
            && (string) (
                $identity['source']['lms']
                ?? ''
            ) === 'ILIAS'
            && (string) (
                $identity['course']['ref_id']
                ?? ''
            ) === $course_ref
            && is_array(
                $identity['users']
                ?? null
            )
            && is_array(
                $identity['course']['roles']
                ?? null
            );
    }

    private function request_path(
        string $package_name
    ): string {
        return $this->requests_root()
            . DIRECTORY_SEPARATOR
            . $package_name
            . '.json';
    }

    private function identity_path(
        string $package_name
    ): string {
        return $this->identities_root()
            . DIRECTORY_SEPARATOR
            . $package_name
            . '.json';
    }

    private function remove_request(
        string $package_name
    ): void {
        $path = $this->request_path(
            $package_name
        );

        if (is_file($path)
                && !is_link($path)) {
            @unlink($path);
        }
    }

    private function requests_root(): string {
        return $this->subdirectory(
            self::REQUESTS_DIRECTORY,
            0750
        );
    }

    private function identities_root(): string {
        return $this->subdirectory(
            self::IDENTITIES_DIRECTORY,
            02770
        );
    }

    private function subdirectory(
        string $name,
        int $mode
    ): string {
        $config = get_config(
            'local_iliasmigration'
        );

        $recoveriesroot = trim(
            (string) (
                $config->recoveriesroot
                ?? '/var/moodledata/ilias2moodle/recovery'
            )
        );

        if ($recoveriesroot === '') {
            throw new \coding_exception(
                'Recovery root must not be empty.'
            );
        }

        if (!is_dir($recoveriesroot)
                && !mkdir(
                    $recoveriesroot,
                    0750,
                    true
                )
                && !is_dir($recoveriesroot)) {
            throw new \coding_exception(
                'Unable to create recovery root.'
            );
        }

        $root = realpath(
            $recoveriesroot
        );

        if ($root === false
                || !is_dir($root)
                || !is_writable($root)) {
            throw new \coding_exception(
                'Recovery root is not writable.'
            );
        }

        $directory = $root
            . DIRECTORY_SEPARATOR
            . $name;

        if (!is_dir($directory)
                && !mkdir(
                    $directory,
                    $mode,
                    true
                )
                && !is_dir($directory)) {
            throw new \coding_exception(
                'Unable to create Phase 7.1 identity directory.'
            );
        }

        if (is_link($directory)) {
            throw new \coding_exception(
                'Phase 7.1 identity directory must not be a symlink.'
            );
        }

        @chmod($directory, $mode);

        $resolved = realpath(
            $directory
        );

        if ($resolved === false
                || !is_dir($resolved)) {
            throw new \coding_exception(
                'Phase 7.1 identity directory is invalid.'
            );
        }

        return $resolved;
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
                'Unsafe Phase 7.1 '
                . $label
                . ' name.'
            );
        }

        return $candidate;
    }
}

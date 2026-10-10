<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Server-side queue for source recovery work.
 *
 * Queue files contain metadata only. The recovery plan itself remains in the
 * prepared package and is fetched through the restricted SSH channel.
 */
final class operator_recovery_queue {
    private const REQUESTS_DIRECTORY = 'requests';

    /**
     * Queue one executable recovery plan, or remove a stale queue marker.
     *
     * @param array<string,mixed> $result prepare-export summary
     * @return array<string,mixed>|null
     */
    public function sync(
        string $package_name,
        string $zip_name,
        array $result
    ): ?array {
        $package_name = $this->safe_name(
            $package_name,
            'package'
        );
        $zip_name = basename(trim($zip_name));

        $plan = is_array(
            $result['recovery_plan'] ?? null
        )
            ? $result['recovery_plan']
            : [];

        $requests = is_array(
            $plan['requests'] ?? null
        )
            ? $plan['requests']
            : [];

        $unresolved = (int) (
            $plan['unresolved_count'] ?? 0
        );

        $required = !empty(
            $result['recovery_required']
        )
            && (int) (
                $result['missing_count'] ?? 0
            ) > 0
            && count($requests) > 0
            && $unresolved === 0;

        if (!$required) {
            $this->remove($package_name);
            return null;
        }

        $planpath = realpath(
            (string) (
                $result['recovery_plan_path']
                ?? ''
            )
        );

        if ($planpath === false
                || !is_file($planpath)
                || !is_readable($planpath)
                || is_link($planpath)) {
            throw new coding_exception(
                'Recovery queue cannot read recovery-plan.json.'
            );
        }

        $sha256 = hash_file(
            'sha256',
            $planpath
        );

        if ($sha256 === false) {
            throw new coding_exception(
                'Recovery queue cannot hash recovery-plan.json.'
            );
        }

        $request = [
            'schema_version' => '1.0',
            'package_name' => $package_name,
            'zip_name' => $zip_name,
            'plan_sha256' => $sha256,
            'request_count' => count($requests),
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

        $written = file_put_contents(
            $tmp,
            $encoded,
            LOCK_EX
        );

        if ($written === false) {
            throw new coding_exception(
                'Unable to write recovery queue request.'
            );
        }

        @chmod($tmp, 0640);

        if (!rename($tmp, $requestpath)) {
            @unlink($tmp);
            throw new coding_exception(
                'Unable to publish recovery queue request.'
            );
        }

        return $request;
    }

    public function remove(string $package_name): void {
        $package_name = $this->safe_name(
            $package_name,
            'package'
        );

        $path = $this->request_path(
            $package_name
        );

        if (is_file($path) && !is_link($path)) {
            @unlink($path);
        }
    }

    private function request_path(
        string $package_name
    ): string {
        return $this->requests_root()
            . DIRECTORY_SEPARATOR
            . $package_name
            . '.json';
    }

    private function requests_root(): string {
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
            throw new coding_exception(
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
            throw new coding_exception(
                'Unable to create recovery root.'
            );
        }

        $root = realpath($recoveriesroot);

        if ($root === false
                || !is_dir($root)
                || !is_writable($root)) {
            throw new coding_exception(
                'Recovery root is not writable.'
            );
        }

        $requests = $root
            . DIRECTORY_SEPARATOR
            . self::REQUESTS_DIRECTORY;

        if (!is_dir($requests)
                && !mkdir(
                    $requests,
                    0750,
                    true
                )
                && !is_dir($requests)) {
            throw new coding_exception(
                'Unable to create recovery requests directory.'
            );
        }

        if (is_link($requests)) {
            throw new coding_exception(
                'Recovery requests directory must not be a symlink.'
            );
        }

        return (string) realpath($requests);
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
            throw new coding_exception(
                'Unsafe recovery queue '
                . $label
                . ' name.'
            );
        }

        return $candidate;
    }
}

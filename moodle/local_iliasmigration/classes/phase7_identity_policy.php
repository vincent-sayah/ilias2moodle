<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Pure Phase 7.1 identity matching policy.
 *
 * The resolver never matches on display names. Username has priority. Email is
 * accepted only when it is non-empty and unique in both source and target.
 */
final class phase7_identity_policy {
    public function resolve(array $sourceusers, array $targetusers): array {
        $targetbyusername = [];
        $targetbyemail = [];
        $sourceemailcounts = [];

        foreach ($sourceusers as $sourceid => $source) {
            if (!is_array($source)) {
                continue;
            }
            $email = $this->normalise_email((string) ($source['email'] ?? ''));
            if ($email !== '') {
                $sourceemailcounts[$email] = ($sourceemailcounts[$email] ?? 0) + 1;
            }
        }

        foreach ($targetusers as $target) {
            if (!is_array($target)) {
                continue;
            }

            $username = $this->normalise_username((string) ($target['username'] ?? ''));
            if ($username !== '') {
                $targetbyusername[$username][] = $target;
            }

            $email = $this->normalise_email((string) ($target['email'] ?? ''));
            if ($email !== '') {
                $targetbyemail[$email][] = $target;
            }
        }

        $result = [];

        foreach ($sourceusers as $sourceid => $source) {
            if (!is_array($source)) {
                continue;
            }

            $sourceid = (string) $sourceid;
            $username = $this->normalise_username((string) ($source['login'] ?? ''));
            $email = $this->normalise_email((string) ($source['email'] ?? ''));

            $resolution = [
                'source_user_id' => $sourceid,
                'source_login' => (string) ($source['login'] ?? ''),
                'source_email' => (string) ($source['email'] ?? ''),
                'status' => 'NOT_IN_TARGET',
                'match_key' => null,
                'target_user_id' => null,
                'target_username' => null,
                'target_email' => null,
                'reason' => 'NO_UNIQUE_USERNAME_OR_EMAIL_MATCH',
            ];

            if (empty($source['exists'])) {
                $resolution['status'] = 'UNRESOLVED';
                $resolution['reason'] = 'SOURCE_USER_NOT_AVAILABLE';
                $result[$sourceid] = $resolution;
                continue;
            }

            if ($username !== '') {
                $usernamecandidates = $targetbyusername[$username] ?? [];
                if (count($usernamecandidates) === 1) {
                    $result[$sourceid] = $this->matched(
                        $resolution,
                        $usernamecandidates[0],
                        'username'
                    );
                    continue;
                }

                if (count($usernamecandidates) > 1) {
                    $resolution['status'] = 'AMBIGUOUS';
                    $resolution['reason'] = 'TARGET_USERNAME_NOT_UNIQUE';
                    $result[$sourceid] = $resolution;
                    continue;
                }
            }

            if ($email !== '') {
                $emailcandidates = $targetbyemail[$email] ?? [];

                if (($sourceemailcounts[$email] ?? 0) > 1 && $emailcandidates) {
                    $resolution['status'] = 'AMBIGUOUS';
                    $resolution['reason'] = 'SOURCE_EMAIL_NOT_UNIQUE';
                    $resolution['candidate_target_user_ids'] = array_values(array_map(
                        static fn(array $user): int => (int) ($user['id'] ?? 0),
                        $emailcandidates
                    ));
                    $result[$sourceid] = $resolution;
                    continue;
                }

                if (count($emailcandidates) > 1) {
                    $resolution['status'] = 'AMBIGUOUS';
                    $resolution['reason'] = 'TARGET_EMAIL_NOT_UNIQUE';
                    $resolution['candidate_target_user_ids'] = array_values(array_map(
                        static fn(array $user): int => (int) ($user['id'] ?? 0),
                        $emailcandidates
                    ));
                    $result[$sourceid] = $resolution;
                    continue;
                }

                if (count($emailcandidates) === 1
                        && ($sourceemailcounts[$email] ?? 0) === 1) {
                    $result[$sourceid] = $this->matched(
                        $resolution,
                        $emailcandidates[0],
                        'email'
                    );
                    continue;
                }
            }

            $result[$sourceid] = $resolution;
        }

        return $result;
    }

    private function matched(array $resolution, array $target, string $key): array {
        $resolution['status'] = 'MATCHED';
        $resolution['match_key'] = $key;
        $resolution['target_user_id'] = (int) ($target['id'] ?? 0);
        $resolution['target_username'] = (string) ($target['username'] ?? '');
        $resolution['target_email'] = (string) ($target['email'] ?? '');
        $resolution['reason'] = $key === 'username'
            ? 'UNIQUE_USERNAME_MATCH'
            : 'UNIQUE_EMAIL_MATCH';
        return $resolution;
    }

    private function normalise_username(string $value): string {
        return strtolower(trim($value));
    }

    private function normalise_email(string $value): string {
        return strtolower(trim($value));
    }
}

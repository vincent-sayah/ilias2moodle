<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Pure selection policy for Phase 3 internal-link rewriting.
 *
 * Database discovery stays in the package validator. This class only decides
 * whether the set of already validated Moodle targets is safe to rewrite to.
 */
final class phase3_internal_link_policy {
    /**
     * Select a single deterministic Moodle target.
     *
     * Duplicate candidates resolving to the same URL are harmless and collapse
     * to one target. Distinct URLs are considered ambiguous and preserve the
     * original ILIAS fallback instead of guessing.
     *
     * @param array $candidates Valid Moodle target descriptors.
     * @return array Rewrite decision.
     */
    public static function choose(array $candidates): array {
        $unique = [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $url = trim((string) ($candidate['url'] ?? ''));
            $targetid = (int) ($candidate['target_id'] ?? 0);
            if ($url === '' || $targetid <= 0) {
                continue;
            }

            // The final URL is the user-visible identity. Multiple mapping rows
            // leading to the exact same URL must not create false ambiguity.
            $unique[$url] = $candidate;
        }

        if (!$unique) {
            return [
                'action' => 'PRESERVE',
                'reason' => 'TARGET_NOT_MIGRATED',
                'candidate' => null,
            ];
        }

        if (count($unique) !== 1) {
            return [
                'action' => 'PRESERVE',
                'reason' => 'AMBIGUOUS_TARGET',
                'candidate' => null,
            ];
        }

        return [
            'action' => 'REWRITE',
            'reason' => null,
            'candidate' => reset($unique),
        ];
    }
}

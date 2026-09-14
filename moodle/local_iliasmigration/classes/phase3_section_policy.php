<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Pure policy for validating Phase 3 resource locations after order reconciliation.
 */
final class phase3_section_policy {
    /**
     * Return the effective section number expected for an UPDATE.
     *
     * Root resources normally belong to section 0. After the Phase 2 global-order
     * reconciler runs, however, a root resource may legitimately live in a
     * plugin-owned synthetic section. In that single case the current section is
     * accepted as the effective expected section. Parented resources always keep
     * the section resolved from their ILIAS parent mapping.
     *
     * Returning the original expected section for an untrusted root placement is
     * deliberate: the executor's strict section assertion will then reject the
     * manual/arbitrary move instead of silently accepting it.
     */
    public static function effective_update_section(
        string $parentsourceref,
        int $expectedsectionnumber,
        int $currentsectionnumber,
        bool $currentsectionisownedsynthetic
    ): int {
        if ($parentsourceref !== '') {
            return $expectedsectionnumber;
        }

        if ($currentsectionnumber === 0) {
            return 0;
        }

        if ($currentsectionisownedsynthetic) {
            return $currentsectionnumber;
        }

        return $expectedsectionnumber;
    }
}

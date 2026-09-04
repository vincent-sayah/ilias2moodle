<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Entry point for the future Moodle-side importer.
 */
final class importer {
    /**
     * Phase 2 will implement the neutral migration document import here.
     *
     * @param string $migrationjson Absolute path to migration.json.
     * @param bool $dryrun Whether Moodle writes are forbidden.
     */
    public function import(string $migrationjson, bool $dryrun = true): void {
        throw new \coding_exception('ILIAS2Moodle Moodle import starts in Phase 2.');
    }
}

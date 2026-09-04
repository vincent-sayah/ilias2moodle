<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads and validates the neutral ILIAS2Moodle migration document.
 */
final class migration_reader {
    /**
     * Load a migration.json file.
     *
     * @param string $path Absolute path to migration.json.
     * @return array Decoded and validated document.
     */
    public function read(string $path): array {
        if (!is_file($path) || !is_readable($path)) {
            throw new \moodle_exception('Migration file is missing or unreadable: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \moodle_exception('Unable to read migration file: ' . $path);
        }

        try {
            $document = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \moodle_exception('Invalid migration JSON: ' . $exception->getMessage());
        }

        if (!is_array($document)) {
            throw new \moodle_exception('Migration document must decode to an object.');
        }

        $this->validate($document);
        return $document;
    }

    /**
     * Validate the minimum contract required by the Moodle importer.
     *
     * @param array $document Decoded migration document.
     */
    private function validate(array $document): void {
        if (($document['schema_version'] ?? null) !== '1.0') {
            throw new \moodle_exception('Unsupported migration schema version. Expected 1.0.');
        }

        $source = $document['source'] ?? null;
        if (!is_array($source) || ($source['lms'] ?? null) !== 'ILIAS') {
            throw new \moodle_exception('Migration source must be ILIAS.');
        }

        $course = $document['course'] ?? null;
        if (!is_array($course)) {
            throw new \moodle_exception('Missing course object in migration document.');
        }

        if (trim((string) ($course['source_id'] ?? '')) === '') {
            throw new \moodle_exception('Missing ILIAS course source_id.');
        }

        if (trim((string) ($course['title'] ?? '')) === '') {
            throw new \moodle_exception('Missing ILIAS course title.');
        }

        if (!isset($course['items']) || !is_array($course['items'])) {
            throw new \moodle_exception('Course items must be an array.');
        }
    }
}

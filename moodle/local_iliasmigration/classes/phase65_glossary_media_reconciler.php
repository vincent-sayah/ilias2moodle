<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Reconcile embedded Glossary definition files after glossary_edit_entry().
 *
 * Moodle decides whether embedded editor files may use subdirectories before a
 * new Glossary entry receives its database id. For a brand-new entry this means
 * subdirs=false, while ILIAS2Moodle deliberately preserves normalized package
 * paths such as glossaries/<ref_id>/media/<mob_id>/file.ext in @@PLUGINFILE@@.
 *
 * This reconciler persists the validated assets directly through Moodle's file
 * storage API in mod_glossary/entry using those exact paths, then verifies that
 * every reference is backed by the exact source bytes. It performs no direct
 * writes to mdl_files and is designed to run inside the outer Glossary apply
 * transaction.
 */
final class phase65_glossary_media_reconciler {
    /** @var string Canonical migration package root. */
    private string $packageroot;

    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        $file = realpath($migrationjson);
        if ($root === false || !is_dir($root) || $file === false || !is_file($file)) {
            throw new \coding_exception('Unable to resolve migration.json or its package directory.');
        }
        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
    }

    /**
     * Persist and verify all embedded files for Glossary operations in an apply report.
     *
     * @param array $result Glossary executor report.
     * @return array Report enriched with reconciliation details.
     */
    public function reconcile(array $result): array {
        global $DB;

        $glossaries = 0;
        $entries = 0;
        $expectedfiles = 0;
        $storedfiles = 0;
        $verifiedfiles = 0;

        foreach ($result['operations'] as &$operation) {
            if (!is_array($operation) || (string) ($operation['kind'] ?? '') !== 'glossary') {
                continue;
            }

            $cmid = (int) ($operation['target_id'] ?? 0);
            if ($cmid <= 0) {
                throw new \coding_exception('Glossary media reconciliation requires a Moodle course module id.');
            }

            $cm = get_coursemodule_from_id('glossary', $cmid, 0, false, MUST_EXIST);
            $context = \context_module::instance($cmid);
            $structure = $this->load_structure($operation);
            $render = (new phase65_glossary_renderer())->render($structure);

            $assetsbyterm = [];
            foreach ((array) ($render['assets'] ?? []) as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $termid = (string) ($asset['term_id'] ?? '');
                if ($termid === '') {
                    throw new \coding_exception('Glossary media asset is missing its source term id.');
                }
                $assetsbyterm[$termid][] = $asset;
            }

            $entryresultindex = [];
            foreach ((array) ($operation['entries'] ?? []) as $index => $entryresult) {
                if (!is_array($entryresult)) {
                    continue;
                }
                $termid = (string) ($entryresult['source_id'] ?? '');
                if ($termid !== '') {
                    $entryresultindex[$termid] = $index;
                }
            }

            foreach ((array) ($render['terms'] ?? []) as $term) {
                if (!is_array($term)) {
                    continue;
                }

                $termid = (string) ($term['source_id'] ?? '');
                if ($termid === '' || !array_key_exists($termid, $entryresultindex)) {
                    throw new \coding_exception('Rendered Glossary term has no executor result to reconcile.');
                }

                $resultindex = $entryresultindex[$termid];
                $entryid = (int) ($operation['entries'][$resultindex]['target_entry_id'] ?? 0);
                if ($entryid <= 0) {
                    throw new \coding_exception('Glossary media reconciliation requires a target entry id.');
                }

                $entry = $DB->get_record(
                    'glossary_entries',
                    ['id' => $entryid, 'glossaryid' => (int) $cm->instance],
                    'id,definition',
                    MUST_EXIST
                );

                $assets = $assetsbyterm[$termid] ?? [];
                $replacement = $this->replace_entry_files($context, $entryid, $entry, $assets);
                $count = (int) ($replacement['count'] ?? 0);
                $checks = is_array($replacement['files'] ?? null) ? $replacement['files'] : [];

                $operation['entries'][$resultindex]['file_count'] = $count;
                $operation['entries'][$resultindex]['media_reconciled'] = true;
                $operation['entries'][$resultindex]['media_verification'] = $checks;

                $entries++;
                $expectedfiles += count($assets);
                $storedfiles += $count;
                $verifiedfiles += count(array_filter(
                    $checks,
                    static fn(array $check): bool => !empty($check['verified'])
                ));
            }

            $operation['moodle_file_count'] = array_sum(array_map(
                static fn(array $entry): int => (int) ($entry['file_count'] ?? 0),
                array_values(array_filter(
                    (array) ($operation['entries'] ?? []),
                    static fn($entry): bool => is_array($entry)
                ))
            ));
            $operation['media_reconciled'] = true;
            $glossaries++;
        }
        unset($operation);

        if ($expectedfiles !== $storedfiles || $expectedfiles !== $verifiedfiles) {
            throw new \coding_exception(
                "Glossary media reconciliation expected {$expectedfiles} files, stored {$storedfiles}, "
                . "and verified {$verifiedfiles}."
            );
        }

        $result['phase65_glossary_media_reconciliation'] = [
            'glossaries' => $glossaries,
            'entries' => $entries,
            'expected_files' => $expectedfiles,
            'stored_files' => $storedfiles,
            'verified_files' => $verifiedfiles,
            'binary_identity_verified' => true,
            'ready' => true,
        ];

        return $result;
    }

    /**
     * Replace one entry file area with the exact validated embedded assets.
     *
     * @return array{count:int,files:array}
     */
    private function replace_entry_files(
        \context_module $context,
        int $entryid,
        \stdClass $entry,
        array $assets
    ): array {
        $fs = get_file_storage();
        $destinations = [];

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $migrationpath = trim((string) ($asset['migration_path'] ?? ''));
            $pluginfile = trim((string) ($asset['pluginfile_path'] ?? ''));
            if ($migrationpath === '' || $pluginfile === '') {
                throw new \coding_exception('Glossary media asset is missing its package or pluginfile path.');
            }
            if (!str_contains((string) $entry->definition, $pluginfile)) {
                throw new \coding_exception(
                    'Glossary entry definition no longer contains the validated embedded-file reference.'
                );
            }

            [$filepath, $filename] = $this->destination_from_pluginfile($pluginfile);
            $key = $filepath . $filename;
            if (isset($destinations[$key])) {
                throw new \coding_exception('Glossary media assets collide on the same Moodle file path.');
            }

            $source = $this->resolve_relative_file($migrationpath);
            $sourcehash = sha1_file($source);
            $sourcesize = filesize($source);
            if ($sourcehash === false || $sourcesize === false) {
                throw new \coding_exception('Unable to fingerprint a validated Glossary source asset.');
            }

            $destinations[$key] = [
                'source' => $source,
                'source_sha1' => $sourcehash,
                'source_size' => (int) $sourcesize,
                'migration_path' => $migrationpath,
                'pluginfile_path' => $pluginfile,
                'filepath' => $filepath,
                'filename' => $filename,
            ];
        }

        // The mapped migration entry is source-owned. Rebuild its embedded file
        // area deterministically so removed or renamed source assets cannot linger.
        $fs->delete_area_files($context->id, 'mod_glossary', 'entry', $entryid);

        $checks = [];
        foreach ($destinations as $destination) {
            // Defensive per-path deletion: create_file_from_pathname() never
            // overwrites an existing pathname. If an earlier editor save recreated
            // the same destination, remove it explicitly before writing source bytes.
            $existing = $fs->get_file(
                $context->id,
                'mod_glossary',
                'entry',
                $entryid,
                $destination['filepath'],
                $destination['filename']
            );
            if ($existing) {
                $existing->delete();
            }

            $created = $fs->create_file_from_pathname(
                [
                    'contextid' => $context->id,
                    'component' => 'mod_glossary',
                    'filearea' => 'entry',
                    'itemid' => $entryid,
                    'filepath' => $destination['filepath'],
                    'filename' => $destination['filename'],
                ],
                $destination['source']
            );

            $this->assert_binary_identity($created, $destination);

            $stored = $fs->get_file(
                $context->id,
                'mod_glossary',
                'entry',
                $entryid,
                $destination['filepath'],
                $destination['filename']
            );
            if (!$stored) {
                throw new \coding_exception('A reconciled Glossary embedded file is missing after creation.');
            }
            $this->assert_binary_identity($stored, $destination);

            $checks[] = [
                'migration_path' => $destination['migration_path'],
                'pluginfile_path' => $destination['pluginfile_path'],
                'filepath' => $destination['filepath'],
                'filename' => $destination['filename'],
                'source_size' => $destination['source_size'],
                'stored_size' => (int) $stored->get_filesize(),
                'source_sha1' => $destination['source_sha1'],
                'stored_sha1' => (string) $stored->get_contenthash(),
                'verified' => true,
            ];
        }

        $storedarea = $fs->get_area_files(
            $context->id,
            'mod_glossary',
            'entry',
            $entryid,
            'id',
            false
        );
        if (count($storedarea) !== count($destinations)) {
            throw new \coding_exception(
                'Glossary embedded-file count differs from the validated source asset count.'
            );
        }

        return ['count' => count($storedarea), 'files' => $checks];
    }

    /** Require exact source/stored size and SHA1 identity. */
    private function assert_binary_identity(\stored_file $stored, array $destination): void {
        $sourcehash = (string) ($destination['source_sha1'] ?? '');
        $sourcesize = (int) ($destination['source_size'] ?? -1);
        $storedhash = (string) $stored->get_contenthash();
        $storedsize = (int) $stored->get_filesize();

        if ($sourcehash === ''
                || $sourcesize < 0
                || !hash_equals($sourcehash, $storedhash)
                || $sourcesize !== $storedsize) {
            throw new \coding_exception(
                'Glossary embedded file differs from the validated package source bytes.'
            );
        }
    }

    /**
     * Convert @@PLUGINFILE@@/path/to/file.ext into Moodle filepath + filename.
     *
     * @return array{0:string,1:string}
     */
    private function destination_from_pluginfile(string $pluginfile): array {
        $prefix = '@@PLUGINFILE@@/';
        if (!str_starts_with($pluginfile, $prefix)) {
            throw new \coding_exception('Glossary media reference is not a @@PLUGINFILE@@ URL.');
        }

        $encoded = substr($pluginfile, strlen($prefix));
        $rawparts = explode('/', $encoded);
        $parts = [];
        foreach ($rawparts as $rawpart) {
            if ($rawpart === '') {
                continue;
            }
            $part = rawurldecode($rawpart);
            if ($part === ''
                    || $part === '.'
                    || $part === '..'
                    || str_contains($part, '/')
                    || str_contains($part, '\\')
                    || str_contains($part, "\0")) {
                throw new \coding_exception('Unsafe Glossary @@PLUGINFILE@@ path segment.');
            }
            $parts[] = $part;
        }

        if (!$parts) {
            throw new \coding_exception('Glossary @@PLUGINFILE@@ path has no filename.');
        }

        $filename = array_pop($parts);
        $filepath = $parts ? '/' . implode('/', $parts) . '/' : '/';
        return [$filepath, $filename];
    }

    /** Load the normalized Glossary structure referenced by one operation. */
    private function load_structure(array $operation): array {
        $path = $this->resolve_relative_file(
            (string) ($operation['migration_structure_path'] ?? '')
        );
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \coding_exception('Unable to read Glossary structure.json during media reconciliation.');
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \coding_exception(
                'Invalid Glossary structure.json during media reconciliation: ' . $exception->getMessage()
            );
        }
        if (!is_array($decoded)) {
            throw new \coding_exception('Glossary structure.json must contain a JSON object.');
        }
        return $decoded;
    }

    /** Resolve one package-relative file and keep it inside the package root. */
    private function resolve_relative_file(string $relative): string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../')) {
            throw new \coding_exception('Unsafe Glossary media package-relative path.');
        }

        $candidate = realpath($this->packageroot . DIRECTORY_SEPARATOR . $relative);
        if ($candidate === false || !is_file($candidate)) {
            throw new \coding_exception('Validated Glossary media file is missing from the package.');
        }
        if (!str_starts_with($candidate, $this->packageroot . DIRECTORY_SEPARATOR)) {
            throw new \coding_exception('Glossary media package path escapes the package root.');
        }
        return $candidate;
    }
}

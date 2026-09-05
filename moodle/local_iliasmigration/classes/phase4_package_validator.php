<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates Phase 4 SCORM packages without writing Moodle content.
 */
final class phase4_package_validator {
    /** @var string Canonical migration package root. */
    private string $packageroot;

    /**
     * @param string $migrationjson Absolute path to migration.json.
     */
    public function __construct(string $migrationjson) {
        $root = realpath(dirname($migrationjson));
        if ($root === false || !is_dir($root)) {
            throw new \coding_exception('Unable to resolve the migration package directory.');
        }
        $this->packageroot = rtrim($root, DIRECTORY_SEPARATOR);
    }

    /**
     * Validate all Phase 4 SCORM operations and annotate the dry-run plan.
     *
     * @param array $plan Phase 4 plan.
     * @return array Annotated plan.
     */
    public function validate(array $plan): array {
        $checked = 0;
        $blocked = 0;

        foreach ($plan['operations'] as &$operation) {
            if (($operation['kind'] ?? '') !== 'scorm') {
                continue;
            }

            $action = (string) ($operation['action'] ?? '');
            if ($action === 'BLOCKED') {
                $blocked++;
                continue;
            }
            if (!in_array($action, ['CREATE', 'UPDATE'], true)) {
                continue;
            }

            $checked++;
            $this->validate_scorm_archive($operation, $plan['warnings']);
            if (($operation['action'] ?? '') === 'BLOCKED') {
                $blocked++;
            }
        }
        unset($operation);

        $prerequisitesready = !empty($plan['phase4_prerequisites']['ready']);
        $phase3packageready = !isset($plan['phase3_package']) || !empty($plan['phase3_package']['ready']);
        $archivechecksready = $blocked === 0 && $checked > 0;

        if ($checked === 0) {
            $plan['warnings'][] = [
                'code' => 'NO_SCORM_PACKAGE_FOUND',
                'message' => 'No SCORM CREATE/UPDATE operation was found in this migration package.',
            ];
        }

        $plan['phase4_package'] = [
            'root' => $this->packageroot,
            'checked_scorm_packages' => $checked,
            'blocked_scorm_packages' => $blocked,
            'archive_checks_ready' => $archivechecksready,
            'prerequisites_ready' => $prerequisitesready && $phase3packageready,
            'ready' => $archivechecksready && $prerequisitesready && $phase3packageready,
        ];

        return $plan;
    }

    /**
     * Validate one SCORM ZIP package using Moodle's archive API.
     *
     * Validation deliberately lists and inspects the ZIP without extracting the
     * potentially large archive into Moodle. The later apply step will use the
     * official mod_scorm package/file APIs.
     *
     * @param array $operation SCORM operation.
     * @param array $warnings Collected warnings.
     */
    private function validate_scorm_archive(array &$operation, array &$warnings): void {
        global $CFG;

        $relative = (string) ($operation['migration_package_path'] ?? '');
        $resolved = $this->resolve_relative_file($relative);
        if ($resolved === null) {
            $this->block(
                $operation,
                'SCORM_PACKAGE_MISSING',
                'The SCORM migration_package_path is missing, unsafe or not present in the package.'
            );
            return;
        }

        $packagesize = filesize($resolved);
        if ($packagesize === false || $packagesize <= 0) {
            $this->block($operation, 'SCORM_PACKAGE_EMPTY', 'The SCORM ZIP package is empty or unreadable.');
            return;
        }

        if (strtolower((string) pathinfo($resolved, PATHINFO_EXTENSION)) !== 'zip') {
            $this->block($operation, 'SCORM_PACKAGE_NOT_ZIP', 'A local SCORM package must be a ZIP archive.');
            return;
        }

        require_once($CFG->libdir . '/filestorage/file_archive.php');
        require_once($CFG->libdir . '/filestorage/zip_archive.php');

        $archive = new \zip_archive();
        if (!$archive->open($resolved, \file_archive::OPEN)) {
            $this->block($operation, 'SCORM_ZIP_OPEN_FAILED', 'Moodle could not open the SCORM ZIP archive.');
            return;
        }

        try {
            $entries = $archive->list_files();
            if ($entries === false || count($entries) === 0) {
                $this->block($operation, 'SCORM_ZIP_EMPTY', 'The SCORM ZIP archive contains no usable entries.');
                return;
            }

            $entrycount = 0;
            $filecount = 0;
            $uncompressed = 0;
            $manifestindex = null;
            $manifestsize = 0;
            $seenpaths = [];

            foreach ($entries as $entry) {
                $entrycount++;
                $rawpath = (string) ($entry->original_pathname ?? $entry->pathname ?? '');
                $normalpath = $this->normalise_zip_path($rawpath);
                if ($normalpath === null) {
                    $this->block(
                        $operation,
                        'SCORM_ZIP_UNSAFE_PATH',
                        'The SCORM ZIP contains an absolute, traversal or otherwise unsafe entry path.'
                    );
                    return;
                }

                $collisionkey = strtolower($normalpath);
                if (isset($seenpaths[$collisionkey])) {
                    $this->block(
                        $operation,
                        'SCORM_ZIP_PATH_COLLISION',
                        'The SCORM ZIP contains duplicate paths after normalisation.'
                    );
                    return;
                }
                $seenpaths[$collisionkey] = true;

                if (!empty($entry->is_directory)) {
                    continue;
                }

                $filecount++;
                $size = max(0, (int) ($entry->size ?? 0));
                $uncompressed += $size;

                if (strtolower($normalpath) === 'imsmanifest.xml') {
                    $manifestindex = (int) ($entry->index ?? -1);
                    $manifestsize = $size;
                }
            }

            if ($filecount === 0) {
                $this->block($operation, 'SCORM_ZIP_NO_FILES', 'The SCORM ZIP contains no files.');
                return;
            }
            if ($manifestindex === null || $manifestindex < 0) {
                $this->block(
                    $operation,
                    'SCORM_MANIFEST_MISSING',
                    'A SCORM package requires imsmanifest.xml at the root of the ZIP archive.'
                );
                return;
            }
            if ($manifestsize <= 0 || $manifestsize > 8 * 1024 * 1024) {
                $this->block(
                    $operation,
                    'SCORM_MANIFEST_SIZE_INVALID',
                    'imsmanifest.xml is empty or unexpectedly large.'
                );
                return;
            }

            $manifest = $this->read_manifest($archive, $manifestindex, $manifestsize);
            if ($manifest === null) {
                $this->block($operation, 'SCORM_MANIFEST_READ_FAILED', 'Unable to read imsmanifest.xml from the ZIP.');
                return;
            }

            $manifestinfo = $this->parse_manifest($manifest);
            if ($manifestinfo === null) {
                $this->block($operation, 'SCORM_MANIFEST_XML_INVALID', 'imsmanifest.xml is not valid manifest XML.');
                return;
            }

            $sha256 = hash_file('sha256', $resolved);
            $ratio = $packagesize > 0 ? round($uncompressed / $packagesize, 2) : null;

            $operation['package_validation'] = [
                'status' => 'OK',
                'relative_path' => $relative,
                'archive_size' => (int) $packagesize,
                'sha256' => $sha256 !== false ? $sha256 : null,
                'entry_count' => $entrycount,
                'file_count' => $filecount,
                'uncompressed_size' => $uncompressed,
                'compression_ratio' => $ratio,
                'manifest' => $manifestinfo,
            ];

            if ($packagesize >= 250 * 1024 * 1024) {
                $warnings[] = [
                    'code' => 'SCORM_LARGE_PACKAGE',
                    'source_ref_id' => (string) ($operation['source_ref_id'] ?? ''),
                    'archive_size' => (int) $packagesize,
                    'uncompressed_size' => $uncompressed,
                    'message' => 'This SCORM package is large; verify Moodle dataroot free space and runtime limits before apply.',
                ];
            }

            if ($ratio !== null && $ratio >= 100 && $uncompressed >= 1024 * 1024 * 1024) {
                $warnings[] = [
                    'code' => 'SCORM_HIGH_COMPRESSION_RATIO',
                    'source_ref_id' => (string) ($operation['source_ref_id'] ?? ''),
                    'compression_ratio' => $ratio,
                    'message' => 'The archive has a high compression ratio; review it before extraction/apply.',
                ];
            }

            $datarootfree = @disk_free_space($CFG->dataroot);
            if ($datarootfree !== false && $datarootfree < ($packagesize + $uncompressed)) {
                $warnings[] = [
                    'code' => 'SCORM_DATAROOT_SPACE_LOW',
                    'source_ref_id' => (string) ($operation['source_ref_id'] ?? ''),
                    'dataroot_free_bytes' => (int) $datarootfree,
                    'estimated_required_bytes' => (int) ($packagesize + $uncompressed),
                    'message' => 'Moodle dataroot free space is lower than the package plus its uncompressed size.',
                ];
            }
        } finally {
            $archive->close();
        }
    }

    /**
     * Read a manifest entry without extracting the whole ZIP.
     */
    private function read_manifest(\zip_archive $archive, int $index, int $size): ?string {
        $stream = $archive->get_stream($index);
        if ($stream === false) {
            return null;
        }
        try {
            $content = stream_get_contents($stream, $size + 1);
            if ($content === false || strlen($content) !== $size) {
                return null;
            }
            return $content;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Parse basic SCORM manifest metadata safely.
     *
     * @return array|null Manifest descriptor.
     */
    private function parse_manifest(string $xml): ?array {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                return null;
            }
            if (!$dom->documentElement || strtolower($dom->documentElement->localName) !== 'manifest') {
                return null;
            }

            $xpath = new \DOMXPath($dom);
            $schemanodes = $xpath->query('//*[local-name()="schema"]');
            $versionnodes = $xpath->query('//*[local-name()="schemaversion"]');
            $organizations = $xpath->query('//*[local-name()="organizations"]/*[local-name()="organization"]');
            $resources = $xpath->query('//*[local-name()="resources"]/*[local-name()="resource"]');

            $schema = ($schemanodes && $schemanodes->length > 0)
                ? trim((string) $schemanodes->item(0)->textContent)
                : '';
            $version = ($versionnodes && $versionnodes->length > 0)
                ? trim((string) $versionnodes->item(0)->textContent)
                : '';

            $standard = 'unknown';
            if (stripos($version, '2004') !== false) {
                $standard = 'SCORM_2004';
            } else if (preg_match('/(^|\s)1\.2($|\s)/', $version) === 1) {
                $standard = 'SCORM_1_2';
            }

            return [
                'schema' => $schema,
                'schema_version' => $version,
                'detected_standard' => $standard,
                'organization_count' => $organizations ? $organizations->length : 0,
                'resource_count' => $resources ? $resources->length : 0,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Normalise and validate one archive entry path.
     */
    private function normalise_zip_path(string $path): ?string {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return null;
        }
        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            return null;
        }

        $normal = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $normal[] = $part;
        }

        if (!$normal) {
            return null;
        }
        return implode('/', $normal);
    }

    /**
     * Resolve a package-relative file and keep it inside the package root.
     */
    private function resolve_relative_file(string $relative): ?string {
        $relative = trim(str_replace('\\', '/', $relative));
        if ($relative === '' || str_starts_with($relative, '/')) {
            return null;
        }
        if (preg_match('/^[A-Za-z]:\//', $relative) === 1) {
            return null;
        }

        $parts = explode('/', $relative);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }

        $candidate = $this->packageroot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
        $resolved = realpath($candidate);
        if ($resolved === false
                || !str_starts_with($resolved, $this->packageroot . DIRECTORY_SEPARATOR)
                || !is_file($resolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * Mark one SCORM operation as blocked.
     */
    private function block(array &$operation, string $code, string $message): void {
        $requested = (string) ($operation['action'] ?? '');
        $operation['requested_action'] = $requested;
        $operation['action'] = 'BLOCKED';
        $operation['reason'] = $code;
        $operation['package_validation'] = [
            'status' => 'ERROR',
            'code' => $code,
            'message' => $message,
        ];
    }
}

<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Materializes operator packages safely.
 */
final class operator_package {
    private static function job_root(int $jobid): string {
        global $CFG;
        $root = $CFG->dataroot . '/local_iliasmigration/jobs/' . $jobid;
        make_writable_directory($root);
        return $root;
    }

    public static function use_existing_migration_json(
        int $jobid,
        string $path
    ): array {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new \coding_exception('The server migration.json path is not readable.');
        }
        if (strtolower(basename($real)) !== 'migration.json') {
            throw new \coding_exception('The server path must point to migration.json.');
        }

        return [
            'sourcefilename' => basename(dirname($real)) . '/migration.json',
            'sourcehash' => hash_file('sha256', $real),
            'packagepath' => dirname($real),
            'migrationjson' => $real,
            'storage' => 'server_path',
        ];
    }

    public static function store_uploaded_zip(
        int $jobid,
        string $temporarypath,
        string $originalname
    ): array {
        if (!class_exists(\ZipArchive::class)) {
            throw new \coding_exception('PHP ZipArchive is required for package upload.');
        }
        if (!is_file($temporarypath) || !is_readable($temporarypath)) {
            throw new \coding_exception('Uploaded package is not readable.');
        }

        $root = self::job_root($jobid);
        $archive = $root . '/input.zip';
        if (!copy($temporarypath, $archive)) {
            throw new \coding_exception('Unable to persist uploaded package.');
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($archive);
        if ($opened !== true) {
            throw new \coding_exception('Uploaded package is not a readable ZIP archive.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                self::assert_safe_zip_entry($zip, $i, $name);
            }

            $package = $root . '/package';
            make_writable_directory($package);
            if (!$zip->extractTo($package)) {
                throw new \coding_exception('Unable to extract uploaded migration package.');
            }
        } finally {
            $zip->close();
        }

        $migrationjson = self::find_migration_json($package);

        return [
            'sourcefilename' => clean_param($originalname, PARAM_FILE),
            'sourcehash' => hash_file('sha256', $archive),
            'packagepath' => dirname($migrationjson),
            'migrationjson' => $migrationjson,
            'storage' => 'uploaded_zip',
        ];
    }

    private static function assert_safe_zip_entry(
        \ZipArchive $zip,
        int $index,
        string $name
    ): void {
        $normalized = str_replace('\\', '/', $name);

        if ($normalized === ''
                || str_contains($normalized, "\0")
                || str_starts_with($normalized, '/')
                || preg_match('/^[A-Za-z]:\//', $normalized)) {
            throw new \coding_exception('Unsafe path in uploaded ZIP.');
        }

        $parts = array_values(array_filter(
            explode('/', $normalized),
            static fn(string $part): bool => $part !== ''
        ));
        if (in_array('..', $parts, true)) {
            throw new \coding_exception('ZIP path traversal detected.');
        }

        if (method_exists($zip, 'getExternalAttributesIndex')) {
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                $mode = ($attributes >> 16) & 0170000;
                if ($mode === 0120000) {
                    throw new \coding_exception('Symbolic links are not accepted in uploaded packages.');
                }
            }
        }
    }

    private static function find_migration_json(string $root): string {
        $matches = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            if (strtolower($file->getFilename()) === 'migration.json') {
                $matches[] = $file->getRealPath();
            }
        }

        $matches = array_values(array_filter($matches));
        if (count($matches) !== 1) {
            throw new \coding_exception(
                'The package must contain exactly one migration.json.'
            );
        }

        return (string) $matches[0];
    }
}

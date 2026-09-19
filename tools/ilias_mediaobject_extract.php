<?php

declare(strict_types=1);

/**
 * Ilias2Moodle - Extracteur read-only d'un fichier local MediaObject ILIAS.
 *
 * Le fichier est lu via l'API MediaObjects/IRSS d'ILIAS, sans accès direct
 * aux tables ni au stockage physique.
 *
 * Exemple :
 *
 * php tools/ilias_mediaobject_extract.php \
 *   --mob-id=810 \
 *   --location=clip1.mp4 \
 *   --ilias-root=/var/www/ilias \
 *   --client=ilias10 \
 *   --output=/tmp/ilias2moodle-media
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERREUR : ce script doit être exécuté en CLI.\n");
    exit(2);
}

function envOrDefault(string $name, string $default): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $default;
    }
    return trim($value);
}

function usage(string $script): void
{
    $message = <<<TXT
Usage :

  php {$script} --mob-id=<ID> --location=<path> [options]

Options :

  --mob-id=<ID>             ID ILIAS du MediaObject
  --location=<path>         Location du media_item (ex: clip1.mp4)
  --ilias-root=<path>       Racine ILIAS
  --client=<client_id>      Client ILIAS
  --output=<path>           Répertoire de récupération
  --help                    Affiche cette aide

Variables d'environnement :

  ILIAS_ROOT
  ILIAS_CLIENT_ID
  ILIAS_MEDIA_OUTPUT

TXT;

    fwrite(STDERR, $message);
}

$options = getopt(
    '',
    [
        'mob-id:',
        'location:',
        'ilias-root:',
        'client:',
        'output:',
        'help',
    ]
);

if ($options === false) {
    usage($argv[0]);
    exit(2);
}

if (array_key_exists('help', $options)) {
    usage($argv[0]);
    exit(0);
}

$mobId = (int) ($options['mob-id'] ?? 0);
$location = trim((string) ($options['location'] ?? ''));

if ($mobId <= 0) {
    fwrite(STDERR, "ERREUR : --mob-id doit être un entier positif.\n");
    exit(2);
}

if (
    $location === '' ||
    str_starts_with($location, '/') ||
    str_contains($location, '../') ||
    str_contains($location, '..\\')
) {
    fwrite(STDERR, "ERREUR : --location invalide ou non sûre.\n");
    exit(2);
}

$iliasRoot = rtrim(
    (string) (
        $options['ilias-root']
        ?? envOrDefault('ILIAS_ROOT', '/var/www/ilias')
    ),
    '/'
);

$clientId = trim(
    (string) (
        $options['client']
        ?? envOrDefault('ILIAS_CLIENT_ID', 'ilias10')
    )
);

$outputBase = rtrim(
    (string) (
        $options['output']
        ?? envOrDefault(
            'ILIAS_MEDIA_OUTPUT',
            '/tmp/ilias2moodle-media'
        )
    ),
    '/'
);

if (
    $clientId === '' ||
    !preg_match('/^[A-Za-z0-9_.-]+$/', $clientId)
) {
    throw new RuntimeException('Identifiant client ILIAS invalide.');
}

try {
    if (!chdir($iliasRoot)) {
        throw new RuntimeException(
            "Impossible d'accéder à $iliasRoot"
        );
    }

    require_once $iliasRoot . '/vendor/composer/vendor/autoload.php';

    if (!defined('CLIENT_ID')) {
        define('CLIENT_ID', $clientId);
    }

    $_SERVER['REQUEST_METHOD'] ??= 'GET';
    $_SERVER['HTTP_HOST']      ??= 'localhost';
    $_SERVER['SERVER_NAME']    ??= 'localhost';
    $_SERVER['SERVER_PORT']    ??= '80';
    $_SERVER['REQUEST_URI']    ??= '/';
    $_SERVER['SCRIPT_NAME']    ??= '/' . basename($argv[0]);
    $_SERVER['PHP_SELF']       ??= $_SERVER['SCRIPT_NAME'];
    $_SERVER['HTTPS']          ??= 'off';

    ilContext::init(ilContext::CONTEXT_CRON);
    ilInitialisation::initILIAS();

    global $DIC;

    if (!isset($DIC)) {
        throw new RuntimeException('DIC ILIAS non initialisé.');
    }

    $manager = $DIC
        ->mediaObjects()
        ->internal()
        ->domain()
        ->mediaObject();

    echo "============================================\n";
    echo " Ilias2Moodle - Extraction MediaObject\n";
    echo "============================================\n";
    echo "ILIAS root : $iliasRoot\n";
    echo "Client     : " . CLIENT_ID . "\n";
    echo "Mob ID     : $mobId\n";
    echo "Location   : $location\n";
    echo "--------------------------------------------\n";

    if (!$manager->hasLocalFile($mobId, $location)) {
        echo "RESULTAT    : LOCAL_FILE_NOT_FOUND\n";
        exit(3);
    }

    $info = $manager->getInfoOfEntry($mobId, $location);
    $stream = $manager->getLocationStream($mobId, $location);
    $resource = $stream->detach();

    if (!is_resource($resource)) {
        throw new RuntimeException(
            "Impossible d'obtenir le flux pour $location"
        );
    }

    $outputDir = $outputBase . '/mob_' . $mobId;
    if (
        !is_dir($outputDir) &&
        !mkdir($outputDir, 0755, true) &&
        !is_dir($outputDir)
    ) {
        throw new RuntimeException(
            "Impossible de créer $outputDir"
        );
    }

    $safeName = preg_replace(
        '/[\x00-\x1F\x7F\/\\\\]+/u',
        '_',
        basename($location)
    );

    if (
        $safeName === null ||
        trim($safeName) === '' ||
        $safeName === '.' ||
        $safeName === '..'
    ) {
        $safeName = 'media_' . $mobId;
    }

    $target = $outputDir . '/' . $safeName;

    $out = fopen($target, 'wb');
    if ($out === false) {
        throw new RuntimeException(
            "Impossible d'ouvrir $target en écriture."
        );
    }

    $written = stream_copy_to_stream($resource, $out);
    fclose($out);

    if ($written === false) {
        throw new RuntimeException(
            "Impossible d'écrire $target"
        );
    }

    $size = filesize($target);
    $sha256 = hash_file('sha256', $target);

    $manifest = [
        'mob_id' => $mobId,
        'location' => $location,
        'output_name' => $safeName,
        'output_path' => $target,
        'size' => $size,
        'sha256' => $sha256,
        'entry_info' => $info,
        'source' => 'ilias_mediaobject_api',
        'read_only' => true,
    ];

    $manifestFile = $outputDir . '/manifest.json';

    file_put_contents(
        $manifestFile,
        json_encode(
            $manifest,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        ) . PHP_EOL
    );

    echo "[OK] $safeName : $size octets\n";
    echo "SHA256      : $sha256\n";
    echo "Manifest    : $manifestFile\n";
    echo "RESULTAT    : EXTRACTION_OK\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "\n[ERREUR] "
        . get_class($e)
        . ': '
        . $e->getMessage()
        . "\n"
    );
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

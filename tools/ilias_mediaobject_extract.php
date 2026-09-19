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

    /*
     * Ne pas passer par $DIC->mediaObjects()->internal() ici :
     * InternalService construit aussi InternalGUIService, qui attend
     * des constantes HTTP absentes dans un contexte CLI.
     *
     * Pour cette extraction read-only, le repository MediaObject
     * suffit. Il donne accès au conteneur IRSS et au flux du fichier
     * sans initialiser la couche GUI.
     */
    $mediaData = new \ILIAS\MediaObjects\InternalDataService();
    $mediaRepos = new \ILIAS\MediaObjects\InternalRepoService(
        $mediaData,
        $DIC->database()
    );
    $manager = $mediaRepos->mediaObject();

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

    $copyResult = stream_copy_to_stream($resource, $out);
    fclose($out);

    clearstatcache(true, $target);

    if (!is_file($target)) {
        throw new RuntimeException(
            "Aucun fichier n'a été produit : $target"
        );
    }

    $size = filesize($target);
    if ($size === false) {
        throw new RuntimeException(
            "Impossible de déterminer la taille de $target"
        );
    }

    $expectedSize = isset($info['size'])
        ? (int) $info['size']
        : 0;

    /*
     * ZIPStream peut retourner false à stream_copy_to_stream() après
     * avoir néanmoins livré l'intégralité de l'entrée. On ne se fie
     * donc pas à cette valeur seule : la validation décisive est la
     * taille annoncée par le conteneur IRSS.
     */
    if ($expectedSize > 0 && $size !== $expectedSize) {
        throw new RuntimeException(
            "Taille extraite incorrecte pour $target : "
            . "$size octets obtenus, $expectedSize attendus."
        );
    }

    if ($size <= 0) {
        throw new RuntimeException(
            "Le fichier extrait est vide : $target"
        );
    }

    $sha256 = hash_file('sha256', $target);
    if ($sha256 === false) {
        throw new RuntimeException(
            "Impossible de calculer le SHA256 de $target"
        );
    }

    $copyWarning = $copyResult === false
        ? 'STREAM_COPY_REPORTED_FALSE_BUT_SIZE_VALIDATED'
        : null;

    if ($copyWarning !== null) {
        echo "[WARN] stream_copy_to_stream() a retourné false, "
            . "mais la taille IRSS est conforme.\n";
    }

    $manifest = [
        'mob_id' => $mobId,
        'location' => $location,
        'output_name' => $safeName,
        'output_path' => $target,
        'expected_size' => $expectedSize,
        'size' => $size,
        'sha256' => $sha256,
        'stream_copy_result' => $copyResult,
        'warning' => $copyWarning,
        'status' => (
            $expectedSize > 0 && $size === $expectedSize
                ? 'OK'
                : 'OK_SIZE_UNAVAILABLE'
        ),
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

<?php

declare(strict_types=1);

/**
 * Ilias2Moodle - Read-only extraction of one ILIAS Forum post attachment.
 *
 * The attachment is read through ILIAS ResourceStorage using the post RCID.
 * No database write and no direct fsv2 access is performed.
 *
 * Example:
 *
 * php tools/ilias_forum_attachment_extract.php \
 *   --forum-obj-id=807 \
 *   --post-id=18 \
 *   --filename=handout.pdf \
 *   --ilias-root=/var/www/ilias \
 *   --client=ilias10 \
 *   --output=/tmp/ilias2moodle-forum
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERREUR : ce script doit être exécuté en CLI.\n");
    exit(2);
}

function forumEnvOrDefault(string $name, string $default): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $default;
    }
    return trim($value);
}

function forumUsage(string $script): void
{
    fwrite(
        STDERR,
        <<<TXT
Usage :

  php {$script} --forum-obj-id=<ID> --post-id=<ID> --filename=<NAME> [options]

Options :

  --forum-obj-id=<ID>       ObjId ILIAS du Forum
  --post-id=<ID>            Identifiant du post Forum
  --filename=<NAME>         Nom exact de la pièce jointe
  --ilias-root=<path>       Racine ILIAS
  --client=<client_id>      Client ILIAS
  --output=<path>           Répertoire de récupération
  --help                    Affiche cette aide

TXT
    );
}

$options = getopt(
    '',
    [
        'forum-obj-id:',
        'post-id:',
        'filename:',
        'ilias-root:',
        'client:',
        'output:',
        'help',
    ]
);

if ($options === false) {
    forumUsage($argv[0]);
    exit(2);
}

if (array_key_exists('help', $options)) {
    forumUsage($argv[0]);
    exit(0);
}

$forumObjId = (int) ($options['forum-obj-id'] ?? 0);
$postId = (int) ($options['post-id'] ?? 0);
$filename = trim((string) ($options['filename'] ?? ''));

if ($forumObjId <= 0 || $postId <= 0) {
    fwrite(STDERR, "ERREUR : --forum-obj-id et --post-id doivent être positifs.\n");
    exit(2);
}

if (
    $filename === '' ||
    basename($filename) !== $filename ||
    str_contains($filename, '/') ||
    str_contains($filename, '\\')
) {
    fwrite(STDERR, "ERREUR : --filename doit être un nom de fichier simple et sûr.\n");
    exit(2);
}

$iliasRoot = rtrim(
    (string) (
        $options['ilias-root']
        ?? forumEnvOrDefault('ILIAS_ROOT', '/var/www/ilias')
    ),
    '/'
);

$clientId = trim(
    (string) (
        $options['client']
        ?? forumEnvOrDefault('ILIAS_CLIENT_ID', 'ilias10')
    )
);

$outputBase = rtrim(
    (string) (
        $options['output']
        ?? forumEnvOrDefault('ILIAS_FORUM_OUTPUT', '/tmp/ilias2moodle-forum')
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
        throw new RuntimeException("Impossible d'accéder à $iliasRoot");
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

    $post = new ilForumPost($postId);
    $rcidValue = trim((string) $post->getRCID());

    if ($rcidValue === '' || $rcidValue === ilForumPost::NO_RCID) {
        echo "RESULTAT    : POST_WITHOUT_ATTACHMENT_COLLECTION\n";
        exit(3);
    }

    $irss = $DIC->resourceStorage();
    $collectionId = $irss->collection()->id($rcidValue);
    $collection = $irss->collection()->get($collectionId);

    echo "============================================\n";
    echo " Ilias2Moodle - Extraction Forum attachment\n";
    echo "============================================\n";
    echo "ILIAS root  : $iliasRoot\n";
    echo "Client      : " . CLIENT_ID . "\n";
    echo "Forum ObjId : $forumObjId\n";
    echo "Post ID     : $postId\n";
    echo "Filename    : $filename\n";
    echo "RCID        : $rcidValue\n";
    echo "--------------------------------------------\n";

    $selectedRid = null;
    $selectedInfo = null;

    foreach ($collection->getResourceIdentifications() as $rid) {
        $revision = $irss->manage()->getCurrentRevision($rid);
        if ($revision === null) {
            continue;
        }

        $info = $revision->getInformation();
        $title = (string) $info->getTitle();

        echo "[FOUND] $title\n";

        if ($title === $filename) {
            if ($selectedRid !== null) {
                throw new RuntimeException(
                    "Plusieurs ressources portent le nom exact $filename dans le post $postId."
                );
            }
            $selectedRid = $rid;
            $selectedInfo = $info;
        }
    }

    if ($selectedRid === null || $selectedInfo === null) {
        echo "RESULTAT    : ATTACHMENT_NOT_FOUND\n";
        exit(3);
    }

    $outputDir = $outputBase . '/forum_' . $forumObjId . '/post_' . $postId;
    if (
        !is_dir($outputDir) &&
        !mkdir($outputDir, 0755, true) &&
        !is_dir($outputDir)
    ) {
        throw new RuntimeException("Impossible de créer $outputDir");
    }

    $safeName = preg_replace(
        '/[\x00-\x1F\x7F\/\\\\]+/u',
        '_',
        $filename
    );

    if (
        $safeName === null ||
        trim($safeName) === '' ||
        $safeName === '.' ||
        $safeName === '..'
    ) {
        throw new RuntimeException('Nom de sortie invalide.');
    }

    $target = $outputDir . '/' . $safeName;
    $stream = $irss->consume()->stream($selectedRid)->getStream();
    $resource = $stream->detach();

    if (!is_resource($resource)) {
        throw new RuntimeException("Impossible d'obtenir le flux IRSS.");
    }

    $out = fopen($target, 'wb');
    if ($out === false) {
        throw new RuntimeException("Impossible d'ouvrir $target en écriture.");
    }

    $copyResult = stream_copy_to_stream($resource, $out);
    fclose($out);

    clearstatcache(true, $target);

    if (!is_file($target)) {
        throw new RuntimeException("Aucun fichier produit : $target");
    }

    $size = filesize($target);
    if ($size === false || $size <= 0) {
        throw new RuntimeException("Fichier extrait vide ou taille indisponible.");
    }

    $expectedSize = (int) $selectedInfo->getSize();
    if ($expectedSize > 0 && $size !== $expectedSize) {
        throw new RuntimeException(
            "Taille extraite incorrecte : $size obtenus, $expectedSize attendus."
        );
    }

    $sha256 = hash_file('sha256', $target);
    if ($sha256 === false) {
        throw new RuntimeException('Impossible de calculer le SHA256.');
    }

    $manifest = [
        'forum_obj_id' => $forumObjId,
        'post_id' => $postId,
        'filename' => $filename,
        'output_name' => $safeName,
        'output_path' => $target,
        'rcid' => $rcidValue,
        'resource_id' => $selectedRid->serialize(),
        'expected_size' => $expectedSize,
        'size' => $size,
        'sha256' => $sha256,
        'stream_copy_result' => $copyResult,
        'status' => (
            $expectedSize > 0 && $size === $expectedSize
                ? 'OK'
                : 'OK_SIZE_UNAVAILABLE'
        ),
        'source' => 'ilias_forum_irss_api',
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
        "\n[ERREUR] " . get_class($e) . ': ' . $e->getMessage() . "\n"
    );
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

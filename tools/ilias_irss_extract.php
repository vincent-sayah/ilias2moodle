<?php

declare(strict_types=1);

/**
 * Ilias2Moodle - Extracteur IRSS read-only
 *
 * Priorité de configuration :
 *
 *   option CLI > variable d'environnement > valeur par défaut
 *
 * Usage recommandé :
 *
 * php tools/ilias_irss_extract.php \
 *   --collection=<UUID> \
 *   --ilias-root=/var/www/ilias \
 *   --client=ilias10 \
 *   --output=/tmp/ilias2moodle-irss
 *
 * Variables d'environnement :
 *
 *   ILIAS_ROOT
 *   ILIAS_CLIENT_ID
 *   ILIAS_IRSS_OUTPUT
 *
 * Compatibilité :
 *
 *   php tools/ilias_irss_extract.php <UUID>
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERREUR : ce script doit être exécuté en CLI.\n");
    exit(2);
}

/**
 * Retourne une variable d'environnement non vide
 * ou la valeur par défaut.
 */
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

  php {$script} --collection=<UUID> [options]

Options :

  --collection=<UUID>       UUID de la collection IRSS
  --ilias-root=<path>       Racine ILIAS
  --client=<client_id>      Client ILIAS
  --output=<path>           Répertoire de récupération
  --help                    Affiche cette aide

Variables d'environnement :

  ILIAS_ROOT
  ILIAS_CLIENT_ID
  ILIAS_IRSS_OUTPUT

Exemple :

  php {$script} \
    --collection=91b53716-8ec4-45ad-ada7-0f714c76901f \
    --ilias-root=/var/www/ilias \
    --client=ilias10 \
    --output=/tmp/ilias2moodle-irss

TXT;

    fwrite(STDERR, $message);
}

$restIndex = null;

$options = getopt(
    '',
    [
        'collection:',
        'ilias-root:',
        'client:',
        'output:',
        'help',
    ],
    $restIndex
);

if ($options === false) {
    usage($argv[0]);
    exit(2);
}

if (array_key_exists('help', $options)) {
    usage($argv[0]);
    exit(0);
}

/*
 * Ancienne syntaxe positionnelle conservée pour compatibilité :
 *
 * php script.php UUID
 */
$positional = [];

if (is_int($restIndex)) {
    $positional = array_slice($argv, $restIndex);
}

$collectionUuid = trim(
    (string) (
        $options['collection']
        ?? ($positional[0] ?? '')
    )
);

if (
    $collectionUuid === '' ||
    !preg_match(
        '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
        $collectionUuid
    )
) {
    fwrite(STDERR, "ERREUR : UUID de collection invalide.\n\n");
    usage($argv[0]);
    exit(2);
}

$iliasRoot = trim(
    (string) (
        $options['ilias-root']
        ?? envOrDefault(
            'ILIAS_ROOT',
            '/var/www/ilias'
        )
    )
);

$clientId = trim(
    (string) (
        $options['client']
        ?? envOrDefault(
            'ILIAS_CLIENT_ID',
            'ilias10'
        )
    )
);

$outputBase = trim(
    (string) (
        $options['output']
        ?? envOrDefault(
            'ILIAS_IRSS_OUTPUT',
            '/tmp/ilias2moodle-irss'
        )
    )
);

if ($iliasRoot === '') {
    throw new RuntimeException(
        'La racine ILIAS ne peut pas être vide.'
    );
}

if (
    $clientId === '' ||
    !preg_match('/^[A-Za-z0-9_.-]+$/', $clientId)
) {
    throw new RuntimeException(
        'Identifiant client ILIAS invalide.'
    );
}

if ($outputBase === '') {
    throw new RuntimeException(
        'Le répertoire de sortie ne peut pas être vide.'
    );
}

$iliasRoot = rtrim($iliasRoot, '/');
$outputBase = rtrim($outputBase, '/');

$outputDir = $outputBase . '/' . $collectionUuid;

try {
    /*
     * Important :
     * ILIAS charge encore plusieurs fichiers relativement
     * à sa racine.
     */
    if (!chdir($iliasRoot)) {
        throw new RuntimeException(
            "Impossible d'accéder à $iliasRoot"
        );
    }

    require_once $iliasRoot . '/vendor/composer/vendor/autoload.php';

    /*
     * Force explicitement le client courant.
     * Aucune modification de configuration ILIAS.
     */
    if (!defined('CLIENT_ID')) {
        define('CLIENT_ID', $clientId);
    }

    /*
     * Quelques valeurs minimales pour les composants
     * qui inspectent $_SERVER pendant l'initialisation.
     */
    $_SERVER['REQUEST_METHOD'] ??= 'GET';
    $_SERVER['HTTP_HOST']      ??= 'localhost';
    $_SERVER['SERVER_NAME']    ??= 'localhost';
    $_SERVER['SERVER_PORT']    ??= '80';
    $_SERVER['REQUEST_URI']    ??= '/';
    $scriptName = '/' . basename($argv[0]);
    $_SERVER['SCRIPT_NAME']    ??= $scriptName;
    $_SERVER['PHP_SELF']       ??= $scriptName;
    $_SERVER['HTTPS']          ??= 'off';

    /*
     * Contexte CLI sans session persistante.
     */
    ilContext::init(ilContext::CONTEXT_CRON);

    /*
     * Même initialisation que le site ILIAS actif.
     */
    ilInitialisation::initILIAS();

    global $DIC;

    if (!isset($DIC)) {
        throw new RuntimeException(
            'DIC ILIAS non initialisé.'
        );
    }

    echo "============================================\n";
    echo " Ilias2Moodle - Extraction IRSS\n";
    echo "============================================\n";
    echo "ILIAS root : $iliasRoot\n";
    echo "Client     : " . CLIENT_ID . "\n";
    echo "Collection : $collectionUuid\n";
    echo "Destination: $outputDir\n";
    echo "--------------------------------------------\n";

    $irss = $DIC->resourceStorage();

    /*
     * Reconstruction de l'identifiant de collection
     * à partir de l'UUID présent dans export.xml.
     */
    $collectionId = $irss
        ->collection()
        ->id($collectionUuid);

    $collection = $irss
        ->collection()
        ->get($collectionId);

    if (!is_dir($outputDir)) {
        if (!mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new RuntimeException(
                "Impossible de créer $outputDir"
            );
        }
    }

    $manifest = [
        'collection_uuid' => $collectionUuid,
        'client_id'       => CLIENT_ID,
        'files'           => [],
    ];

    $count = 0;

    foreach ($collection->getResourceIdentifications() as $rid) {
        $count++;

        $ridString = $rid->serialize();

        $revision = $irss
            ->manage()
            ->getCurrentRevision($rid);

        if ($revision === null) {
            echo "[WARN] $ridString : aucune révision courante\n";

            $manifest['files'][] = [
                'resource_id' => $ridString,
                'status'      => 'NO_CURRENT_REVISION',
            ];

            continue;
        }

        $info = $revision->getInformation();

        $originalName = (string) $info->getTitle();
        $mimeType     = (string) $info->getMimeType();
        $expectedSize = (int) $info->getSize();

        /*
         * Neutralise uniquement les caractères dangereux
         * pour un nom de fichier local.
         */
        $safeName = preg_replace(
            '/[\x00-\x1F\x7F\/\\\\]+/u',
            '_',
            $originalName
        );

        if (
            $safeName === null ||
            trim($safeName) === '' ||
            $safeName === '.' ||
            $safeName === '..'
        ) {
            $safeName = 'resource_' . $count;
        }

        /*
         * Evite l'écrasement si deux ressources ont le même nom.
         */
        $target = $outputDir . '/' . $safeName;

        if (file_exists($target)) {
            $extension = pathinfo($safeName, PATHINFO_EXTENSION);
            $baseName  = pathinfo($safeName, PATHINFO_FILENAME);

            $suffix = 2;

            do {
                $candidate =
                    $baseName .
                    '_' .
                    $suffix .
                    ($extension !== '' ? '.' . $extension : '');

                $target = $outputDir . '/' . $candidate;
                $suffix++;
            } while (file_exists($target));

            $safeName = basename($target);
        }

        /*
         * Lecture via l'API officielle IRSS.
         * Aucun accès direct aux tables ni au stockage physique.
         */
        $stream = $irss
            ->consume()
            ->stream($rid)
            ->getStream();

        $contents = $stream->getContents();

        $written = file_put_contents(
            $target,
            $contents
        );

        if ($written === false) {
            throw new RuntimeException(
                "Impossible d'écrire $target"
            );
        }

        $sha256 = hash_file(
            'sha256',
            $target
        );

        $status =
            ($expectedSize === 0 || $written === $expectedSize)
            ? 'OK'
            : 'SIZE_MISMATCH';

        printf(
            "[%s] %-35s %10d octets  %s\n",
            $status,
            $safeName,
            $written,
            $mimeType
        );

        $manifest['files'][] = [
            'resource_id'   => $ridString,
            'original_name' => $originalName,
            'output_name'   => $safeName,
            'mime_type'     => $mimeType,
            'expected_size' => $expectedSize,
            'written_size'  => $written,
            'sha256'        => $sha256,
            'status'        => $status,
        ];

        /*
         * Libère immédiatement le contenu en mémoire.
         */
        unset($contents);
    }

    $manifest['resource_count'] = $count;

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

    echo "--------------------------------------------\n";
    echo "Ressources : $count\n";
    echo "Manifest   : $manifestFile\n";

    if ($count === 0) {
        echo "RESULTAT    : COLLECTION_VIDE\n";
        exit(3);
    }

    echo "RESULTAT    : EXTRACTION_OK\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(
        STDERR,
        "\n[ERREUR] " .
        get_class($e) .
        ": " .
        $e->getMessage() .
        "\n"
    );

    fwrite(
        STDERR,
        $e->getTraceAsString() .
        "\n"
    );

    exit(1);
}

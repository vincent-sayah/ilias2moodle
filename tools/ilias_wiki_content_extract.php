<?php

declare(strict_types=1);

/**
 * Ilias2Moodle - Read-only extraction of current ILIAS Wiki page content.
 *
 * This extractor reads current Wiki page metadata through the Wiki repository
 * and current COPage XML through ilWikiPage::getXMLContent(). It does not call
 * update/create/delete and does not access fsv2 directly.
 *
 * Example:
 *
 * php tools/ilias_wiki_content_extract.php \
 *   --wiki-ref=279 \
 *   --course-ref=128 \
 *   --ilias-root=/var/www/ilias \
 *   --client=ilias10 \
 *   --output=/tmp/ilias2moodle-wiki
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: this script must be executed from CLI.\n");
    exit(2);
}

function wikiRecoveryUsage(string $script): void
{
    fwrite(
        STDERR,
        <<<TXT
Usage:

  php {$script} --wiki-ref=<REF_ID> --course-ref=<REF_ID> [options]

Options:

  --wiki-ref=<REF_ID>       ILIAS Wiki repository ref_id
  --course-ref=<REF_ID>     Parent ILIAS course repository ref_id
  --ilias-root=<path>       ILIAS root directory (default: /var/www/ilias)
  --client=<client_id>      ILIAS client id (default: ilias10)
  --output=<dir>            Recovery output directory
  --help                    Show this help

TXT
    );
}

function wikiRecoveryEnv(string $name, string $default): string
{
    $value = getenv($name);
    return ($value === false || trim($value) === '')
        ? $default
        : trim($value);
}

/**
 * Collect numeric object ids conservatively from raw COPage XML.
 *
 * These ids are advisory inventory only. They are not used to fetch files
 * automatically by this extractor.
 *
 * @return array{media_object_ids: list<int>, file_object_ids: list<int>}
 */
function wikiRecoveryReferencedObjectIds(string $xml): array
{
    $mobIds = [];
    $fileIds = [];

    if (preg_match_all('/il_[0-9]+_mob_([0-9]+)/i', $xml, $matches)) {
        foreach ($matches[1] as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $mobIds[$id] = true;
            }
        }
    }

    if (preg_match_all('/il_[0-9]+_file_([0-9]+)/i', $xml, $matches)) {
        foreach ($matches[1] as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $fileIds[$id] = true;
            }
        }
    }

    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        if ($xml !== '' && $dom->loadXML($xml, LIBXML_NONET)) {
            $xpath = new DOMXPath($dom);

            foreach ($xpath->query('//*[local-name()="MediaObject"]/@Id') ?: [] as $attr) {
                $raw = (string) $attr->nodeValue;
                if (preg_match('/(?:mob[_:-]?)?([0-9]+)$/i', $raw, $m)) {
                    $id = (int) $m[1];
                    if ($id > 0) {
                        $mobIds[$id] = true;
                    }
                }
            }

            foreach ($xpath->query('//*[local-name()="FileItem"]/@Id') ?: [] as $attr) {
                $raw = (string) $attr->nodeValue;
                if (preg_match('/(?:file[_:-]?)?([0-9]+)$/i', $raw, $m)) {
                    $id = (int) $m[1];
                    if ($id > 0) {
                        $fileIds[$id] = true;
                    }
                }
            }
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    $mobIds = array_map('intval', array_keys($mobIds));
    $fileIds = array_map('intval', array_keys($fileIds));
    sort($mobIds, SORT_NUMERIC);
    sort($fileIds, SORT_NUMERIC);

    return [
        'media_object_ids' => array_values($mobIds),
        'file_object_ids' => array_values($fileIds),
    ];
}

$options = getopt(
    '',
    [
        'wiki-ref:',
        'course-ref:',
        'ilias-root:',
        'client:',
        'output:',
        'help',
    ]
);

if ($options === false) {
    wikiRecoveryUsage($argv[0]);
    exit(2);
}

if (array_key_exists('help', $options)) {
    wikiRecoveryUsage($argv[0]);
    exit(0);
}

$wikiRef = (int) ($options['wiki-ref'] ?? 0);
$courseRef = (int) ($options['course-ref'] ?? 0);

$iliasRoot = rtrim(
    (string) (
        $options['ilias-root']
        ?? wikiRecoveryEnv('ILIAS_ROOT', '/var/www/ilias')
    ),
    '/'
);

$clientId = trim(
    (string) (
        $options['client']
        ?? wikiRecoveryEnv('ILIAS_CLIENT_ID', 'ilias10')
    )
);

$outputBase = rtrim(
    (string) (
        $options['output']
        ?? wikiRecoveryEnv('ILIAS_WIKI_RECOVERY_OUTPUT', '/tmp/ilias2moodle-wiki')
    ),
    '/'
);

if ($wikiRef <= 0 || $courseRef <= 0) {
    fwrite(
        STDERR,
        "ERROR: --wiki-ref and --course-ref must be positive integers.\n"
    );
    exit(2);
}

if ($iliasRoot === '' || !is_dir($iliasRoot)) {
    fwrite(STDERR, "ERROR: invalid ILIAS root: {$iliasRoot}\n");
    exit(2);
}

if (
    $clientId === '' ||
    !preg_match('/^[A-Za-z0-9_.-]+$/', $clientId)
) {
    fwrite(STDERR, "ERROR: invalid ILIAS client id.\n");
    exit(2);
}

if ($outputBase === '') {
    fwrite(STDERR, "ERROR: output directory cannot be empty.\n");
    exit(2);
}

try {
    if (!chdir($iliasRoot)) {
        throw new RuntimeException(
            "Cannot enter ILIAS root: {$iliasRoot}"
        );
    }

    $autoload = $iliasRoot . '/vendor/composer/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException(
            "ILIAS autoloader not found: {$autoload}"
        );
    }

    require_once $autoload;

    if (!defined('CLIENT_ID')) {
        define('CLIENT_ID', $clientId);
    }

    $_SERVER['REQUEST_METHOD'] ??= 'GET';
    $_SERVER['HTTP_HOST'] ??= 'localhost';
    $_SERVER['SERVER_NAME'] ??= 'localhost';
    $_SERVER['SERVER_PORT'] ??= '80';
    $_SERVER['REQUEST_URI'] ??= '/';
    $_SERVER['SCRIPT_NAME'] ??= '/' . basename($argv[0]);
    $_SERVER['PHP_SELF'] ??= $_SERVER['SCRIPT_NAME'];
    $_SERVER['HTTPS'] ??= 'off';

    ilContext::init(ilContext::CONTEXT_CRON);
    ilInitialisation::initILIAS();

    global $DIC;

    $wikiObjId = (int) ilObject::_lookupObjId($wikiRef);
    $wikiType = $wikiObjId > 0
        ? (string) ilObject::_lookupType($wikiObjId)
        : '';
    $wikiTitle = $wikiObjId > 0
        ? (string) ilObject::_lookupTitle($wikiObjId)
        : '';

    $courseObjId = (int) ilObject::_lookupObjId($courseRef);
    $courseType = $courseObjId > 0
        ? (string) ilObject::_lookupType($courseObjId)
        : '';
    $courseTitle = $courseObjId > 0
        ? (string) ilObject::_lookupTitle($courseObjId)
        : '';

    if ($wikiObjId <= 0 || $wikiType !== 'wiki') {
        throw new RuntimeException(
            "ref_id {$wikiRef} is not an ILIAS Wiki."
        );
    }

    if ($courseObjId <= 0 || $courseType !== 'crs') {
        throw new RuntimeException(
            "ref_id {$courseRef} is not an ILIAS course."
        );
    }

    $wiki = new ilObjWiki($wikiRef);
    $startPageTitle = (string) $wiki->getStartPage();

    $dataService = new \ILIAS\Wiki\InternalDataService();
    $repoService = new \ILIAS\Wiki\InternalRepoService(
        $dataService,
        $DIC->database()
    );
    $pageRepo = $repoService->page();

    $pages = [];

    foreach ($pageRepo->getAllPagesInfo($wikiObjId) as $info) {
        $pageId = (int) $info->getId();
        if ($pageId <= 0) {
            continue;
        }

        $pages[(string) $pageId] = [
            'source_page_id' => (string) $pageId,
            'title' => (string) $info->getTitle(),
            'language' => '',
            'created' => null,
            'create_user_id' => null,
            'last_change' => (string) $info->getLastChange(),
            'last_change_user_id' => $info->getLastChangedUser() > 0
                ? (string) $info->getLastChangedUser()
                : null,
        ];
    }

    foreach ($pageRepo->getNewPages($wikiObjId) as $info) {
        $pageId = (int) $info->getId();
        if ($pageId <= 0) {
            continue;
        }

        $key = (string) $pageId;
        if (!isset($pages[$key])) {
            $pages[$key] = [
                'source_page_id' => $key,
                'title' => (string) $info->getTitle(),
                'language' => '',
                'created' => null,
                'create_user_id' => null,
                'last_change' => null,
                'last_change_user_id' => null,
            ];
        }

        $pages[$key]['language'] = (string) $info->getLanguage();
        $pages[$key]['created'] = (string) $info->getCreated();
        $pages[$key]['create_user_id'] = $info->getCreateUser() > 0
            ? (string) $info->getCreateUser()
            : null;
    }

    if ($pages === []) {
        throw new RuntimeException(
            "Wiki {$wikiObjId} contains no current page."
        );
    }

    uksort(
        $pages,
        static fn(string $a, string $b): int =>
            ((int) $a) <=> ((int) $b)
    );

    $wikiOutput = $outputBase . '/wiki_' . $wikiObjId;
    $pagesOutput = $wikiOutput . '/pages';

    if (
        !is_dir($pagesOutput) &&
        !mkdir($pagesOutput, 0755, true) &&
        !is_dir($pagesOutput)
    ) {
        throw new RuntimeException(
            "Cannot create recovery directory: {$pagesOutput}"
        );
    }

    $allMobIds = [];
    $allFileIds = [];
    $pageDocuments = [];

    foreach ($pages as $pageId => $metadata) {
        $language = trim((string) ($metadata['language'] ?? ''));
        if ($language === '') {
            $language = '-';
        }

        $page = new ilWikiPage((int) $pageId, 0, $language);
        $xml = (string) $page->getXMLContent(true);

        if (trim($xml) === '') {
            throw new RuntimeException(
                "Wiki page {$pageId} returned empty current COPage XML."
            );
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $validXml = false;
        try {
            $validXml = $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$validXml) {
            throw new RuntimeException(
                "Wiki page {$pageId} returned invalid XML."
            );
        }

        $filename = 'page_' . $pageId . '.xml';
        $target = $pagesOutput . '/' . $filename;

        if (file_put_contents($target, $xml) === false) {
            throw new RuntimeException(
                "Cannot write Wiki page recovery XML: {$target}"
            );
        }

        $size = filesize($target);
        $sha256 = hash_file('sha256', $target);
        if ($size === false || $size <= 0 || $sha256 === false) {
            throw new RuntimeException(
                "Cannot validate Wiki page recovery XML: {$target}"
            );
        }

        $refs = wikiRecoveryReferencedObjectIds($xml);
        foreach ($refs['media_object_ids'] as $id) {
            $allMobIds[$id] = true;
        }
        foreach ($refs['file_object_ids'] as $id) {
            $allFileIds[$id] = true;
        }

        $pageDocuments[] = [
            'source_page_id' => (string) $pageId,
            'title' => (string) ($metadata['title'] ?? ''),
            'language' => $language,
            'created' => $metadata['created'],
            'create_user_id' => $metadata['create_user_id'],
            'last_change' => $metadata['last_change'],
            'last_change_user_id' => $metadata['last_change_user_id'],
            'xml_file' => 'pages/' . $filename,
            'size' => (int) $size,
            'sha256' => $sha256,
            'media_object_ids' => $refs['media_object_ids'],
            'file_object_ids' => $refs['file_object_ids'],
        ];
    }

    $startPageId = null;
    foreach ($pageDocuments as $page) {
        if ((string) $page['title'] === $startPageTitle) {
            $startPageId = (string) $page['source_page_id'];
            break;
        }
    }

    if ($startPageTitle === '' || $startPageId === null) {
        throw new RuntimeException(
            "Wiki start page cannot be resolved to a current page."
        );
    }

    $mobIds = array_map('intval', array_keys($allMobIds));
    $fileIds = array_map('intval', array_keys($allFileIds));
    sort($mobIds, SORT_NUMERIC);
    sort($fileIds, SORT_NUMERIC);

    $manifest = [
        'schema_version' => '1.0',
        'source' => [
            'lms' => 'ILIAS',
            'client_id' => CLIENT_ID,
            'read_only' => true,
        ],
        'course' => [
            'object_id' => (string) $courseObjId,
            'ref_id' => (string) $courseRef,
            'title' => $courseTitle,
        ],
        'wiki' => [
            'object_id' => (string) $wikiObjId,
            'ref_id' => (string) $wikiRef,
            'title' => $wikiTitle,
            'start_page' => [
                'title' => $startPageTitle,
                'source_id' => $startPageId,
            ],
        ],
        'history_policy' => [
            'migration_policy' => 'current_pages_only',
            'source_export_contains_history' => false,
            'authors_migrated' => false,
        ],
        'pages' => $pageDocuments,
        'referenced_assets' => [
            'media_object_ids' => array_values($mobIds),
            'file_object_ids' => array_values($fileIds),
        ],
        'safety' => [
            'read_only' => true,
            'wiki_page_method' => 'ilWikiPage::getXMLContent',
            'update_called' => false,
            'create_called' => false,
            'delete_called' => false,
            'direct_fsv2_access' => false,
            'writes_performed' => false,
        ],
    ];

    $manifestFile = $wikiOutput . '/manifest.json';

    $encoded = json_encode(
        $manifest,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    if (file_put_contents($manifestFile, $encoded) === false) {
        throw new RuntimeException(
            "Cannot write recovery manifest: {$manifestFile}"
        );
    }

    echo "============================================\n";
    echo " Ilias2Moodle - Wiki current-content recovery\n";
    echo "============================================\n";
    echo "Client              : " . CLIENT_ID . "\n";
    echo "Course              : "
        . $courseObjId . " / ref " . $courseRef . " / " . $courseTitle
        . "\n";
    echo "Wiki                : "
        . $wikiObjId . " / ref " . $wikiRef . " / " . $wikiTitle
        . "\n";
    echo "Start page          : "
        . $startPageId . " / " . $startPageTitle
        . "\n";
    echo "Pages               : " . count($pageDocuments) . "\n";
    echo "Referenced mobs     : "
        . (count($mobIds) ? implode(',', $mobIds) : '-')
        . "\n";
    echo "Referenced files    : "
        . (count($fileIds) ? implode(',', $fileIds) : '-')
        . "\n";
    echo "Manifest            : " . $manifestFile . "\n";
    echo "RESULT              : EXTRACTION_OK\n";

    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "ERROR: "
        . get_class($exception)
        . ': '
        . $exception->getMessage()
        . PHP_EOL
    );
    fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);

    exit(1);
}

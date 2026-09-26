<?php

declare(strict_types=1);

/**
 * Ilias2Moodle - Phase 7.6 read-only Wiki page authors inventory.
 *
 * Reads the ILIAS Wiki application repository only. It retrieves creation
 * author/date and current last-change author/date for Wiki pages. No Wiki,
 * COPage, Learning Progress or user write method is called.
 *
 * Example:
 * php tools/ilias_wiki_authors_extract.php \
 *   --wiki-ref=273 \
 *   --course-ref=128 \
 *   --ilias-root=/var/www/ilias \
 *   --client=ilias10 \
 *   --output=/tmp/phase76_wiki_801_authors.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: this script must be executed from CLI.\n");
    exit(2);
}

function phase76wiki_usage(string $script): void
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
  --output=<file>           JSON output file
  --help                    Show this help

TXT
    );
}

function phase76wiki_env(string $name, string $default): string
{
    $value = getenv($name);
    return ($value === false || trim($value) === '')
        ? $default
        : trim($value);
}

function phase76wiki_user(int $userId): array
{
    if ($userId <= 0) {
        return [
            'source_user_id' => $userId > 0 ? (string) $userId : null,
            'source_login' => null,
            'user_exists' => false,
        ];
    }

    $exists = ilObject::_exists($userId);
    $login = $exists
        ? (string) ilObjUser::_lookupLogin($userId)
        : '';

    return [
        'source_user_id' => (string) $userId,
        'source_login' => $login !== '' ? $login : null,
        'user_exists' => (bool) $exists,
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
    phase76wiki_usage($argv[0]);
    exit(2);
}

if (array_key_exists('help', $options)) {
    phase76wiki_usage($argv[0]);
    exit(0);
}

$wikiRef = (int) ($options['wiki-ref'] ?? 0);
$courseRef = (int) ($options['course-ref'] ?? 0);
$iliasRoot = rtrim(
    (string) (
        $options['ilias-root']
        ?? phase76wiki_env('ILIAS_ROOT', '/var/www/ilias')
    ),
    '/'
);
$clientId = trim(
    (string) (
        $options['client']
        ?? phase76wiki_env('ILIAS_CLIENT_ID', 'ilias10')
    )
);
$output = trim(
    (string) (
        $options['output']
        ?? '/tmp/phase76_ilias_wiki_authors.json'
    )
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

if ($clientId === ''
        || !preg_match('/^[A-Za-z0-9_.-]+$/', $clientId)) {
    fwrite(STDERR, "ERROR: invalid ILIAS client id.\n");
    exit(2);
}

if ($output === '') {
    fwrite(STDERR, "ERROR: output path cannot be empty.\n");
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

    $dataService = new \ILIAS\Wiki\InternalDataService();
    $repoService = new \ILIAS\Wiki\InternalRepoService(
        $dataService,
        $DIC->database()
    );
    $pageRepo = $repoService->page();

    $pages = [];

    foreach ($pageRepo->getAllPagesInfo($wikiObjId) as $info) {
        $pageId = (int) $info->getId();

        $pages[(string) $pageId] = [
            'source_page_id' => (string) $pageId,
            'title' => (string) $info->getTitle(),
            'language' => (string) $info->getLanguage(),
            'create_user_id' => null,
            'create_user' => null,
            'created' => null,
            'last_change_user_id' => $info->getLastChangedUser() > 0
                ? (string) $info->getLastChangedUser()
                : null,
            'last_change_user' => phase76wiki_user(
                $info->getLastChangedUser()
            ),
            'last_change' => (string) $info->getLastChange(),
        ];
    }

    foreach ($pageRepo->getNewPages($wikiObjId) as $info) {
        $pageId = (int) $info->getId();
        $key = (string) $pageId;

        if (!isset($pages[$key])) {
            $pages[$key] = [
                'source_page_id' => $key,
                'title' => (string) $info->getTitle(),
                'language' => (string) $info->getLanguage(),
                'create_user_id' => null,
                'create_user' => null,
                'created' => null,
                'last_change_user_id' => null,
                'last_change_user' => null,
                'last_change' => null,
            ];
        }

        $pages[$key]['create_user_id'] = $info->getCreateUser() > 0
            ? (string) $info->getCreateUser()
            : null;
        $pages[$key]['create_user'] = phase76wiki_user(
            $info->getCreateUser()
        );
        $pages[$key]['created'] = (string) $info->getCreated();
    }

    uksort(
        $pages,
        static fn(string $a, string $b): int =>
            ((int) $a) <=> ((int) $b)
    );

    $userIds = [];
    $creationKnown = 0;
    $lastChangeKnown = 0;
    $sameCreatorAndLastEditor = 0;

    foreach ($pages as $page) {
        $creator = (int) ($page['create_user_id'] ?? 0);
        $editor = (int) ($page['last_change_user_id'] ?? 0);

        if ($creator > 0) {
            $creationKnown++;
            $userIds[$creator] = true;
        }
        if ($editor > 0) {
            $lastChangeKnown++;
            $userIds[$editor] = true;
        }
        if ($creator > 0 && $creator === $editor) {
            $sameCreatorAndLastEditor++;
        }
    }

    $document = [
        'schema_version' => '1.0',
        'phase' => '7.6',
        'extractor' => 'wiki_current_page_authors',
        'source' => [
            'lms' => 'ILIAS',
            'client_id' => CLIENT_ID,
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
        ],
        'history_policy' => [
            'package_policy' => 'current_pages_only',
            'full_revision_history_extracted' => false,
            'current_page_creation_metadata_read' => true,
            'current_page_last_change_metadata_read' => true,
        ],
        'pages' => $pages,
        'counts' => [
            'pages' => count($pages),
            'pages_with_creator' => $creationKnown,
            'pages_with_last_change_user' => $lastChangeKnown,
            'same_creator_and_last_editor' => $sameCreatorAndLastEditor,
            'distinct_users' => count($userIds),
        ],
        'safety' => [
            'read_only' => true,
            'repository' =>
                'ILIAS\\Wiki\\Page\\PageDBRepository',
            'methods_called' => [
                'getAllPagesInfo',
                'getNewPages',
            ],
            'refresh_called' => false,
            'update_called' => false,
            'writes_performed' => false,
        ],
    ];

    $directory = dirname($output);
    if (!is_dir($directory)
            && !mkdir($directory, 0755, true)
            && !is_dir($directory)) {
        throw new RuntimeException(
            "Cannot create output directory: {$directory}"
        );
    }

    $encoded = json_encode(
        $document,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    if (file_put_contents($output, $encoded) === false) {
        throw new RuntimeException(
            "Cannot write output file: {$output}"
        );
    }

    echo "============================================\n";
    echo " Ilias2Moodle - Phase 7.6 Wiki author inventory\n";
    echo "============================================\n";
    echo "Client              : " . CLIENT_ID . "\n";
    echo "Course              : "
        . $courseObjId . " / ref " . $courseRef . " / " . $courseTitle
        . "\n";
    echo "Wiki                : "
        . $wikiObjId . " / ref " . $wikiRef . " / " . $wikiTitle
        . "\n";
    echo "Pages               : " . count($pages) . "\n";
    echo "Creator known       : " . $creationKnown . "\n";
    echo "Last editor known   : " . $lastChangeKnown . "\n";
    echo "Distinct users      : " . count($userIds) . "\n";
    echo "Output              : " . $output . "\n";
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

<?php

declare(strict_types=1);

/**
 * Ilias2Moodle - Phase 7.1 read-only ILIAS course identity extractor.
 *
 * Uses ILIAS application services only. It does not write to ILIAS and does
 * not query ILIAS database tables directly.
 *
 * Example:
 * php tools/ilias_course_identity_extract.php \
 *   --course-ref=128 \
 *   --ilias-root=/var/www/ilias \
 *   --client=ilias10 \
 *   --output=/tmp/phase7_ilias_identities_course_504.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: this script must be executed from CLI.\n");
    exit(2);
}

function phase71_usage(string $script): void
{
    $message = <<<TXT
Usage:

  php {$script} --course-ref=<REF_ID> [options]

Options:

  --course-ref=<REF_ID>     ILIAS course repository ref_id
  --ilias-root=<path>       ILIAS root directory (default: /var/www/ilias)
  --client=<client_id>      ILIAS client id (default: ilias10)
  --output=<file>           JSON output file
  --help                    Show this help

Environment variables:

  ILIAS_ROOT
  ILIAS_CLIENT_ID
  ILIAS_IDENTITY_OUTPUT

TXT;

    fwrite(STDERR, $message);
}

function phase71_env(string $name, string $default): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $default;
    }
    return trim($value);
}

function phase71_user_value(object $user, string $method, mixed $default = ''): mixed
{
    if (!method_exists($user, $method)) {
        return $default;
    }

    try {
        return $user->{$method}();
    } catch (Throwable) {
        return $default;
    }
}

$options = getopt(
    '',
    [
        'course-ref:',
        'ilias-root:',
        'client:',
        'output:',
        'help',
    ]
);

if ($options === false) {
    phase71_usage($argv[0]);
    exit(2);
}

if (array_key_exists('help', $options)) {
    phase71_usage($argv[0]);
    exit(0);
}

$courseRef = (int) ($options['course-ref'] ?? 0);
$iliasRoot = rtrim(
    (string) ($options['ilias-root'] ?? phase71_env('ILIAS_ROOT', '/var/www/ilias')),
    '/'
);
$clientId = trim(
    (string) ($options['client'] ?? phase71_env('ILIAS_CLIENT_ID', 'ilias10'))
);
$output = trim(
    (string) (
        $options['output']
        ?? phase71_env(
            'ILIAS_IDENTITY_OUTPUT',
            '/tmp/phase7_ilias_identities.json'
        )
    )
);

if ($courseRef <= 0) {
    fwrite(STDERR, "ERROR: --course-ref must be a positive integer.\n\n");
    phase71_usage($argv[0]);
    exit(2);
}

if ($iliasRoot === '' || !is_dir($iliasRoot)) {
    fwrite(STDERR, "ERROR: invalid ILIAS root: {$iliasRoot}\n");
    exit(2);
}

if ($clientId === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $clientId)) {
    fwrite(STDERR, "ERROR: invalid ILIAS client id.\n");
    exit(2);
}

if ($output === '') {
    fwrite(STDERR, "ERROR: output path cannot be empty.\n");
    exit(2);
}

try {
    if (!chdir($iliasRoot)) {
        throw new RuntimeException("Cannot enter ILIAS root: {$iliasRoot}");
    }

    $autoload = $iliasRoot . '/vendor/composer/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException("ILIAS autoloader not found: {$autoload}");
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

    $course = ilObjectFactory::getInstanceByRefId($courseRef);

    if (!$course instanceof ilObject || $course->getType() !== 'crs') {
        throw new RuntimeException("ref_id {$courseRef} is not an ILIAS course.");
    }

    $participants = ilParticipants::getInstance($courseRef);

    $roleIds = [
        'admin' => array_values(
            array_unique(array_map('intval', $participants->getAdmins()))
        ),
        'tutor' => array_values(
            array_unique(array_map('intval', $participants->getTutors()))
        ),
        'member' => array_values(
            array_unique(array_map('intval', $participants->getMembers()))
        ),
        'subscriber' => [],
    ];

    if (method_exists($participants, 'getSubscribers')) {
        $roleIds['subscriber'] = array_values(
            array_unique(array_map('intval', $participants->getSubscribers()))
        );
    }

    foreach ($roleIds as &$ids) {
        sort($ids);
    }
    unset($ids);

    $allIds = [];
    foreach ($roleIds as $ids) {
        foreach ($ids as $userId) {
            $allIds[$userId] = true;
        }
    }
    $allIds = array_map('intval', array_keys($allIds));
    sort($allIds);

    $users = [];

    foreach ($allIds as $userId) {
        $user = ilObjectFactory::getInstanceByObjId($userId, false);
        $exists = $user instanceof ilObjUser;

        $users[(string) $userId] = [
            'source_user_id' => (string) $userId,
            'exists' => $exists,
            'login' => $exists
                ? (string) phase71_user_value($user, 'getLogin')
                : '',
            'email' => $exists
                ? (string) phase71_user_value($user, 'getEmail')
                : '',
            'firstname' => $exists
                ? (string) phase71_user_value($user, 'getFirstname')
                : '',
            'lastname' => $exists
                ? (string) phase71_user_value($user, 'getLastname')
                : '',
            'matriculation' => $exists
                ? (string) phase71_user_value($user, 'getMatriculation')
                : '',
            'external_account' => $exists
                ? (string) phase71_user_value($user, 'getExternalAccount')
                : '',
            'active' => $exists
                ? (bool) phase71_user_value($user, 'getActive', false)
                : false,
        ];
    }

    $roles = [];
    foreach ($roleIds as $role => $ids) {
        $roles[$role] = array_map('strval', $ids);
    }

    $document = [
        'schema_version' => '1.0',
        'phase' => '7.1',
        'source' => [
            'lms' => 'ILIAS',
            'client_id' => CLIENT_ID,
        ],
        'course' => [
            'object_id' => (string) $course->getId(),
            'ref_id' => (string) $courseRef,
            'title' => (string) $course->getTitle(),
            'roles' => $roles,
        ],
        'users' => $users,
        'counts' => [
            'users' => count($allIds),
            'admins' => count($roleIds['admin']),
            'tutors' => count($roleIds['tutor']),
            'members' => count($roleIds['member']),
            'subscribers' => count($roleIds['subscriber']),
        ],
        'read_only' => true,
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
        throw new RuntimeException("Cannot write output file: {$output}");
    }

    echo "============================================\n";
    echo " Ilias2Moodle - Phase 7.1 identity extraction\n";
    echo "============================================\n";
    echo "Client      : " . CLIENT_ID . "\n";
    echo "Course      : " . $course->getId()
        . " / ref " . $courseRef
        . " / " . $course->getTitle() . "\n";
    echo "Users       : " . count($allIds) . "\n";
    echo "Admins      : " . count($roleIds['admin']) . "\n";
    echo "Tutors      : " . count($roleIds['tutor']) . "\n";
    echo "Members     : " . count($roleIds['member']) . "\n";
    echo "Subscribers : " . count($roleIds['subscriber']) . "\n";
    echo "Output      : " . $output . "\n";
    echo "RESULT      : EXTRACTION_OK\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "ERROR: " . get_class($exception) . ': '
        . $exception->getMessage() . PHP_EOL
    );
    exit(1);
}

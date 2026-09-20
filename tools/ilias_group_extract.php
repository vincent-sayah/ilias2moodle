<?php

declare(strict_types=1);

/**
 * Ilias2Moodle - Phase 7.2 read-only ILIAS group extractor.
 *
 * Uses ILIAS application services only. It does not write to ILIAS and does
 * not query ILIAS database tables directly.
 *
 * Example:
 * php tools/ilias_group_extract.php \
 *   --group-ref=254 \
 *   --course-ref=128 \
 *   --ilias-root=/var/www/ilias \
 *   --client=ilias10 \
 *   --output=/tmp/phase7_ilias_group_743.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: this script must be executed from CLI.\n");
    exit(2);
}

function phase72_usage(string $script): void
{
    $message = <<<TXT
Usage:

  php {$script} --group-ref=<REF_ID> --course-ref=<REF_ID> [options]

Options:

  --group-ref=<REF_ID>      ILIAS group repository ref_id
  --course-ref=<REF_ID>     Parent ILIAS course repository ref_id
  --ilias-root=<path>       ILIAS root directory (default: /var/www/ilias)
  --client=<client_id>      ILIAS client id (default: ilias10)
  --output=<file>           JSON output file
  --help                    Show this help

Environment variables:

  ILIAS_ROOT
  ILIAS_CLIENT_ID
  ILIAS_GROUP_OUTPUT

TXT;

    fwrite(STDERR, $message);
}

function phase72_env(string $name, string $default): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $default;
    }
    return trim($value);
}

function phase72_user_value(object $user, string $method, mixed $default = ''): mixed
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
        'group-ref:',
        'course-ref:',
        'ilias-root:',
        'client:',
        'output:',
        'help',
    ]
);

if ($options === false) {
    phase72_usage($argv[0]);
    exit(2);
}

if (array_key_exists('help', $options)) {
    phase72_usage($argv[0]);
    exit(0);
}

$groupRef = (int) ($options['group-ref'] ?? 0);
$courseRef = (int) ($options['course-ref'] ?? 0);
$iliasRoot = rtrim(
    (string) ($options['ilias-root'] ?? phase72_env('ILIAS_ROOT', '/var/www/ilias')),
    '/'
);
$clientId = trim(
    (string) ($options['client'] ?? phase72_env('ILIAS_CLIENT_ID', 'ilias10'))
);
$output = trim(
    (string) (
        $options['output']
        ?? phase72_env('ILIAS_GROUP_OUTPUT', '/tmp/phase7_ilias_group.json')
    )
);

if ($groupRef <= 0 || $courseRef <= 0) {
    fwrite(STDERR, "ERROR: --group-ref and --course-ref must be positive integers.\n\n");
    phase72_usage($argv[0]);
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

    $group = ilObjectFactory::getInstanceByRefId($groupRef);
    $course = ilObjectFactory::getInstanceByRefId($courseRef);

    if (!$group instanceof ilObject || $group->getType() !== 'grp') {
        throw new RuntimeException("ref_id {$groupRef} is not an ILIAS group.");
    }
    if (!$course instanceof ilObject || $course->getType() !== 'crs') {
        throw new RuntimeException("ref_id {$courseRef} is not an ILIAS course.");
    }

    $groupParticipants = ilParticipants::getInstance($groupRef);
    $courseParticipants = ilParticipants::getInstance($courseRef);

    $memberIds = array_values(array_unique(array_map('intval', $groupParticipants->getMembers())));
    $adminIds = array_values(array_unique(array_map('intval', $groupParticipants->getAdmins())));
    sort($memberIds);
    sort($adminIds);

    $participantIds = array_values(array_unique(array_merge($memberIds, $adminIds)));
    sort($participantIds);

    $users = [];
    $memberships = [];
    $parentCourseParticipantCount = 0;
    $groupOnlyCount = 0;

    foreach ($participantIds as $userId) {
        $user = ilObjectFactory::getInstanceByObjId($userId, false);
        $exists = $user instanceof ilObjUser;

        $groupRoles = [];
        if (in_array($userId, $adminIds, true)) {
            $groupRoles[] = 'admin';
        }
        if (in_array($userId, $memberIds, true)) {
            $groupRoles[] = 'member';
        }

        $courseRoles = [];
        if ($courseParticipants->isAdmin($userId)) {
            $courseRoles[] = 'admin';
        }
        if ($courseParticipants->isTutor($userId)) {
            $courseRoles[] = 'tutor';
        }
        if ($courseParticipants->isMember($userId)) {
            $courseRoles[] = 'member';
        }

        $inParentCourse = $courseParticipants->isAssigned($userId);
        if ($inParentCourse) {
            $parentCourseParticipantCount++;
        } else {
            $groupOnlyCount++;
        }

        $users[(string) $userId] = [
            'source_user_id' => (string) $userId,
            'exists' => $exists,
            'login' => $exists ? (string) phase72_user_value($user, 'getLogin') : '',
            'email' => $exists ? (string) phase72_user_value($user, 'getEmail') : '',
            'firstname' => $exists ? (string) phase72_user_value($user, 'getFirstname') : '',
            'lastname' => $exists ? (string) phase72_user_value($user, 'getLastname') : '',
            'matriculation' => $exists ? (string) phase72_user_value($user, 'getMatriculation') : '',
            'external_account' => $exists ? (string) phase72_user_value($user, 'getExternalAccount') : '',
            'active' => $exists ? (bool) phase72_user_value($user, 'getActive', false) : false,
        ];

        $memberships[] = [
            'source_user_id' => (string) $userId,
            'source_group_roles' => $groupRoles,
            'source_parent_course_roles' => $courseRoles,
            'source_parent_course_participant' => $inParentCourse,
        ];
    }

    $owner = null;
    if (method_exists($group, 'getOwner')) {
        $ownerValue = (int) $group->getOwner();
        if ($ownerValue > 0) {
            $owner = (string) $ownerValue;
        }
    }

    $document = [
        'schema_version' => '1.0',
        'phase' => '7.2',
        'source' => [
            'lms' => 'ILIAS',
            'client_id' => CLIENT_ID,
        ],
        'course' => [
            'object_id' => (string) $course->getId(),
            'ref_id' => (string) $courseRef,
            'title' => (string) $course->getTitle(),
        ],
        'group' => [
            'object_id' => (string) $group->getId(),
            'ref_id' => (string) $groupRef,
            'type' => 'grp',
            'title' => (string) $group->getTitle(),
            'description' => (string) $group->getDescription(),
            'owner_source_user_id' => $owner,
            'memberships' => $memberships,
        ],
        'users' => $users,
        'counts' => [
            'group_participants' => count($participantIds),
            'group_admins' => count($adminIds),
            'group_members' => count($memberIds),
            'parent_course_participants' => $parentCourseParticipantCount,
            'group_only_participants' => $groupOnlyCount,
        ],
        'read_only' => true,
    ];

    $directory = dirname($output);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException("Cannot create output directory: {$directory}");
    }

    $encoded = json_encode(
        $document,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    if (file_put_contents($output, $encoded) === false) {
        throw new RuntimeException("Cannot write output file: {$output}");
    }

    echo "============================================\n";
    echo " Ilias2Moodle - Phase 7.2 group extraction\n";
    echo "============================================\n";
    echo "Client                 : " . CLIENT_ID . "\n";
    echo "Course                 : " . $course->getId() . " / ref " . $courseRef . " / " . $course->getTitle() . "\n";
    echo "Group                  : " . $group->getId() . " / ref " . $groupRef . " / " . $group->getTitle() . "\n";
    echo "Group participants     : " . count($participantIds) . "\n";
    echo "Group admins           : " . count($adminIds) . "\n";
    echo "Group members          : " . count($memberIds) . "\n";
    echo "Also in parent course  : " . $parentCourseParticipantCount . "\n";
    echo "Group-only participants: " . $groupOnlyCount . "\n";
    echo "Output                 : " . $output . "\n";
    echo "RESULT                  : EXTRACTION_OK\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "ERROR: " . get_class($exception) . ': ' . $exception->getMessage() . PHP_EOL
    );
    exit(1);
}

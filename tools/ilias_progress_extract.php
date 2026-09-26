<?php

declare(strict_types=1);

/**
 * Ilias2Moodle - Phase 7.3 read-only ILIAS learning progress extractor.
 *
 * This tool inventories the common ILIAS Learning Progress layer for one
 * course and its repository descendants. It uses ILIAS application classes
 * only and deliberately avoids any status recalculation.
 *
 * Important safety rule:
 *   ilLPStatus::_lookupStatus(..., false) is used so missing LP records are
 *   never created by this extractor.
 *
 * Example:
 * php tools/ilias_progress_extract.php \
 *   --course-ref=128 \
 *   --ilias-root=/var/www/ilias \
 *   --client=ilias10 \
 *   --output=/tmp/phase73_ilias_progress_course_504.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: this script must be executed from CLI.\n");
    exit(2);
}

function phase73_usage(string $script): void
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
  ILIAS_PROGRESS_OUTPUT

TXT;

    fwrite(STDERR, $message);
}

function phase73_env(string $name, string $default): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $default;
    }

    return trim($value);
}

function phase73_user_value(
    object $user,
    string $method,
    mixed $default = ''
): mixed {
    if (!method_exists($user, $method)) {
        return $default;
    }

    try {
        return $user->{$method}();
    } catch (Throwable) {
        return $default;
    }
}

function phase73_status_name(?int $status): ?string
{
    return match ($status) {
        ilLPStatus::LP_STATUS_NOT_ATTEMPTED_NUM => 'not_attempted',
        ilLPStatus::LP_STATUS_IN_PROGRESS_NUM => 'in_progress',
        ilLPStatus::LP_STATUS_COMPLETED_NUM => 'completed',
        ilLPStatus::LP_STATUS_FAILED_NUM => 'failed',
        default => null,
    };
}

function phase73_mode_name(int $mode): string
{
    static $map = null;

    if ($map === null) {
        $map = [];

        $reflection = new ReflectionClass(ilLPObjSettings::class);
        foreach ($reflection->getConstants() as $name => $value) {
            if (!str_starts_with((string) $name, 'LP_MODE_')) {
                continue;
            }

            if (!is_int($value)) {
                continue;
            }

            if (!array_key_exists($value, $map)) {
                $map[$value] = (string) $name;
            }
        }
    }

    return $map[$mode] ?? ('LP_MODE_UNKNOWN_' . $mode);
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
    phase73_usage($argv[0]);
    exit(2);
}

if (array_key_exists('help', $options)) {
    phase73_usage($argv[0]);
    exit(0);
}

$courseRef = (int) ($options['course-ref'] ?? 0);

$iliasRoot = rtrim(
    (string) (
        $options['ilias-root']
        ?? phase73_env('ILIAS_ROOT', '/var/www/ilias')
    ),
    '/'
);

$clientId = trim(
    (string) (
        $options['client']
        ?? phase73_env('ILIAS_CLIENT_ID', 'ilias10')
    )
);

$output = trim(
    (string) (
        $options['output']
        ?? phase73_env(
            'ILIAS_PROGRESS_OUTPUT',
            '/tmp/phase73_ilias_progress.json'
        )
    )
);

if ($courseRef <= 0) {
    fwrite(
        STDERR,
        "ERROR: --course-ref must be a positive integer.\n\n"
    );
    phase73_usage($argv[0]);
    exit(2);
}

if ($iliasRoot === '' || !is_dir($iliasRoot)) {
    fwrite(
        STDERR,
        "ERROR: invalid ILIAS root: {$iliasRoot}\n"
    );
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

    $course = ilObjectFactory::getInstanceByRefId(
        $courseRef,
        false
    );

    if (!$course instanceof ilObject
            || $course->getType() !== 'crs') {
        throw new RuntimeException(
            "ref_id {$courseRef} is not an ILIAS course."
        );
    }

    $participants = ilParticipants::getInstance($courseRef);

    $roleIds = [
        'admin' => array_values(
            array_unique(
                array_map(
                    'intval',
                    $participants->getAdmins()
                )
            )
        ),
        'tutor' => array_values(
            array_unique(
                array_map(
                    'intval',
                    $participants->getTutors()
                )
            )
        ),
        'member' => array_values(
            array_unique(
                array_map(
                    'intval',
                    $participants->getMembers()
                )
            )
        ),
    ];

    foreach ($roleIds as &$ids) {
        sort($ids);
    }
    unset($ids);

    $participantIds = [];

    foreach ($roleIds as $ids) {
        foreach ($ids as $userId) {
            $participantIds[$userId] = true;
        }
    }

    $participantIds = array_map(
        'intval',
        array_keys($participantIds)
    );
    sort($participantIds);

    $users = [];

    foreach ($participantIds as $userId) {
        $user = ilObjectFactory::getInstanceByObjId(
            $userId,
            false
        );

        $exists = $user instanceof ilObjUser;

        $roles = [];

        foreach ($roleIds as $role => $ids) {
            if (in_array($userId, $ids, true)) {
                $roles[] = $role;
            }
        }

        $users[(string) $userId] = [
            'source_user_id' => (string) $userId,
            'exists' => $exists,
            'login' => $exists
                ? (string) phase73_user_value(
                    $user,
                    'getLogin'
                )
                : '',
            'roles' => $roles,
        ];
    }

    $tree = $DIC->repositoryTree();

    $rootNode = $tree->getNodeData($courseRef);

    if (!$rootNode) {
        throw new RuntimeException(
            "Course ref_id {$courseRef} is missing from repository tree."
        );
    }

    $nodes = $tree->getSubTree($rootNode);

    $objects = [];
    $supportedCount = 0;
    $activeModeCount = 0;
    $progressRecordCount = 0;
    $statusCounts = [
        'not_attempted' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'failed' => 0,
        'unknown' => 0,
    ];

    foreach ($nodes as $node) {
        $refId = (int) (
            $node['ref_id']
            ?? $node['child']
            ?? 0
        );

        $objId = (int) (
            $node['obj_id']
            ?? (
                $refId > 0
                    ? ilObject::_lookupObjId($refId)
                    : 0
            )
        );

        $type = trim(
            (string) (
                $node['type']
                ?? (
                    $objId > 0
                        ? ilObject::_lookupType($objId)
                        : ''
                )
            )
        );

        if ($refId <= 0 || $objId <= 0 || $type === '') {
            continue;
        }

        $title = trim(
            (string) (
                $node['title']
                ?? ilObject::_lookupTitle($objId)
            )
        );

        $supported = false;

        try {
            $supported = ilObjectLP::isSupportedObjectType(
                $type
            );
        } catch (Throwable) {
            $supported = false;
        }

        $objectEntry = [
            'ref_id' => (string) $refId,
            'object_id' => (string) $objId,
            'type' => $type,
            'title' => $title,
            'lp_supported' => $supported,
            'lp_mode' => null,
            'lp_mode_name' => null,
            'lp_active' => false,
            'records' => [],
            'error' => null,
        ];

        if (!$supported) {
            $objects[] = $objectEntry;
            continue;
        }

        $supportedCount++;

        try {
            $objectLP = ilObjectLP::getInstance($objId);
            $mode = (int) $objectLP->getCurrentMode();

            $objectEntry['lp_mode'] = $mode;
            $objectEntry['lp_mode_name'] =
                phase73_mode_name($mode);
            $objectEntry['lp_active'] =
                $mode !== ilLPObjSettings::LP_MODE_DEACTIVATED;

            if ($objectEntry['lp_active']) {
                $activeModeCount++;
            }

            foreach ($participantIds as $userId) {
                // CRITICAL: false means do not create/recalculate a
                // missing LP record.
                $status = ilLPStatus::_lookupStatus(
                    $objId,
                    $userId,
                    false
                );

                if ($status === null) {
                    continue;
                }

                $status = (int) $status;
                $statusName = phase73_status_name($status);

                $percentage = ilLPStatus::_lookupPercentage(
                    $objId,
                    $userId
                );

                // Safe here because _lookupStatus(..., false)
                // already proved that an existing clean LP row exists.
                $statusChanged =
                    ilLPStatus::_lookupStatusChanged(
                        $objId,
                        $userId
                    );

                $record = [
                    'source_user_id' => (string) $userId,
                    'source_login' => (string) (
                        $users[(string) $userId]['login']
                        ?? ''
                    ),
                    'status' => $status,
                    'status_name' => $statusName,
                    'percentage' => $percentage,
                    'status_changed' => $statusChanged,
                ];

                $objectEntry['records'][] = $record;
                $progressRecordCount++;

                if ($statusName !== null
                        && array_key_exists(
                            $statusName,
                            $statusCounts
                        )) {
                    $statusCounts[$statusName]++;
                } else {
                    $statusCounts['unknown']++;
                }
            }
        } catch (Throwable $exception) {
            $objectEntry['error'] =
                get_class($exception)
                . ': '
                . $exception->getMessage();
        }

        $objects[] = $objectEntry;
    }

    $document = [
        'schema_version' => '1.0',
        'phase' => '7.3',
        'extractor' => 'common_learning_progress_inventory',
        'source' => [
            'lms' => 'ILIAS',
            'client_id' => CLIENT_ID,
        ],
        'course' => [
            'object_id' => (string) $course->getId(),
            'ref_id' => (string) $courseRef,
            'title' => (string) $course->getTitle(),
        ],
        'users' => $users,
        'objects' => $objects,
        'counts' => [
            'course_participants' => count($participantIds),
            'repository_nodes' => count($objects),
            'lp_supported_objects' => $supportedCount,
            'lp_active_objects' => $activeModeCount,
            'existing_lp_records' => $progressRecordCount,
            'statuses' => $statusCounts,
        ],
        'safety' => [
            'read_only' => true,
            'missing_status_creation' => false,
            'status_refresh_called' => false,
            'status_update_called' => false,
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
    echo " Ilias2Moodle - Phase 7.3 progress inventory\n";
    echo "============================================\n";
    echo "Client              : " . CLIENT_ID . "\n";
    echo "Course              : "
        . $course->getId()
        . " / ref "
        . $courseRef
        . " / "
        . $course->getTitle()
        . "\n";
    echo "Participants        : "
        . count($participantIds)
        . "\n";
    echo "Repository nodes    : "
        . count($objects)
        . "\n";
    echo "LP supported        : "
        . $supportedCount
        . "\n";
    echo "LP active           : "
        . $activeModeCount
        . "\n";
    echo "Existing LP records : "
        . $progressRecordCount
        . "\n";
    echo "Completed           : "
        . $statusCounts['completed']
        . "\n";
    echo "In progress         : "
        . $statusCounts['in_progress']
        . "\n";
    echo "Failed              : "
        . $statusCounts['failed']
        . "\n";
    echo "Not attempted       : "
        . $statusCounts['not_attempted']
        . "\n";
    echo "Output              : "
        . $output
        . "\n";
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

    exit(1);
}

<?php

declare(strict_types=1);

/**
 * Ilias2Moodle - Phase 7.3 read-only SCORM tracking inventory.
 *
 * Reads already persisted ILIAS SCORM tracking data. It deliberately does not
 * call ilTrQuery::getSCOsStatusForUser() or any LP refresh/update method.
 *
 * Examples:
 * php tools/ilias_scorm_results_extract.php \
 *   --scorm-ref=241 \
 *   --course-ref=128 \
 *   --ilias-root=/var/www/ilias \
 *   --client=ilias10 \
 *   --output=/tmp/phase73_scorm_719.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: this script must be executed from CLI.\n");
    exit(2);
}

function phase73scorm_usage(string $script): void
{
    fwrite(
        STDERR,
        <<<TXT
Usage:

  php {$script} --scorm-ref=<REF_ID> --course-ref=<REF_ID> [options]

Options:

  --scorm-ref=<REF_ID>      ILIAS SCORM repository ref_id
  --course-ref=<REF_ID>     Parent ILIAS course repository ref_id
  --ilias-root=<path>       ILIAS root directory (default: /var/www/ilias)
  --client=<client_id>      ILIAS client id (default: ilias10)
  --output=<file>           JSON output file
  --help                    Show this help

TXT
    );
}

function phase73scorm_env(string $name, string $default): string
{
    $value = getenv($name);
    return ($value === false || trim($value) === '')
        ? $default
        : trim($value);
}

function phase73scorm_status_name(?int $status): ?string
{
    if ($status === null) {
        return null;
    }

    return match ($status) {
        ilLPStatus::LP_STATUS_NOT_ATTEMPTED_NUM => 'not_attempted',
        ilLPStatus::LP_STATUS_IN_PROGRESS_NUM => 'in_progress',
        ilLPStatus::LP_STATUS_COMPLETED_NUM => 'completed',
        ilLPStatus::LP_STATUS_FAILED_NUM => 'failed',
        default => 'unknown',
    };
}

$options = getopt(
    '',
    [
        'scorm-ref:',
        'course-ref:',
        'ilias-root:',
        'client:',
        'output:',
        'help',
    ]
);

if ($options === false) {
    phase73scorm_usage($argv[0]);
    exit(2);
}

if (array_key_exists('help', $options)) {
    phase73scorm_usage($argv[0]);
    exit(0);
}

$scormRef = (int) ($options['scorm-ref'] ?? 0);
$courseRef = (int) ($options['course-ref'] ?? 0);
$iliasRoot = rtrim(
    (string) (
        $options['ilias-root']
        ?? phase73scorm_env('ILIAS_ROOT', '/var/www/ilias')
    ),
    '/'
);
$clientId = trim(
    (string) (
        $options['client']
        ?? phase73scorm_env('ILIAS_CLIENT_ID', 'ilias10')
    )
);
$output = trim(
    (string) (
        $options['output']
        ?? '/tmp/phase73_ilias_scorm_results.json'
    )
);

if ($scormRef <= 0 || $courseRef <= 0) {
    fwrite(
        STDERR,
        "ERROR: --scorm-ref and --course-ref must be positive integers.\n"
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

    $sourceObject = ilObjectFactory::getInstanceByRefId(
        $scormRef,
        false
    );
    $course = ilObjectFactory::getInstanceByRefId(
        $courseRef,
        false
    );

    if (!$sourceObject instanceof ilObject
            || $sourceObject->getType() !== 'sahs') {
        throw new RuntimeException(
            "ref_id {$scormRef} is not an ILIAS SAHS/SCORM object."
        );
    }

    if (!$course instanceof ilObject
            || $course->getType() !== 'crs') {
        throw new RuntimeException(
            "ref_id {$courseRef} is not an ILIAS course."
        );
    }

    $objId = (int) $sourceObject->getId();
    $subType = ilObjSAHSLearningModule::_lookupSubType($objId);

    if ($subType === 'scorm2004') {
        $module = new ilObjSCORM2004LearningModule(
            $objId,
            false
        );
        $trackedUserIds = ilSCORM2004Tracking::_getTrackedUsers(
            $objId
        );
    } else if (in_array(
        $subType,
        ['scorm', 'aicc', 'hacp'],
        true
    )) {
        $module = new ilObjSCORMLearningModule(
            $objId,
            false
        );

        $trackedUserIds = [];
        foreach ($module->getTrackedUsers('') as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid > 0) {
                $trackedUserIds[] = $uid;
            }
        }
    } else {
        throw new RuntimeException(
            "Unsupported SAHS subtype for this inventory: {$subType}"
        );
    }

    $trackedUserIds = array_values(
        array_unique(
            array_map('intval', $trackedUserIds)
        )
    );
    sort($trackedUserIds);

    $participants = ilParticipants::getInstance($courseRef);

    $courseUserIds = array_values(
        array_unique(
            array_map(
                'intval',
                array_merge(
                    $participants->getAdmins(),
                    $participants->getTutors(),
                    $participants->getMembers()
                )
            )
        )
    );
    sort($courseUserIds);

    $users = [];
    $trackedInCourseCount = 0;
    $attemptCount = 0;
    $scoRecordCount = 0;
    $usersWithScoDataCount = 0;

    foreach ($courseUserIds as $userId) {
        $user = ilObjectFactory::getInstanceByObjId(
            $userId,
            false
        );

        $login = $user instanceof ilObjUser
            ? (string) $user->getLogin()
            : '';

        $tracked = in_array(
            $userId,
            $trackedUserIds,
            true
        );

        if ($tracked) {
            $trackedInCourseCount++;
        }

        $attempts = $module->getAttemptsForUser($userId);
        $attemptCount += $attempts;

        // Safe read: do not create or refresh a missing LP row.
        $lpStatus = ilLPStatus::_lookupStatus(
            $objId,
            $userId,
            false
        );

        if ($subType === 'scorm2004') {
            $raw = $module->getTrackingDataAgg(
                $userId,
                true
            );

            $scoData = [];

            foreach ($raw as $scoId => $row) {
                $scoData[] = [
                    'sco_id' => (int) (
                        $row['cp_node_id']
                        ?? $scoId
                    ),
                    'last_access' => $row['last_access'] ?? null,
                    'total_time_seconds' => isset($row['total_time'])
                        ? (float) $row['total_time']
                        : null,
                    'success_status' =>
                        $row['success_status'] ?? null,
                    'completion_status' =>
                        $row['completion_status'] ?? null,
                    'score_raw' => $row['c_raw'] ?? null,
                    'score_scaled' => $row['scaled'] ?? null,
                ];
            }
        } else {
            $raw = $module->getTrackingDataAgg($userId);

            $scoData = [];

            foreach ($raw as $row) {
                $scoData[] = [
                    'sco_id' => (int) (
                        $row['sco_id'] ?? 0
                    ),
                    'title' => (string) (
                        $row['title'] ?? ''
                    ),
                    'status' => (string) (
                        $row['status'] ?? ''
                    ),
                    'score' => $row['score'] ?? null,
                    'total_time' => $row['time'] ?? null,
                ];
            }
        }

        if ($scoData) {
            $usersWithScoDataCount++;
        }

        $scoRecordCount += count($scoData);

        $users[(string) $userId] = [
            'source_user_id' => (string) $userId,
            'source_login' => $login,
            'tracked_user' => $tracked,
            'attempts' => $attempts,
            'lp_status' => $lpStatus,
            'lp_status_name' => phase73scorm_status_name(
                $lpStatus === null ? null : (int) $lpStatus
            ),
            'sco_record_count' => count($scoData),
            'sco_tracking' => $scoData,
        ];
    }

    $document = [
        'schema_version' => '1.0',
        'phase' => '7.3',
        'extractor' => 'scorm_tracking_inventory',
        'source' => [
            'lms' => 'ILIAS',
            'client_id' => CLIENT_ID,
        ],
        'course' => [
            'object_id' => (string) $course->getId(),
            'ref_id' => (string) $courseRef,
            'title' => (string) $course->getTitle(),
        ],
        'scorm' => [
            'object_id' => (string) $objId,
            'ref_id' => (string) $scormRef,
            'title' => (string) $sourceObject->getTitle(),
            'subtype' => $subType,
        ],
        'users' => $users,
        'counts' => [
            'course_participants' => count($courseUserIds),
            'tracked_users_all' => count($trackedUserIds),
            'tracked_users_in_course' => $trackedInCourseCount,
            'users_with_sco_data' => $usersWithScoDataCount,
            'total_attempts' => $attemptCount,
            'sco_tracking_records' => $scoRecordCount,
        ],
        'tracked_user_ids_all' => array_map(
            'strval',
            $trackedUserIds
        ),
        'safety' => [
            'read_only' => true,
            'lp_refresh_called' => false,
            'lp_update_called' => false,
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
    echo " Ilias2Moodle - Phase 7.3 SCORM inventory\n";
    echo "============================================\n";
    echo "Client              : " . CLIENT_ID . "\n";
    echo "Course              : "
        . $course->getId()
        . " / ref "
        . $courseRef
        . " / "
        . $course->getTitle()
        . "\n";
    echo "SCORM               : "
        . $objId
        . " / ref "
        . $scormRef
        . " / "
        . $sourceObject->getTitle()
        . "\n";
    echo "Subtype             : "
        . $subType
        . "\n";
    echo "Course participants : "
        . count($courseUserIds)
        . "\n";
    echo "Tracked users (all) : "
        . count($trackedUserIds)
        . "\n";
    echo "Tracked in course   : "
        . $trackedInCourseCount
        . "\n";
    echo "Users with SCO data : "
        . $usersWithScoDataCount
        . "\n";
    echo "Total attempts      : "
        . $attemptCount
        . "\n";
    echo "SCO tracking rows   : "
        . $scoRecordCount
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

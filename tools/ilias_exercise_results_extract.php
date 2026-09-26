<?php

declare(strict_types=1);

/**
 * Ilias2Moodle - Phase 7.3 read-only Exercise submissions/results inventory.
 *
 * Reads assignments, submissions and tutor grading metadata already persisted
 * in ILIAS. It does not call update(), save(), processExerciseStatus() or any
 * Learning Progress refresh/update method.
 *
 * Example:
 * php tools/ilias_exercise_results_extract.php \
 *   --exercise-ref=274 \
 *   --course-ref=128 \
 *   --ilias-root=/var/www/ilias \
 *   --client=ilias10 \
 *   --output=/tmp/phase73_exercise_806.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: this script must be executed from CLI.\n");
    exit(2);
}

function phase73exc_usage(string $script): void
{
    fwrite(
        STDERR,
        <<<TXT
Usage:

  php {$script} --exercise-ref=<REF_ID> --course-ref=<REF_ID> [options]

Options:

  --exercise-ref=<REF_ID>   ILIAS Exercise repository ref_id
  --course-ref=<REF_ID>     Parent ILIAS course repository ref_id
  --ilias-root=<path>       ILIAS root directory (default: /var/www/ilias)
  --client=<client_id>      ILIAS client id (default: ilias10)
  --output=<file>           JSON output file
  --help                    Show this help

TXT
    );
}

function phase73exc_env(string $name, string $default): string
{
    $value = getenv($name);
    return ($value === false || trim($value) === '')
        ? $default
        : trim($value);
}

$options = getopt(
    '',
    [
        'exercise-ref:',
        'course-ref:',
        'ilias-root:',
        'client:',
        'output:',
        'help',
    ]
);

if ($options === false) {
    phase73exc_usage($argv[0]);
    exit(2);
}

if (array_key_exists('help', $options)) {
    phase73exc_usage($argv[0]);
    exit(0);
}

$exerciseRef = (int) ($options['exercise-ref'] ?? 0);
$courseRef = (int) ($options['course-ref'] ?? 0);
$iliasRoot = rtrim(
    (string) (
        $options['ilias-root']
        ?? phase73exc_env('ILIAS_ROOT', '/var/www/ilias')
    ),
    '/'
);
$clientId = trim(
    (string) (
        $options['client']
        ?? phase73exc_env('ILIAS_CLIENT_ID', 'ilias10')
    )
);
$output = trim(
    (string) (
        $options['output']
        ?? '/tmp/phase73_ilias_exercise_results.json'
    )
);

if ($exerciseRef <= 0 || $courseRef <= 0) {
    fwrite(
        STDERR,
        "ERROR: --exercise-ref and --course-ref must be positive integers.\n"
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
    $db = $DIC->database();

    // Avoid instantiating specialised repository objects in CLI. In ILIAS 10
    // ilObjExercise construction pulls Repository/Form services that require
    // the web constant ILIAS_HTTP_PATH.
    $exerciseObjId = (int) ilObject::_lookupObjId($exerciseRef);
    $exerciseType = $exerciseObjId > 0
        ? (string) ilObject::_lookupType($exerciseObjId)
        : '';
    $exerciseTitle = $exerciseObjId > 0
        ? (string) ilObject::_lookupTitle($exerciseObjId)
        : '';

    $courseObjId = (int) ilObject::_lookupObjId($courseRef);
    $courseType = $courseObjId > 0
        ? (string) ilObject::_lookupType($courseObjId)
        : '';
    $courseTitle = $courseObjId > 0
        ? (string) ilObject::_lookupTitle($courseObjId)
        : '';

    if ($exerciseObjId <= 0 || $exerciseType !== 'exc') {
        throw new RuntimeException(
            "ref_id {$exerciseRef} is not an ILIAS Exercise."
        );
    }

    if ($courseObjId <= 0 || $courseType !== 'crs') {
        throw new RuntimeException(
            "ref_id {$courseRef} is not an ILIAS course."
        );
    }

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

    foreach ($courseUserIds as $userId) {
        $user = ilObjectFactory::getInstanceByObjId(
            $userId,
            false
        );

        $users[(string) $userId] = [
            'source_user_id' => (string) $userId,
            'source_login' => $user instanceof ilObjUser
                ? (string) $user->getLogin()
                : '',
        ];
    }

    // Read assignments directly. ilExAssignment's constructor initialises
    // Exercise GUI services which require ILIAS_HTTP_PATH and are not CLI-safe.
    $assignments = [];

    $assignmentSet = $db->queryF(
        'SELECT * FROM exc_assignment WHERE exc_id = %s ORDER BY order_nr',
        ['integer'],
        [$exerciseObjId]
    );

    while ($row = $db->fetchAssoc($assignmentSet)) {
        $assignments[] = $row;
    }

    $assignmentTypeNames = [
        1 => 'upload',
        2 => 'blog',
        3 => 'portfolio',
        4 => 'upload_team',
        5 => 'text',
        6 => 'wiki_team',
    ];

    $submissionTypeNames = [
        1 => 'File',
        2 => 'Object',
        3 => 'Object',
        4 => 'File',
        5 => 'Text',
        6 => 'RepoObject',
    ];

    $assignmentData = [];
    $statusRowCount = 0;
    $submittedCount = 0;
    $passedCount = 0;
    $failedCount = 0;
    $notgradedCount = 0;
    $markCount = 0;
    $commentCount = 0;

    foreach ($assignments as $assignment) {
        $assignmentId = (int) ($assignment['id'] ?? 0);
        $assignmentType = (int) ($assignment['type'] ?? 0);
        $usesTeams = in_array($assignmentType, [4, 6], true);

        if ($assignmentId <= 0) {
            continue;
        }

        $assignmentUsers = [];

        foreach ($courseUserIds as $userId) {
            // Read exercise membership directly. This mirrors the source used
            // by ilExAssignment::getMemberListData() without constructing
            // ilExSubmission (which pulls web/UI dependencies in CLI).
            $memberSet = $db->queryF(
                'SELECT usr_id FROM exc_members WHERE obj_id = %s AND usr_id = %s',
                ['integer', 'integer'],
                [$exerciseObjId, $userId]
            );
            $exerciseMember = (bool) $db->fetchAssoc($memberSet);

            // Read tutor/member status directly from the documented
            // exc_mem_ass_status table. No setters/update hooks are called.
            $statusSet = $db->queryF(
                'SELECT * FROM exc_mem_ass_status WHERE ass_id = %s AND usr_id = %s',
                ['integer', 'integer'],
                [$assignmentId, $userId]
            );
            $memberRow = $db->fetchAssoc($statusSet) ?: null;

            $statusRowExists = is_array($memberRow);

            $status = $statusRowExists
                ? (string) ($memberRow['status'] ?? 'notgraded')
                : null;

            $mark = $statusRowExists
                ? trim((string) ($memberRow['mark'] ?? ''))
                : '';

            $comment = $statusRowExists
                ? trim((string) ($memberRow['u_comment'] ?? ''))
                : '';

            // Mirror ilExSubmission::getLastSubmission() with a read-only
            // query on exc_returned. The current POC assignment is individual;
            // for team assignments we report that detailed submission lookup
            // is not evaluated rather than guessing team ownership.
            $lastSubmission = null;
            $hasSubmitted = false;

            if (!$usesTeams) {
                $db->setLimit(1, 0);

                $submissionSet = $db->queryF(
                    'SELECT ts FROM exc_returned
                     WHERE ass_id = %s
                       AND user_id = %s
                       AND (filename IS NOT NULL OR atext IS NOT NULL)
                       AND ts IS NOT NULL
                     ORDER BY ts DESC',
                    ['integer', 'integer'],
                    [$assignmentId, $userId]
                );

                $submissionRow = $db->fetchAssoc($submissionSet);

                if ($submissionRow && !empty($submissionRow['ts'])) {
                    $lastSubmission = (string) $submissionRow['ts'];
                    $hasSubmitted = true;
                }
            }

            if ($statusRowExists) {
                $statusRowCount++;

                if ($status === 'passed') {
                    $passedCount++;
                } else if ($status === 'failed') {
                    $failedCount++;
                } else {
                    $notgradedCount++;
                }
            }

            if ($hasSubmitted) {
                $submittedCount++;
            }

            if ($mark !== '') {
                $markCount++;
            }

            if ($comment !== '') {
                $commentCount++;
            }

            $assignmentUsers[(string) $userId] = [
                'source_user_id' => (string) $userId,
                'source_login' => $users[(string) $userId]['source_login'],
                'exercise_member' => $exerciseMember,
                'status_row_exists' => $statusRowExists,
                'status' => $status,
                'status_time' => $statusRowExists
                    ? (string) ($memberRow['status_time'] ?? '')
                    : '',
                'sent_time' => $statusRowExists
                    ? (string) ($memberRow['sent_time'] ?? '')
                    : '',
                'feedback_time' => $statusRowExists
                    ? (string) ($memberRow['feedback_time'] ?? '')
                    : '',
                'mark' => $mark,
                'comment' => $comment,
                'notice' => $statusRowExists
                    ? trim((string) ($memberRow['notice'] ?? ''))
                    : '',
                'has_submission' => $hasSubmitted,
                'last_submission' => $lastSubmission,
                'submission_lookup' => $usesTeams
                    ? 'TEAM_NOT_EVALUATED'
                    : 'INDIVIDUAL_READ_ONLY',
            ];
        }

        $assignmentData[] = [
            'assignment_id' => (string) $assignmentId,
            'title' => (string) ($assignment['title'] ?? ''),
            'type' => $assignmentType,
            'type_name' => $assignmentTypeNames[$assignmentType] ?? 'unknown',
            'submission_type' => $submissionTypeNames[$assignmentType] ?? 'Unknown',
            'uses_teams' => $usesTeams,
            'mandatory' => !empty($assignment['mandatory']),
            'start_time' => isset($assignment['start_time'])
                ? (int) $assignment['start_time']
                : null,
            'deadline' => isset($assignment['time_stamp'])
                ? (int) $assignment['time_stamp']
                : null,
            'extended_deadline' => isset($assignment['deadline2'])
                ? (int) $assignment['deadline2']
                : null,
            'users' => $assignmentUsers,
        ];
    }

    $document = [
        'schema_version' => '1.0',
        'phase' => '7.3',
        'extractor' => 'exercise_results_inventory',
        'source' => [
            'lms' => 'ILIAS',
            'client_id' => CLIENT_ID,
        ],
        'course' => [
            'object_id' => (string) $courseObjId,
            'ref_id' => (string) $courseRef,
            'title' => (string) $courseTitle,
        ],
        'exercise' => [
            'object_id' => (string) $exerciseObjId,
            'ref_id' => (string) $exerciseRef,
            'title' => (string) $exerciseTitle,
        ],
        'users' => $users,
        'assignments' => $assignmentData,
        'counts' => [
            'course_participants' => count($courseUserIds),
            'assignments' => count($assignmentData),
            'status_rows' => $statusRowCount,
            'submitted_records' => $submittedCount,
            'passed' => $passedCount,
            'failed' => $failedCount,
            'notgraded' => $notgradedCount,
            'marks' => $markCount,
            'comments' => $commentCount,
        ],
        'safety' => [
            'read_only' => true,
            'exercise_status_processing_called' => false,
            'member_status_update_called' => false,
            'lp_refresh_called' => false,
            'lp_update_called' => false,
            'direct_tables_read' => [
                'exc_assignment',
                'exc_members',
                'exc_mem_ass_status',
                'exc_returned',
            ],
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
    echo " Ilias2Moodle - Phase 7.3 Exercise inventory\n";
    echo "============================================\n";
    echo "Client              : " . CLIENT_ID . "\n";
    echo "Course              : "
        . $courseObjId
        . " / ref "
        . $courseRef
        . " / "
        . $courseTitle
        . "\n";
    echo "Exercise            : "
        . $exerciseObjId
        . " / ref "
        . $exerciseRef
        . " / "
        . $exerciseTitle
        . "\n";
    echo "Course participants : "
        . count($courseUserIds)
        . "\n";
    echo "Assignments         : "
        . count($assignmentData)
        . "\n";
    echo "Status rows         : "
        . $statusRowCount
        . "\n";
    echo "Submissions         : "
        . $submittedCount
        . "\n";
    echo "Passed              : "
        . $passedCount
        . "\n";
    echo "Failed              : "
        . $failedCount
        . "\n";
    echo "Not graded          : "
        . $notgradedCount
        . "\n";
    echo "Marks               : "
        . $markCount
        . "\n";
    echo "Comments            : "
        . $commentCount
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
    fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);

    exit(1);
}

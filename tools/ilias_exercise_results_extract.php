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

    $exercise = ilObjectFactory::getInstanceByRefId(
        $exerciseRef,
        false
    );
    $course = ilObjectFactory::getInstanceByRefId(
        $courseRef,
        false
    );

    if (!$exercise instanceof ilObjExercise
            || $exercise->getType() !== 'exc') {
        throw new RuntimeException(
            "ref_id {$exerciseRef} is not an ILIAS Exercise."
        );
    }

    if (!$course instanceof ilObject
            || $course->getType() !== 'crs') {
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

    $assignments = ilExAssignment::getInstancesByExercise(
        (int) $exercise->getId()
    );

    $assignmentData = [];
    $statusRowCount = 0;
    $submittedCount = 0;
    $passedCount = 0;
    $failedCount = 0;
    $notgradedCount = 0;
    $markCount = 0;
    $commentCount = 0;

    foreach ($assignments as $assignment) {
        $memberList = $assignment->getMemberListData();

        $assignmentUsers = [];

        foreach ($courseUserIds as $userId) {
            $memberRow = $memberList[$userId] ?? null;

            // ilExSubmission construction is read-only here. We only call
            // read accessors and never mutation methods.
            $submission = new ilExSubmission(
                $assignment,
                $userId
            );

            $lastSubmission = $submission->getLastSubmission();
            $hasSubmitted = $lastSubmission !== null
                && $lastSubmission !== '';

            $statusRowExists = is_array($memberRow)
                && array_key_exists('status', $memberRow);

            $status = $statusRowExists
                ? (string) ($memberRow['status'] ?? 'notgraded')
                : null;

            $mark = $statusRowExists
                ? trim((string) ($memberRow['mark'] ?? ''))
                : '';

            $comment = $statusRowExists
                ? trim((string) ($memberRow['comment'] ?? ''))
                : '';

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
                'exercise_member' => is_array($memberRow),
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
            ];
        }

        $assignmentData[] = [
            'assignment_id' => (string) $assignment->getId(),
            'title' => (string) $assignment->getTitle(),
            'type' => (int) $assignment->getType(),
            'submission_type' => (string) (
                $assignment->getAssignmentType()->getSubmissionType()
            ),
            'uses_teams' => (bool) $assignment->hasTeam(),
            'mandatory' => (bool) $assignment->getMandatory(),
            'start_time' => $assignment->getStartTime(),
            'deadline' => $assignment->getDeadline(),
            'extended_deadline' => $assignment->getExtendedDeadline(),
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
            'object_id' => (string) $course->getId(),
            'ref_id' => (string) $courseRef,
            'title' => (string) $course->getTitle(),
        ],
        'exercise' => [
            'object_id' => (string) $exercise->getId(),
            'ref_id' => (string) $exerciseRef,
            'title' => (string) $exercise->getTitle(),
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
        . $course->getId()
        . " / ref "
        . $courseRef
        . " / "
        . $course->getTitle()
        . "\n";
    echo "Exercise            : "
        . $exercise->getId()
        . " / ref "
        . $exerciseRef
        . " / "
        . $exercise->getTitle()
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

    exit(1);
}

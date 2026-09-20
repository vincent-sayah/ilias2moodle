<?php

declare(strict_types=1);

/**
 * Ilias2Moodle - Phase 7.3 read-only ILIAS Test results inventory.
 *
 * Uses ILIAS Test application classes only. No writes are performed.
 *
 * Example:
 * php tools/ilias_test_results_extract.php \
 *   --test-ref=236 \
 *   --course-ref=128 \
 *   --ilias-root=/var/www/ilias \
 *   --client=ilias10 \
 *   --output=/tmp/phase73_ilias_test_713_results.json
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: this script must be executed from CLI.\n");
    exit(2);
}

function phase73test_usage(string $script): void
{
    $message = <<<TXT
Usage:

  php {$script} --test-ref=<REF_ID> --course-ref=<REF_ID> [options]

Options:

  --test-ref=<REF_ID>       ILIAS Test repository ref_id
  --course-ref=<REF_ID>     Parent ILIAS course repository ref_id
  --ilias-root=<path>       ILIAS root directory (default: /var/www/ilias)
  --client=<client_id>      ILIAS client id (default: ilias10)
  --output=<file>           JSON output file
  --help                    Show this help

TXT;

    fwrite(STDERR, $message);
}

function phase73test_env(string $name, string $default): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $default;
    }

    return trim($value);
}

$options = getopt(
    '',
    [
        'test-ref:',
        'course-ref:',
        'ilias-root:',
        'client:',
        'output:',
        'help',
    ]
);

if ($options === false) {
    phase73test_usage($argv[0]);
    exit(2);
}

if (array_key_exists('help', $options)) {
    phase73test_usage($argv[0]);
    exit(0);
}

$testRef = (int) ($options['test-ref'] ?? 0);
$courseRef = (int) ($options['course-ref'] ?? 0);

$iliasRoot = rtrim(
    (string) (
        $options['ilias-root']
        ?? phase73test_env('ILIAS_ROOT', '/var/www/ilias')
    ),
    '/'
);

$clientId = trim(
    (string) (
        $options['client']
        ?? phase73test_env('ILIAS_CLIENT_ID', 'ilias10')
    )
);

$output = trim(
    (string) (
        $options['output']
        ?? '/tmp/phase73_ilias_test_results.json'
    )
);

if ($testRef <= 0 || $courseRef <= 0) {
    fwrite(
        STDERR,
        "ERROR: --test-ref and --course-ref must be positive integers.\n"
    );
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

    $test = ilObjectFactory::getInstanceByRefId(
        $testRef,
        false
    );

    $course = ilObjectFactory::getInstanceByRefId(
        $courseRef,
        false
    );

    if (!$test instanceof ilObjTest
            || $test->getType() !== 'tst') {
        throw new RuntimeException(
            "ref_id {$testRef} is not an ILIAS Test."
        );
    }

    if (!$course instanceof ilObject
            || $course->getType() !== 'crs') {
        throw new RuntimeException(
            "ref_id {$courseRef} is not an ILIAS course."
        );
    }

    $courseParticipants = ilParticipants::getInstance(
        $courseRef
    );

    $courseUserIds = array_values(
        array_unique(
            array_map(
                'intval',
                array_merge(
                    $courseParticipants->getAdmins(),
                    $courseParticipants->getTutors(),
                    $courseParticipants->getMembers()
                )
            )
        )
    );
    sort($courseUserIds);

    $testDbRows = $test->getTestParticipants();

    $scoringByUser = [];

    if (!empty($testDbRows)) {
        $participantList = new ilTestParticipantList(
            $test,
            $DIC['ilUser'],
            $DIC['lng'],
            $DIC['ilDB']
        );

        $participantList->initializeFromDbRows(
            $testDbRows
        );

        if (!empty($participantList->getAllActiveIds())) {
            $scoredList = $participantList
                ->getScoredParticipantList();

            foreach ($scoredList->getScoringsTableRows() as $row) {
                $scoringByUser[(int) $row['usr_id']] = $row;
            }
        }
    }

    $users = [];
    $activeCount = 0;
    $scoredCount = 0;
    $passedCount = 0;
    $failedCount = 0;

    foreach ($courseUserIds as $userId) {
        $user = ilObjectFactory::getInstanceByObjId(
            $userId,
            false
        );

        $login = $user instanceof ilObjUser
            ? (string) $user->getLogin()
            : '';

        $activeId = $test->getActiveIdOfUser($userId);

        $entry = [
            'source_user_id' => (string) $userId,
            'source_login' => $login,
            'active_id' => $activeId,
            'has_test_participation' => $activeId !== null,
            'result_pass' => null,
            'max_pass' => null,
            'scoring' => null,
        ];

        if ($activeId !== null) {
            $activeCount++;

            $entry['result_pass'] =
                ilObjTest::_getResultPass($activeId);
            $entry['max_pass'] =
                ilObjTest::_getMaxPass($activeId);

            if (isset($scoringByUser[$userId])) {
                $row = $scoringByUser[$userId];

                $entry['scoring'] = [
                    'scored_pass' => $row['scored_pass'] ?? null,
                    'answered_questions' =>
                        $row['answered_questions'] ?? null,
                    'total_questions' =>
                        $row['total_questions'] ?? null,
                    'reached_points' =>
                        $row['reached_points'] ?? null,
                    'max_points' =>
                        $row['max_points'] ?? null,
                    'percent_result' =>
                        $row['percent_result'] ?? null,
                    'passed_status' =>
                        $row['passed_status'] ?? null,
                    'final_mark' =>
                        $row['final_mark'] ?? null,
                    'scored_pass_finished_timestamp' =>
                        $row['scored_pass_finished_timestamp'] ?? null,
                    'finished_passes' =>
                        $row['finished_passes'] ?? null,
                    'has_unfinished_passes' =>
                        $row['has_unfinished_passes'] ?? null,
                ];

                $scoredCount++;

                if (!empty($row['passed_status'])) {
                    $passedCount++;
                } else {
                    $failedCount++;
                }
            }
        }

        $users[(string) $userId] = $entry;
    }

    $document = [
        'schema_version' => '1.0',
        'phase' => '7.3',
        'extractor' => 'test_results_inventory',
        'source' => [
            'lms' => 'ILIAS',
            'client_id' => CLIENT_ID,
        ],
        'course' => [
            'object_id' => (string) $course->getId(),
            'ref_id' => (string) $courseRef,
            'title' => (string) $course->getTitle(),
        ],
        'test' => [
            'object_id' => (string) $test->getId(),
            'ref_id' => (string) $testRef,
            'title' => (string) $test->getTitle(),
        ],
        'users' => $users,
        'counts' => [
            'course_participants' => count($courseUserIds),
            'test_participants_with_active_id' => $activeCount,
            'scored_participants' => $scoredCount,
            'passed' => $passedCount,
            'failed' => $failedCount,
        ],
        'safety' => [
            'read_only' => true,
            'test_result_recalculation' => false,
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
    echo " Ilias2Moodle - Phase 7.3 Test inventory\n";
    echo "============================================\n";
    echo "Client              : " . CLIENT_ID . "\n";
    echo "Course              : "
        . $course->getId()
        . " / ref "
        . $courseRef
        . " / "
        . $course->getTitle()
        . "\n";
    echo "Test                : "
        . $test->getId()
        . " / ref "
        . $testRef
        . " / "
        . $test->getTitle()
        . "\n";
    echo "Course participants : "
        . count($courseUserIds)
        . "\n";
    echo "Active IDs          : "
        . $activeCount
        . "\n";
    echo "Scored participants : "
        . $scoredCount
        . "\n";
    echo "Passed              : "
        . $passedCount
        . "\n";
    echo "Failed              : "
        . $failedCount
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

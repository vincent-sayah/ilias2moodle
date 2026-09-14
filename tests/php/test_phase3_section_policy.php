<?php

define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../../moodle/local_iliasmigration/classes/phase3_section_policy.php');

use local_iliasmigration\phase3_section_policy;

function fail_policy_test(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assert_policy_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fail_policy_test($message . ' expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true));
    }
}

assert_policy_same(
    0,
    phase3_section_policy::effective_update_section('', 0, 0, false),
    'A root resource already in section 0 must stay valid.'
);

assert_policy_same(
    3,
    phase3_section_policy::effective_update_section('', 0, 3, true),
    'A root resource in an ILIAS2Moodle synthetic section must keep that section.'
);

assert_policy_same(
    0,
    phase3_section_policy::effective_update_section('', 0, 3, false),
    'A root resource moved to an arbitrary regular section must remain invalid.'
);

assert_policy_same(
    4,
    phase3_section_policy::effective_update_section('237', 4, 7, true),
    'A parented resource must always use its mapped parent section, even if its current section is synthetic.'
);

assert_policy_same(
    2,
    phase3_section_policy::effective_update_section('248', 2, 2, false),
    'A parented resource already in its mapped section must remain unchanged.'
);

fwrite(STDOUT, "OK: Phase 3 section policy\n");

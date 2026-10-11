<?php

define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../../moodle/local_iliasmigration/classes/phase3_internal_link_policy.php');

use local_iliasmigration\phase3_internal_link_policy;

function fail_internal_link_test(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assert_internal_link_same($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fail_internal_link_test($message . ' expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true));
    }
}

$decision = phase3_internal_link_policy::choose([]);
assert_internal_link_same('PRESERVE', $decision['action'], 'No migrated target must preserve ILIAS fallback.');
assert_internal_link_same(
    'TARGET_NOT_MIGRATED',
    $decision['reason'],
    'No migrated target must expose an explicit reason.'
);

$candidate = [
    'target_type' => 'html_module',
    'target_id' => 20,
    'module' => 'resource',
    'url' => 'http://moodle.example/mod/resource/view.php?id=20',
];
$decision = phase3_internal_link_policy::choose([$candidate]);
assert_internal_link_same('REWRITE', $decision['action'], 'One valid target must be rewritten.');
assert_internal_link_same(20, $decision['candidate']['target_id'], 'The selected CMID must be preserved.');

$decision = phase3_internal_link_policy::choose([$candidate, $candidate]);
assert_internal_link_same(
    'REWRITE',
    $decision['action'],
    'Duplicate mappings to the same final URL must not create false ambiguity.'
);

$decision = phase3_internal_link_policy::choose([
    $candidate,
    [
        'target_type' => 'file',
        'target_id' => 43,
        'module' => 'resource',
        'url' => 'http://moodle.example/mod/resource/view.php?id=43',
    ],
]);
assert_internal_link_same(
    'PRESERVE',
    $decision['action'],
    'Distinct migrated targets must preserve the ILIAS fallback instead of guessing.'
);
assert_internal_link_same(
    'AMBIGUOUS_TARGET',
    $decision['reason'],
    'Ambiguous targets must expose an explicit reason.'
);



$root = dirname(__DIR__, 2);
$validator = file_get_contents(
    $root
    . '/moodle/local_iliasmigration/classes/phase3_package_validator.php'
);
$executor = file_get_contents(
    $root
    . '/moodle/local_iliasmigration/classes/resource_executor.php'
);

foreach ([
    'public function reconcile_internal_link',
    'return $this->rewrite_internal_url($operation);',
] as $check) {
    if (!str_contains($validator, $check)) {
        fail_internal_link_test(
            'Missing Phase 3 post-apply resolver behavior: ' . $check
        );
    }
}

foreach ([
    'internal_links_reconciled_after_apply',
    'reconcile_internal_link(',
    'update_existing_url_target(',
    "get_coursemodule_from_id(\n            'url'",
    '$moduleinfo->externalurl = $resolvedurl;',
] as $check) {
    if (!str_contains($executor, $check)) {
        fail_internal_link_test(
            'Missing Phase 3 post-apply reconciliation behavior: ' . $check
        );
    }
}

fwrite(STDOUT, "OK: Phase 3 internal link policy\n");

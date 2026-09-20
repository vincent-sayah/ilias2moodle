<?php

define('MOODLE_INTERNAL', true);

class moodle_exception extends Exception {}

require_once(
    __DIR__
    . '/../../moodle/local_iliasmigration/classes/phase7_identity_policy.php'
);

$source = [
    '6' => [
        'exists' => true,
        'login' => 'root',
        'email' => 'ilias@yourserver.com',
    ],
    '401' => [
        'exists' => true,
        'login' => 'stagiaire.1',
        'email' => 'vince.syh@free.fr',
    ],
    '402' => [
        'exists' => true,
        'login' => 'stagiaire.2',
        'email' => 'vince.syh@free.fr',
    ],
    '410' => [
        'exists' => true,
        'login' => 'stagiaire.10',
        'email' => 'vince.syh@free.fr',
    ],
];

$target = [
    [
        'id' => 2,
        'username' => 'admin',
        'email' => 'vince.syh@free.fr',
    ],
    [
        'id' => 3,
        'username' => 'etudiant',
        'email' => 'vince.1403@hotmail.fr',
    ],
    [
        'id' => 4,
        'username' => 'enseignant',
        'email' => 'toto@free.fr',
    ],
];

$result = (new \local_iliasmigration\phase7_identity_policy())->resolve(
    $source,
    $target
);

$checks = [
    ($result['6']['status'] ?? '') === 'NOT_IN_TARGET',
    ($result['401']['status'] ?? '') === 'AMBIGUOUS',
    ($result['402']['status'] ?? '') === 'AMBIGUOUS',
    ($result['410']['status'] ?? '') === 'AMBIGUOUS',
    ($result['401']['reason'] ?? '') === 'SOURCE_EMAIL_NOT_UNIQUE',
    ($result['401']['target_user_id'] ?? null) === null,
];

foreach ($checks as $ok) {
    if (!$ok) {
        fwrite(STDERR, "Phase 7 identity policy regression failed.\n");
        exit(1);
    }
}

echo "PHASE7_IDENTITY_POLICY_OK\n";

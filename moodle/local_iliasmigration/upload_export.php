<?php

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/iliasmigration:operate', $context);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(new moodle_url('/local/iliasmigration/index.php'));
}

require_sesskey();

$upload = isset($_FILES['exportzip']) && is_array($_FILES['exportzip'])
    ? $_FILES['exportzip']
    : [];

try {
    $result = (new \local_iliasmigration\operator_artifact_manager())
        ->upload_export($upload);

    \core\notification::success(
        get_string(
            'exportuploadsuccess',
            'local_iliasmigration',
            (object) [
                'name' => $result['name'],
                'size' => display_size((int) $result['size']),
            ]
        )
    );
} catch (Throwable $exception) {
    \core\notification::error($exception->getMessage());
}

redirect(new moodle_url('/local/iliasmigration/index.php'));

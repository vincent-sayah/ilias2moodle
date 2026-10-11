<?php

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/iliasmigration:operate', $context);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(new moodle_url('/local/iliasmigration/index.php'));
}

require_sesskey();

$name = required_param('packagename', PARAM_FILE);

try {
    (new \local_iliasmigration\operator_artifact_manager())
        ->delete_package($name);

    \core\notification::success(
        get_string('packagedeletedsuccess', 'local_iliasmigration', $name)
    );
} catch (Throwable $exception) {
    \core\notification::error($exception->getMessage());
}

redirect(new moodle_url('/local/iliasmigration/index.php'));

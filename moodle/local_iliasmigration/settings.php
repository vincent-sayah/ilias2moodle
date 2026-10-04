<?php

defined('MOODLE_INTERNAL') || die();

// admin_externalpage performs its own capability check. Register the page for
// users with local/iliasmigration:manage, not only for site administrators
// holding moodle/site:config.
$ADMIN->add(
    'localplugins',
    new admin_externalpage(
        'local_iliasmigration_operator',
        get_string('operatorconsole', 'local_iliasmigration'),
        new moodle_url('/local/iliasmigration/operator.php'),
        'local/iliasmigration:manage'
    )
);

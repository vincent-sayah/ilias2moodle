<?php

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add(
        'localplugins',
        new admin_externalpage(
            'local_iliasmigration_console',
            get_string('operatorconsole', 'local_iliasmigration'),
            new moodle_url('/local/iliasmigration/index.php'),
            'local/iliasmigration:operate'
        )
    );
}

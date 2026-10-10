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

    $settings = new admin_settingpage(
        'local_iliasmigration_settings',
        get_string('operatorsettings', 'local_iliasmigration')
    );

    $settings->add(new admin_setting_configtext(
        'local_iliasmigration/projectroot',
        get_string('projectroot', 'local_iliasmigration'),
        get_string('projectroot_desc', 'local_iliasmigration'),
        '/opt/ilias2moodle',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_iliasmigration/importsroot',
        get_string('importsroot', 'local_iliasmigration'),
        get_string('importsroot_desc', 'local_iliasmigration'),
        '/var/moodledata/ilias2moodle/imports',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_iliasmigration/packagesroot',
        get_string('packagesroot', 'local_iliasmigration'),
        get_string('packagesroot_desc', 'local_iliasmigration'),
        '/var/moodledata/ilias2moodle/packages',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_iliasmigration/recoveriesroot',
        get_string('recoveriesroot', 'local_iliasmigration'),
        get_string('recoveriesroot_desc', 'local_iliasmigration'),
        '/var/moodledata/ilias2moodle/recovery',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_iliasmigration/iliasversion',
        get_string('iliasversion', 'local_iliasmigration'),
        get_string('iliasversion_desc', 'local_iliasmigration'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $ADMIN->add('localplugins', $settings);
}

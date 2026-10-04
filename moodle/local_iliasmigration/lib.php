<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Add the operator console to navigation for users allowed to operate it.
 */
function local_iliasmigration_extend_navigation(
    global_navigation $navigation
): void {
    $context = context_system::instance();

    if (!has_capability('local/iliasmigration:operate', $context)) {
        return;
    }

    $navigation->add(
        get_string('operatorconsole', 'local_iliasmigration'),
        new moodle_url('/local/iliasmigration/index.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_iliasmigration_operatorconsole'
    );
}

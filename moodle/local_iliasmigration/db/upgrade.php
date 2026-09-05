<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade local_iliasmigration.
 *
 * @param int $oldversion Previously installed plugin version.
 * @return bool
 */
function xmldb_local_iliasmigration_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026090501) {
        $table = new xmldb_table('local_iliasmigration_map');

        $field = new xmldb_field(
            'sourceinstance',
            XMLDB_TYPE_CHAR,
            '191',
            null,
            XMLDB_NOTNULL,
            null,
            '',
            'sourcelms'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $oldkey = new xmldb_key(
            'source_target_unique',
            XMLDB_KEY_UNIQUE,
            ['sourcelms', 'sourcecourse', 'sourceref', 'targettype']
        );
        if ($dbman->find_key_name($table, $oldkey)) {
            $dbman->drop_key($table, $oldkey);
        }

        $newkey = new xmldb_key(
            'source_target_unique',
            XMLDB_KEY_UNIQUE,
            ['sourcelms', 'sourceinstance', 'sourcecourse', 'sourceref', 'targettype']
        );
        if (!$dbman->find_key_name($table, $newkey)) {
            $dbman->add_key($table, $newkey);
        }

        upgrade_plugin_savepoint(true, 2026090501, 'local', 'iliasmigration');
    }

    return true;
}

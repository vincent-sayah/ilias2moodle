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
        if ($dbman->find_key_name($table, $newkey)) {
            $dbman->drop_key($table, $newkey);
        }

        $field = new xmldb_field(
            'sourceinstance',
            XMLDB_TYPE_CHAR,
            '128',
            null,
            XMLDB_NOTNULL,
            null,
            '',
            'sourcelms'
        );

        if ($dbman->field_exists($table, $field)) {
            $lengthsql = $DB->sql_length('sourceinstance');
            if ($DB->record_exists_select(
                'local_iliasmigration_map',
                $lengthsql . ' > ?',
                [128]
            )) {
                throw new coding_exception(
                    'Cannot reduce local_iliasmigration_map.sourceinstance to 128 characters: '
                    . 'at least one existing value is longer.'
                );
            }
            $dbman->change_field_precision($table, $field);
        } else {
            $dbman->add_field($table, $field);
        }

        $dbman->add_key($table, $newkey);

        upgrade_plugin_savepoint(true, 2026090501, 'local', 'iliasmigration');
    }

    if ($oldversion >= 2026090501 && $oldversion < 2026092605) {
        $table = new xmldb_table('local_iliasmigration_map');

        $key = new xmldb_key(
            'source_target_unique',
            XMLDB_KEY_UNIQUE,
            ['sourcelms', 'sourceinstance', 'sourcecourse', 'sourceref', 'targettype']
        );
        if ($dbman->find_key_name($table, $key)) {
            $dbman->drop_key($table, $key);
        }

        $field = new xmldb_field(
            'sourceinstance',
            XMLDB_TYPE_CHAR,
            '128',
            null,
            XMLDB_NOTNULL,
            null,
            '',
            'sourcelms'
        );

        $lengthsql = $DB->sql_length('sourceinstance');
        if ($DB->record_exists_select(
            'local_iliasmigration_map',
            $lengthsql . ' > ?',
            [128]
        )) {
            throw new coding_exception(
                'Cannot reduce local_iliasmigration_map.sourceinstance to 128 characters: '
                . 'at least one existing value is longer.'
            );
        }

        $dbman->change_field_precision($table, $field);
        $dbman->add_key($table, $key);

        upgrade_plugin_savepoint(true, 2026092605, 'local', 'iliasmigration');
    }

    return true;
}

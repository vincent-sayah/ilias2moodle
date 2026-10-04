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


    if ($oldversion < 2026100402) {
        $table = new xmldb_table('local_iliasmigration_run');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('status', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'READY');
            $table->add_field('sourcepath', XMLDB_TYPE_CHAR, '512', null, XMLDB_NOTNULL);
            $table->add_field('sourcehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
            $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, null);
            $table->add_field('categorypath', XMLDB_TYPE_CHAR, '255', null, null);
            $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('currentstep', XMLDB_TYPE_CHAR, '64', null, null);
            $table->add_field('summaryjson', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('createdby_idx', XMLDB_INDEX_NOTUNIQUE, ['createdby']);
            $table->add_index('created_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_iliasmigration_step');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('runid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('stepkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('position', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('status', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'PENDING');
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('ignored', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('resultjson', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('timestarted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key(
                'run_fk',
                XMLDB_KEY_FOREIGN,
                ['runid'],
                'local_iliasmigration_run',
                ['id']
            );
            $table->add_key('run_step_unique', XMLDB_KEY_UNIQUE, ['runid', 'stepkey']);
            $table->add_index('run_status_idx', XMLDB_INDEX_NOTUNIQUE, ['runid', 'status']);
            $table->add_index('position_idx', XMLDB_INDEX_NOTUNIQUE, ['runid', 'position']);

            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_iliasmigration_log');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('runid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('stepid', XMLDB_TYPE_INTEGER, '10', null, null);
            $table->add_field('level', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'INFO');
            $table->add_field('event', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('message', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $table->add_field('contextjson', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key(
                'run_fk',
                XMLDB_KEY_FOREIGN,
                ['runid'],
                'local_iliasmigration_run',
                ['id']
            );
            $table->add_index('run_time_idx', XMLDB_INDEX_NOTUNIQUE, ['runid', 'timecreated']);
            $table->add_index('step_idx', XMLDB_INDEX_NOTUNIQUE, ['stepid']);
            $table->add_index('level_idx', XMLDB_INDEX_NOTUNIQUE, ['level']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026100402, 'local', 'iliasmigration');
    }

    return true;
}

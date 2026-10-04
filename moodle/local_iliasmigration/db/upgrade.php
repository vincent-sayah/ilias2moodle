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


    if ($oldversion < 2026100401) {
        $job = new xmldb_table('local_iliasmigration_job');
        $job->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $job->add_field('status', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, 'NEW');
        $job->add_field('failurepolicy', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'pause');
        $job->add_field('sourcefilename', XMLDB_TYPE_CHAR, '255');
        $job->add_field('sourcehash', XMLDB_TYPE_CHAR, '64');
        $job->add_field('packagepath', XMLDB_TYPE_TEXT);
        $job->add_field('migrationjson', XMLDB_TYPE_TEXT);
        $job->add_field('categorypath', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $job->add_field('categoryid', XMLDB_TYPE_INTEGER, '10');
        $job->add_field('courseid', XMLDB_TYPE_INTEGER, '10');
        $job->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $job->add_field('summaryjson', XMLDB_TYPE_TEXT);
        $job->add_field('lasterror', XMLDB_TYPE_TEXT);
        $job->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $job->add_field('timestarted', XMLDB_TYPE_INTEGER, '10');
        $job->add_field('timefinished', XMLDB_TYPE_INTEGER, '10');
        $job->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $job->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $job->add_key('createdby_fk', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        $job->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $job->add_index('createdby_idx', XMLDB_INDEX_NOTUNIQUE, ['createdby']);
        if (!$dbman->table_exists($job)) {
            $dbman->create_table($job);
        }

        $step = new xmldb_table('local_iliasmigration_step');
        $step->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $step->add_field('jobid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $step->add_field('sequence', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $step->add_field('stepkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $step->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $step->add_field('status', XMLDB_TYPE_CHAR, '24', null, XMLDB_NOTNULL, null, 'PENDING');
        $step->add_field('critical', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $step->add_field('skippable', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $step->add_field('ignored', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $step->add_field('attempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $step->add_field('contextjson', XMLDB_TYPE_TEXT);
        $step->add_field('resultjson', XMLDB_TYPE_TEXT);
        $step->add_field('errormessage', XMLDB_TYPE_TEXT);
        $step->add_field('timestarted', XMLDB_TYPE_INTEGER, '10');
        $step->add_field('timefinished', XMLDB_TYPE_INTEGER, '10');
        $step->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $step->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $step->add_key('job_fk', XMLDB_KEY_FOREIGN, ['jobid'], 'local_iliasmigration_job', ['id']);
        $step->add_key('job_step_unique', XMLDB_KEY_UNIQUE, ['jobid', 'stepkey']);
        $step->add_index('job_status_idx', XMLDB_INDEX_NOTUNIQUE, ['jobid', 'status']);
        $step->add_index('job_sequence_idx', XMLDB_INDEX_NOTUNIQUE, ['jobid', 'sequence']);
        if (!$dbman->table_exists($step)) {
            $dbman->create_table($step);
        }

        $log = new xmldb_table('local_iliasmigration_log');
        $log->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $log->add_field('jobid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $log->add_field('stepid', XMLDB_TYPE_INTEGER, '10');
        $log->add_field('level', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'INFO');
        $log->add_field('eventcode', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $log->add_field('message', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $log->add_field('contextjson', XMLDB_TYPE_TEXT);
        $log->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $log->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $log->add_key('job_fk', XMLDB_KEY_FOREIGN, ['jobid'], 'local_iliasmigration_job', ['id']);
        $log->add_key('step_fk', XMLDB_KEY_FOREIGN, ['stepid'], 'local_iliasmigration_step', ['id']);
        $log->add_index('job_time_idx', XMLDB_INDEX_NOTUNIQUE, ['jobid', 'timecreated']);
        $log->add_index('level_idx', XMLDB_INDEX_NOTUNIQUE, ['level']);
        if (!$dbman->table_exists($log)) {
            $dbman->create_table($log);
        }

        upgrade_plugin_savepoint(true, 2026100401, 'local', 'iliasmigration');
    }

    return true;
}

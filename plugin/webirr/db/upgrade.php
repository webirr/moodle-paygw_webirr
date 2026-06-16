<?php
// This file is part of WeBirr Moodle Payment Gateway.
//
// WeBirr Moodle Payment Gateway is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// WeBirr Moodle Payment Gateway is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for the WeBirr payment gateway.
 *
 * @param int $oldversion Previously installed plugin version.
 * @return bool
 */
function xmldb_paygw_webirr_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026061401) {
        $table = new xmldb_table('paygw_webirr_payments');
        $field = new xmldb_field('paymentid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'status');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $index = new xmldb_index('paymentid', XMLDB_INDEX_NOTUNIQUE, ['paymentid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026061401, 'paygw', 'webirr');
    }

    if ($oldversion < 2026061402) {
        $table = new xmldb_table('paygw_webirr_payments');

        $referencefield = new xmldb_field(
            'paymentreference',
            XMLDB_TYPE_CHAR,
            '255',
            null,
            null,
            null,
            null,
            'paymentid'
        );
        if (!$dbman->field_exists($table, $referencefield)) {
            $dbman->add_field($table, $referencefield);
        }

        $issuerfield = new xmldb_field(
            'paymentissuer',
            XMLDB_TYPE_CHAR,
            '255',
            null,
            null,
            null,
            null,
            'paymentreference'
        );
        if (!$dbman->field_exists($table, $issuerfield)) {
            $dbman->add_field($table, $issuerfield);
        }

        upgrade_plugin_savepoint(true, 2026061402, 'paygw', 'webirr');
    }

    if ($oldversion < 2026061500) {
        $table = new xmldb_table('paygw_webirr_payments');

        $duplicates = $DB->get_records_sql(
            "SELECT billreference, COUNT(1) AS duplicatecount
               FROM {paygw_webirr_payments}
           GROUP BY billreference
             HAVING COUNT(1) > 1"
        );

        foreach ($duplicates as $duplicate) {
            $records = $DB->get_records(
                'paygw_webirr_payments',
                ['billreference' => $duplicate->billreference],
                'timemodified DESC, id DESC'
            );

            $keepfirst = true;
            foreach ($records as $record) {
                if ($keepfirst) {
                    $keepfirst = false;
                    continue;
                }

                $suffix = '_duplicate_' . $record->id;
                $record->billreference = substr((string)$record->billreference, 0, 255 - strlen($suffix)) . $suffix;
                $record->timemodified = time();
                $DB->update_record('paygw_webirr_payments', $record);
            }
        }

        $oldindex = new xmldb_index('billreference', XMLDB_INDEX_NOTUNIQUE, ['billreference']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }

        $uniqueindex = new xmldb_index('billreference', XMLDB_INDEX_UNIQUE, ['billreference']);
        if (!$dbman->index_exists($table, $uniqueindex)) {
            $dbman->add_index($table, $uniqueindex);
        }

        upgrade_plugin_savepoint(true, 2026061500, 'paygw', 'webirr');
    }

    return true;
}

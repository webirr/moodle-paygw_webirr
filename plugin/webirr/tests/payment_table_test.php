<?php

namespace paygw_webirr;

defined('MOODLE_INTERNAL') || die();

/**
 * Local payment table contract tests.
 *
 * @package    paygw_webirr
 * @copyright  2026 WeBirr
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class payment_table_test extends \advanced_testcase {
    /**
     * Bill references are the local idempotency key for WeBirr checkout state.
     */
    public function test_billreference_has_unique_index(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('paygw_webirr_payments');
        $index = new \xmldb_index('billreference', XMLDB_INDEX_UNIQUE, ['billreference']);

        $this->assertTrue($dbman->index_exists($table, $index));
    }

    /**
     * A duplicate bill reference must not create another local payment row.
     */
    public function test_duplicate_billreference_is_rejected(): void {
        global $DB;

        $this->resetAfterTest();

        $record = self::payment_record('moodle_component_area_1_42_7');
        $DB->insert_record('paygw_webirr_payments', $record);

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('paygw_webirr_payments', self::payment_record('moodle_component_area_1_42_7'));
    }

    /**
     * Build a minimal local payment record.
     *
     * @param string $billreference Merchant bill reference.
     * @return \stdClass Payment table record.
     */
    private static function payment_record(string $billreference): \stdClass {
        $record = new \stdClass();
        $record->userid = 2;
        $record->component = 'mod_example';
        $record->paymentarea = 'checkout';
        $record->itemid = 1;
        $record->billreference = $billreference;
        $record->wbc_code = '123 456 789';
        $record->amount = 530.00;
        $record->currency = 'ETB';
        $record->status = 0;
        $record->timecreated = time();
        $record->timemodified = time();

        return $record;
    }
}

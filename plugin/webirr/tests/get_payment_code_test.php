<?php

namespace paygw_webirr\external;

defined('MOODLE_INTERNAL') || die();

use core_payment\helper;
use paygw_webirr\local\webirr_client;

/**
 * Checkout code state-machine tests.
 *
 * @package    paygw_webirr
 * @copyright  2026 WeBirr
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @runTestsInSeparateProcesses
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class get_payment_code_test extends \advanced_testcase {
    /**
     * Reset the default test transport.
     */
    protected function tearDown(): void {
        webirr_client::set_test_transport(null);
        parent::tearDown();
    }

    /**
     * A missing remote bill should create exactly one local checkout record.
     */
    public function test_missing_remote_bill_creates_bill_and_local_record(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Elias', 'lastname' => 'Haileselassie']);
        $this->setUser($user);
        $instanceid = $this->create_fee_enrolment_instance(530.00);
        $requests = [];

        webirr_client::set_test_transport(function(
            string $method,
            string $url,
            ?array $payload,
            array $headers
        ) use (&$requests): array {
            $requests[] = compact('method', 'url', 'payload', 'headers');

            if (strpos($url, 'einvoice/api/banks') !== false) {
                return [
                    'status' => 200,
                    'body' => '{"error":null,"res":[{"bankID":"cbe_mobile","name":"CBE Mobile Banking"}],"errorCode":null}',
                    'error' => '',
                ];
            }

            if ($method === 'GET') {
                return [
                    'status' => 200,
                    'body' => '{"error":"Bill not found","res":null,"errorCode":"ERROR_INVLAID_INPUT"}',
                    'error' => '',
                ];
            }

            return [
                'status' => 200,
                'body' => '{"error":null,"res":"123 456 789","errorCode":null}',
                'error' => '',
            ];
        });

        $response = get_payment_code::execute('enrol_fee', 'fee', $instanceid, 'moodle course enrollment');

        $this->assertTrue($response['success']);
        $this->assertSame('123 456 789', $response['paymentcode']);
        $this->assertSame('cbe_mobile', $response['supportedbanks'][0]['bankid']);
        $this->assertSame('CBE Mobile Banking', $response['supportedbanks'][0]['name']);
        $this->assertCount(3, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertSame('POST', $requests[1]['method']);
        $this->assertSame('GET', $requests[2]['method']);
        $this->assertStringContainsString('bill_reference=' . rawurlencode($response['billreference']), $requests[0]['url']);
        $this->assertSame($response['billreference'], $requests[1]['payload']['billReference']);
        $this->assertSame('test-merchant-id', $requests[1]['payload']['merchantID']);
        $this->assertSame('530.00', $requests[1]['payload']['amount']);
        $this->assertStringContainsString('einvoice/api/banks', $requests[2]['url']);

        $record = $DB->get_record('paygw_webirr_payments', ['id' => $response['paymentid']], '*', MUST_EXIST);
        $this->assertSame($response['billreference'], $record->billreference);
        $this->assertSame('123 456 789', $record->wbc_code);
        $this->assertEquals(530.00, (float)$record->amount);
        $this->assertSame('ETB', $record->currency);
    }

    /**
     * An existing local pending payment should be reused without a bill/status gateway call when payable details match.
     */
    public function test_existing_local_payment_is_reused_without_bill_gateway_call(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Elias', 'lastname' => 'Haileselassie']);
        $this->setUser($user);
        $instanceid = $this->create_fee_enrolment_instance(530.00);
        $billreference = $this->expected_bill_reference('enrol_fee', 'fee', $instanceid, (int)$user->id);
        $paymentid = $this->insert_payment_record($user->id, $instanceid, $billreference, '123 456 789', 530.00, 'ETB', 0);
        $requests = [];

        webirr_client::set_test_transport(function(
            string $method,
            string $url,
            ?array $payload,
            array $headers
        ) use (&$requests): array {
            $requests[] = compact('method', 'url', 'payload', 'headers');

            return [
                'status' => 200,
                'body' => '{"error":null,"res":[{"bankID":"telebirr","name":"Telebirr"}],"errorCode":null}',
                'error' => '',
            ];
        });

        $response = get_payment_code::execute('enrol_fee', 'fee', $instanceid, 'moodle course enrollment');

        $this->assertTrue($response['success']);
        $this->assertSame('123 456 789', $response['paymentcode']);
        $this->assertSame($paymentid, $response['paymentid']);
        $this->assertSame($billreference, $response['billreference']);
        $this->assertSame('telebirr', $response['supportedbanks'][0]['bankid']);
        $this->assertCount(1, $requests);
        $this->assertStringContainsString('einvoice/api/banks', $requests[0]['url']);
        $this->assertCount(1, $DB->get_records('paygw_webirr_payments', ['billreference' => $billreference]));
    }

    /**
     * A remote bill with no local row should be recovered into local Moodle checkout state.
     */
    public function test_remote_bill_is_recovered_when_local_record_is_missing(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Elias', 'lastname' => 'Haileselassie']);
        $this->setUser($user);
        $instanceid = $this->create_fee_enrolment_instance(530.00);
        $requests = [];

        webirr_client::set_test_transport(function(
            string $method,
            string $url,
            ?array $payload,
            array $headers
        ) use (&$requests): array {
            $requests[] = compact('method', 'url', 'payload', 'headers');

            if (strpos($url, 'einvoice/api/banks') !== false) {
                return [
                    'status' => 200,
                    'body' => '{"error":null,"res":[{"bankID":"awash_birr","name":"Awash Mobile Wallet | Awash Birr"}],"errorCode":null}',
                    'error' => '',
                ];
            }

            return [
                'status' => 200,
                'body' => '{"error":null,"res":{"wbcCode":"222333444","paymentStatus":0,"amount":"530","customerName":"Elias Haileselassie","customerPhone":"","description":"moodle course enrollment"},"errorCode":null}',
                'error' => '',
            ];
        });

        $response = get_payment_code::execute('enrol_fee', 'fee', $instanceid, 'moodle course enrollment');

        $this->assertTrue($response['success']);
        $this->assertSame('222333444', $response['paymentcode']);
        $this->assertSame('awash_birr', $response['supportedbanks'][0]['bankid']);
        $this->assertCount(2, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertStringContainsString('einvoice/api/banks', $requests[1]['url']);

        $record = $DB->get_record('paygw_webirr_payments', ['id' => $response['paymentid']], '*', MUST_EXIST);
        $this->assertSame('222333444', $record->wbc_code);
        $this->assertSame(0, (int)$record->status);
    }

    /**
     * Changed payable details on an unpaid local payment should update the WeBirr bill.
     */
    public function test_changed_unpaid_local_payment_updates_remote_bill(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Elias', 'lastname' => 'Haileselassie']);
        $this->setUser($user);
        $instanceid = $this->create_fee_enrolment_instance(530.00);
        $billreference = $this->expected_bill_reference('enrol_fee', 'fee', $instanceid, (int)$user->id);
        $paymentid = $this->insert_payment_record($user->id, $instanceid, $billreference, '123 456 789', 100.00, 'ETB', 0);
        $requests = [];

        webirr_client::set_test_transport(function(
            string $method,
            string $url,
            ?array $payload,
            array $headers
        ) use (&$requests): array {
            $requests[] = compact('method', 'url', 'payload', 'headers');

            if (strpos($url, 'einvoice/api/banks') !== false) {
                return [
                    'status' => 200,
                    'body' => '{"error":null,"res":[{"bankID":"m_pesa","name":"M-Pesa Safaricom"}],"errorCode":null}',
                    'error' => '',
                ];
            }

            if ($method === 'GET') {
                return [
                    'status' => 200,
                    'body' => '{"error":null,"res":{"status":0},"errorCode":null}',
                    'error' => '',
                ];
            }

            return [
                'status' => 200,
                'body' => '{"error":null,"res":"Ok","errorCode":null}',
                'error' => '',
            ];
        });

        $response = get_payment_code::execute('enrol_fee', 'fee', $instanceid, 'moodle course enrollment');

        $this->assertTrue($response['success']);
        $this->assertSame($paymentid, $response['paymentid']);
        $this->assertSame('m_pesa', $response['supportedbanks'][0]['bankid']);
        $this->assertCount(3, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertSame('PUT', $requests[1]['method']);
        $this->assertStringContainsString('einvoice/api/banks', $requests[2]['url']);
        $this->assertSame('530.00', $requests[1]['payload']['amount']);

        $record = $DB->get_record('paygw_webirr_payments', ['id' => $paymentid], '*', MUST_EXIST);
        $this->assertEquals(530.00, (float)$record->amount);
        $this->assertSame(0, (int)$record->status);
    }

    /**
     * Changed payable details must not update a bill that is already paid.
     */
    public function test_changed_paid_local_payment_does_not_update_remote_bill(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Elias', 'lastname' => 'Haileselassie']);
        $this->setUser($user);
        $instanceid = $this->create_fee_enrolment_instance(530.00);
        $billreference = $this->expected_bill_reference('enrol_fee', 'fee', $instanceid, (int)$user->id);
        $paymentid = $this->insert_payment_record($user->id, $instanceid, $billreference, '123 456 789', 100.00, 'ETB', 0);
        $requests = [];

        webirr_client::set_test_transport(function(
            string $method,
            string $url,
            ?array $payload,
            array $headers
        ) use (&$requests): array {
            $requests[] = compact('method', 'url', 'payload', 'headers');

            if (strpos($url, 'einvoice/api/banks') !== false) {
                return [
                    'status' => 200,
                    'body' => '{"error":null,"res":[{"bankID":"coopay_ebirr","name":"Coopay Ebirr | Cooperative Bank of Oromia"}],"errorCode":null}',
                    'error' => '',
                ];
            }

            return [
                'status' => 200,
                'body' => '{"error":null,"res":{"status":2},"errorCode":null}',
                'error' => '',
            ];
        });

        $response = get_payment_code::execute('enrol_fee', 'fee', $instanceid, 'moodle course enrollment');

        $this->assertTrue($response['success']);
        $this->assertSame($paymentid, $response['paymentid']);
        $this->assertSame('coopay_ebirr', $response['supportedbanks'][0]['bankid']);
        $this->assertCount(2, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertStringContainsString('einvoice/api/banks', $requests[1]['url']);

        $record = $DB->get_record('paygw_webirr_payments', ['id' => $paymentid], '*', MUST_EXIST);
        $this->assertEquals(100.00, (float)$record->amount);
        $this->assertSame(2, (int)$record->status);
    }

    /**
     * Transport errors during recovery should not create a duplicate remote bill.
     */
    public function test_transport_error_during_recovery_returns_failure_without_create(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Elias', 'lastname' => 'Haileselassie']);
        $this->setUser($user);
        $instanceid = $this->create_fee_enrolment_instance(530.00);
        $requests = [];

        webirr_client::set_test_transport(function(
            string $method,
            string $url,
            ?array $payload,
            array $headers
        ) use (&$requests): array {
            $requests[] = compact('method', 'url', 'payload', 'headers');

            return [
                'status' => 500,
                'body' => 'server error',
                'error' => '',
            ];
        });

        $response = get_payment_code::execute('enrol_fee', 'fee', $instanceid, 'moodle course enrollment');

        $this->assertFalse($response['success']);
        $this->assertSame('http error 500', $response['error']);
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0]['method']);
    }

    /**
     * Create a fee enrolment instance backed by a configured WeBirr payment account.
     *
     * @param float $amount Payable amount.
     * @return int Enrolment instance id.
     */
    private function create_fee_enrolment_instance(float $amount): int {
        global $DB;

        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $account = $this->getDataGenerator()->get_plugin_generator('core_payment')->create_payment_account();
        helper::save_payment_gateway((object)[
            'accountid' => $account->get('id'),
            'gateway' => 'webirr',
            'enabled' => 1,
            'config' => json_encode([
                'merchantid' => 'test-merchant-id',
                'apikey' => 'test-api-key',
                'testmode' => true,
            ]),
        ]);

        $course = $this->getDataGenerator()->create_course();

        return enrol_get_plugin('fee')->add_instance($course, [
            'courseid' => $course->id,
            'customint1' => $account->get('id'),
            'cost' => $amount,
            'currency' => 'ETB',
            'roleid' => $studentrole->id,
        ]);
    }

    /**
     * Insert a local WeBirr payment record.
     *
     * @param int $userid User id.
     * @param int $itemid Payable item id.
     * @param string $billreference Stable bill reference.
     * @param string $paymentcode WeBirr payment code.
     * @param float $amount Stored amount.
     * @param string $currency Stored currency.
     * @param int $status Stored payment status.
     * @return int Payment record id.
     */
    private function insert_payment_record(
        int $userid,
        int $itemid,
        string $billreference,
        string $paymentcode,
        float $amount,
        string $currency,
        int $status
    ): int {
        global $DB;

        return (int)$DB->insert_record('paygw_webirr_payments', (object)[
            'userid' => $userid,
            'component' => 'enrol_fee',
            'paymentarea' => 'fee',
            'itemid' => $itemid,
            'billreference' => $billreference,
            'wbc_code' => $paymentcode,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Build the expected stable bill reference for a test payable.
     *
     * @param string $component Moodle component.
     * @param string $paymentarea Payment area.
     * @param int $itemid Payable item id.
     * @param int $userid User id.
     * @return string Expected bill reference.
     */
    private function expected_bill_reference(string $component, string $paymentarea, int $itemid, int $userid): string {
        $payable = helper::get_payable($component, $paymentarea, $itemid);

        return 'moodle_' . $component . '_' . $paymentarea . '_' . $itemid . '_' . $userid . '_' .
            $payable->get_account_id();
    }
}

<?php
namespace paygw_webirr\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use paygw_webirr\local\webirr_client;

class get_payment_code extends external_api {

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'component' => new external_value(PARAM_COMPONENT, 'Component'),
            'paymentarea' => new external_value(PARAM_AREA, 'Payment area in the component'),
            'itemid' => new external_value(PARAM_INT, 'An identifier for payment area in the component'),
            'description' => new external_value(PARAM_TEXT, 'Description of the payment')
        ]);
    }

    /**
     * Creates a WeBirr payment code
     *
     * @param string $component Component
     * @param string $paymentarea Payment area in the component
     * @param int $itemid An identifier for payment area in the component
     * @param string $description Description of the payment
     * @return array
     */
    public static function execute($component, $paymentarea, $itemid, $description) {
        global $USER, $DB;
        
        $params = self::validate_parameters(self::execute_parameters(), [
            'component' => $component,
            'paymentarea' => $paymentarea,
            'itemid' => $itemid,
            'description' => $description
        ]);
        
        $component = $params['component'];
        $paymentarea = $params['paymentarea'];
        $itemid = $params['itemid'];
        $description = $params['description'];

        self::validate_context(\context_system::instance());
        
        // Get the payment record.
        $payable = \core_payment\helper::get_payable($component, $paymentarea, $itemid);
        $amount = $payable->get_amount();
        $currency = $payable->get_currency();
        $accountid = $payable->get_account_id();
        
        // Get the gateway configuration.
        $account = new \core_payment\account($accountid);
        $gateways = $account->get_gateways(false);
        $gateway = $gateways['webirr'] ?? null;
        if (!$gateway || !$gateway->get('enabled')) {
            throw new \moodle_exception('gatewaynotavailable', 'paygw_webirr');
        }
        
        $config = $gateway->get_configuration();

        if (empty($config['apikey']) || empty($config['merchantid'])) {
            return [
                'success' => false,
                'error' => get_string('gatewaynotconfigured', 'paygw_webirr')
            ];
        }

        // Build the stable Moodle payable reference. This must stay
        // deterministic so browser refreshes and interrupted sessions can reuse
        // or recover the same WeBirr bill.
        $billreference = self::build_bill_reference(
            $component,
            $paymentarea,
            (int)$itemid,
            (int)$USER->id,
            (int)$accountid
        );

        // Create a WeBirr client.
        $isTestEnv = isset($config['testmode']) ? (bool)$config['testmode'] : true;
        $client = new webirr_client($config['merchantid'], $config['apikey'], $isTestEnv);

        $customerphone = '';
        if (!empty($USER->phone1)) {
            $customerphone = $USER->phone1;
        } else if (!empty($USER->phone2)) {
            $customerphone = $USER->phone2;
        }

        // Create a bill object for WeBirr.
        $bill = new \stdClass();
        $bill->amount = number_format((float)$amount, 2, '.', '');
        $bill->customerCode = (string)$USER->id;
        $bill->customerName = fullname($USER);
        $bill->customerPhone = $customerphone;
        $bill->time = date('Y-m-d H:i');
        $bill->description = $description;
        $bill->billReference = $billreference;

        $existing = self::find_existing_payment(
            $billreference,
            $component,
            $paymentarea,
            (int)$itemid,
            (int)$USER->id
        );
        if ($existing && !empty($existing->wbc_code)) {
            return self::reuse_existing_payment($existing, $bill, (float)$amount, $currency, $client);
        }

        // Recover from the case where WeBirr created the bill but Moodle did
        // not persist the payment code because the request was interrupted.
        $recovered = $client->get_bill_by_reference($billreference);
        if (empty($recovered->error)) {
            $paymentcode = self::extract_bill_payment_code($recovered);
            if ($paymentcode !== '') {
                $status = self::extract_bill_status($recovered);

                if ($status !== 2 && self::bill_details_changed($recovered, $bill)) {
                    $updated = $client->update_bill($bill);
                    if (!empty($updated->error)) {
                        return [
                            'success' => false,
                            'error' => $updated->error
                        ];
                    }
                }

                $record = self::insert_payment_record(
                    (int)$USER->id,
                    $component,
                    $paymentarea,
                    (int)$itemid,
                    $billreference,
                    $paymentcode,
                    (float)$amount,
                    $currency,
                    $status
                );

                return self::payment_code_response($paymentcode, (int)$record->id, $billreference, $client);
            }

            return [
                'success' => false,
                'error' => get_string('invalidresponse', 'paygw_webirr')
            ];
        } else if (self::is_transport_error($recovered->error)) {
            return [
                'success' => false,
                'error' => $recovered->error
            ];
        }

        // No recoverable WeBirr bill was found, so create it once.
        $result = $client->create_bill($bill);

        // Check if bill creation was successful.
        if (empty($result->error)) {
            $paymentcode = $result->res;

            $record = self::insert_payment_record(
                (int)$USER->id,
                $component,
                $paymentarea,
                (int)$itemid,
                $billreference,
                (string)$paymentcode,
                (float)$amount,
                $currency,
                0
            );

            return self::payment_code_response((string)$paymentcode, (int)$record->id, $billreference, $client);
        } else {
            return [
                'success' => false,
                'error' => $result->error
            ];
        }
    }

    /**
     * Returns description of method result value
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the payment code was created successfully'),
            'paymentcode' => new external_value(PARAM_TEXT, 'The WeBirr payment code', VALUE_OPTIONAL),
            'paymentid' => new external_value(PARAM_INT, 'The payment record ID', VALUE_OPTIONAL),
            'billreference' => new external_value(PARAM_TEXT, 'The merchant bill reference', VALUE_OPTIONAL),
            'supportedbanks' => new external_multiple_structure(
                new external_single_structure([
                    'bankid' => new external_value(PARAM_TEXT, 'The WeBirr bank or wallet ID'),
                    'name' => new external_value(PARAM_TEXT, 'Display name for the bank or wallet'),
                ]),
                'Banks and wallets configured for this merchant',
                VALUE_OPTIONAL
            ),
            'error' => new external_value(PARAM_TEXT, 'The error message if the payment code was not created', VALUE_OPTIONAL)
        ]);
    }

    /**
     * Build a stable Moodle merchant reference for the payable.
     *
     * @param string $component Moodle payment component.
     * @param string $paymentarea Moodle payment area.
     * @param int $itemid Moodle payable item id.
     * @param int $userid Moodle user id.
     * @param int $accountid Moodle payment account id.
     * @return string Stable merchant reference sent to WeBirr.
     */
    private static function build_bill_reference(
        string $component,
        string $paymentarea,
        int $itemid,
        int $userid,
        int $accountid
    ): string {
        return 'moodle_' . $component . '_' . $paymentarea . '_' . $itemid . '_' . $userid . '_' .
            $accountid;
    }

    /**
     * Find a local Moodle payment record to reuse.
     *
     * Prefer the deterministic bill reference. Fall back to the latest local
     * record for the same Moodle payable so in-progress pre-upgrade checkouts do
     * not get abandoned.
     *
     * @param string $billreference Deterministic bill reference.
     * @param string $component Moodle payment component.
     * @param string $paymentarea Moodle payment area.
     * @param int $itemid Moodle payable item id.
     * @param int $userid Moodle user id.
     * @return \stdClass|null Existing payment record.
     */
    private static function find_existing_payment(
        string $billreference,
        string $component,
        string $paymentarea,
        int $itemid,
        int $userid
    ): ?\stdClass {
        global $DB;

        $records = $DB->get_records(
            'paygw_webirr_payments',
            ['billreference' => $billreference],
            'timemodified DESC',
            '*',
            0,
            1
        );
        $record = reset($records);
        if ($record && !empty($record->wbc_code)) {
            return $record;
        }

        $records = $DB->get_records(
            'paygw_webirr_payments',
            [
                'userid' => $userid,
                'component' => $component,
                'paymentarea' => $paymentarea,
                'itemid' => $itemid,
            ],
            'timemodified DESC',
            '*',
            0,
            10
        );

        foreach ($records as $record) {
            if (!empty($record->wbc_code)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Reuse an existing local WeBirr payment record.
     *
     * @param \stdClass $record Existing Moodle payment record.
     * @param \stdClass $bill Current payable bill data.
     * @param float $amount Current payable amount.
     * @param string $currency Current payable currency.
     * @param webirr_client $client WeBirr client.
     * @return array External function response.
     */
    private static function reuse_existing_payment(
        \stdClass $record,
        \stdClass $bill,
        float $amount,
        string $currency,
        webirr_client $client
    ): array {
        global $DB;

        if ((int)$record->status !== 2 && self::local_payment_changed($record, $amount, $currency)) {
            $status = $client->get_payment_status((string)$record->wbc_code);
            if (!empty($status->error)) {
                return [
                    'success' => false,
                    'error' => $status->error
                ];
            }

            $statusvalue = self::extract_payment_status($status);
            if ($statusvalue === 2) {
                $record->status = 2;
            } else {
                $bill->billReference = $record->billreference;
                $updated = $client->update_bill($bill);
                if (!empty($updated->error)) {
                    return [
                        'success' => false,
                        'error' => $updated->error
                    ];
                }

                $record->amount = $amount;
                $record->currency = $currency;
                $record->status = $statusvalue;
            }

            $record->timemodified = time();
            $DB->update_record('paygw_webirr_payments', $record);
        }

        return self::payment_code_response(
            (string)$record->wbc_code,
            (int)$record->id,
            (string)$record->billreference,
            $client
        );
    }

    /**
     * Check whether the local stored payable amount/currency changed.
     *
     * @param \stdClass $record Existing Moodle payment record.
     * @param float $amount Current payable amount.
     * @param string $currency Current payable currency.
     * @return bool Whether the payable changed.
     */
    private static function local_payment_changed(\stdClass $record, float $amount, string $currency): bool {
        return abs((float)$record->amount - $amount) > 0.001 || (string)$record->currency !== $currency;
    }

    /**
     * Check whether recovered WeBirr bill details differ from Moodle's payable.
     *
     * @param \stdClass $result Gateway response for get bill.
     * @param \stdClass $bill Current payable bill data.
     * @return bool Whether unpaid bill should be updated.
     */
    private static function bill_details_changed(\stdClass $result, \stdClass $bill): bool {
        $amount = self::extract_bill_value($result, 'amount');
        if ($amount !== '' && abs((float)$amount - (float)$bill->amount) > 0.001) {
            return true;
        }

        foreach (['customerName', 'customerPhone', 'description'] as $key) {
            $current = (string)($bill->$key ?? '');
            $remote = self::extract_bill_value($result, $key);
            if ($remote !== '' && $remote !== $current) {
                return true;
            }
        }

        return false;
    }

    /**
     * Insert a local Moodle payment record.
     *
     * @param int $userid Moodle user id.
     * @param string $component Moodle payment component.
     * @param string $paymentarea Moodle payment area.
     * @param int $itemid Moodle payable item id.
     * @param string $billreference Merchant bill reference.
     * @param string $paymentcode WeBirr payment code.
     * @param float $amount Payable amount.
     * @param string $currency Payable currency.
     * @param int $status Local/gateway status value.
     * @return \stdClass Inserted payment record.
     */
    private static function insert_payment_record(
        int $userid,
        string $component,
        string $paymentarea,
        int $itemid,
        string $billreference,
        string $paymentcode,
        float $amount,
        string $currency,
        int $status
    ): \stdClass {
        global $DB;

        $record = new \stdClass();
        $record->userid = $userid;
        $record->component = $component;
        $record->paymentarea = $paymentarea;
        $record->itemid = $itemid;
        $record->billreference = $billreference;
        $record->wbc_code = $paymentcode;
        $record->amount = $amount;
        $record->currency = $currency;
        $record->status = $status;
        $record->timecreated = time();
        $record->timemodified = time();

        try {
            $record->id = $DB->insert_record('paygw_webirr_payments', $record);
        } catch (\dml_write_exception $exception) {
            $existing = self::find_existing_payment($billreference, $component, $paymentarea, $itemid, $userid);
            if ($existing && !empty($existing->wbc_code)) {
                return $existing;
            }

            throw $exception;
        }

        return $record;
    }

    /**
     * Build the external function response for an existing or new payment code.
     *
     * @param string $paymentcode WeBirr payment code.
     * @param int $paymentid Local Moodle payment record id.
     * @param string $billreference Merchant bill reference.
     * @param webirr_client $client WeBirr client.
     * @return array External function response.
     */
    private static function payment_code_response(
        string $paymentcode,
        int $paymentid,
        string $billreference,
        webirr_client $client
    ): array {
        return [
            'success' => true,
            'paymentcode' => $paymentcode,
            'paymentid' => $paymentid,
            'billreference' => $billreference,
            'supportedbanks' => self::supported_banks_response($client),
        ];
    }

    /**
     * Fetch and normalize merchant-supported banks for browser display.
     *
     * The payment code remains usable even if this optional display list cannot
     * be loaded, so failures return an empty list instead of failing checkout.
     *
     * @param webirr_client $client WeBirr client.
     * @return array[] Supported bank rows with bankid/name fields.
     */
    private static function supported_banks_response(webirr_client $client): array {
        $response = $client->get_supported_banks();
        if (!empty($response->error) || !isset($response->res) || !is_array($response->res)) {
            return [];
        }

        $banks = [];
        foreach ($response->res as $bank) {
            if (!is_object($bank)) {
                continue;
            }

            $bankid = trim((string)($bank->bankID ?? $bank->bankid ?? ''));
            $name = trim((string)($bank->name ?? ''));
            if ($bankid === '' || $name === '') {
                continue;
            }

            $banks[] = [
                'bankid' => $bankid,
                'name' => $name,
            ];
        }

        return self::sort_supported_banks($banks);
    }

    /**
     * Sort known payment channels in the same order used by WeBirr checkout docs.
     *
     * Unknown future channels are still displayed after the known channels so the
     * UI remains merchant-scoped without hiding server-supported banks.
     *
     * @param array[] $banks Supported bank rows with bankid/name fields.
     * @return array[] Sorted supported bank rows.
     */
    private static function sort_supported_banks(array $banks): array {
        $preferred = [
            'cbe_mobile' => 10,
            'cbe_birr' => 20,
            'awash_birr' => 30,
            'telebirr' => 40,
            'm_pesa' => 50,
            'coopay_ebirr' => 60,
        ];

        usort($banks, static function(array $left, array $right) use ($preferred): int {
            $leftid = (string)($left['bankid'] ?? '');
            $rightid = (string)($right['bankid'] ?? '');
            $leftweight = $preferred[$leftid] ?? 1000;
            $rightweight = $preferred[$rightid] ?? 1000;

            if ($leftweight !== $rightweight) {
                return $leftweight <=> $rightweight;
            }

            return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        });

        return $banks;
    }

    /**
     * Extract a payment status value from the single-status response.
     *
     * @param \stdClass $result Gateway payment-status response.
     * @return int Payment status.
     */
    private static function extract_payment_status(\stdClass $result): int {
        $nodes = [$result];
        if (isset($result->res) && is_object($result->res)) {
            $nodes[] = $result->res;
            if (isset($result->res->data) && is_object($result->res->data)) {
                $nodes[] = $result->res->data;
            }
        }

        foreach ($nodes as $node) {
            if (isset($node->status)) {
                return (int)$node->status;
            }
            if (isset($node->paymentStatus)) {
                return (int)$node->paymentStatus;
            }
        }

        return 0;
    }

    /**
     * Extract a bill status value from the get-bill response.
     *
     * @param \stdClass $result Gateway get-bill response.
     * @return int Payment status.
     */
    private static function extract_bill_status(\stdClass $result): int {
        foreach (['paymentStatus', 'status'] as $key) {
            $value = self::extract_bill_value($result, $key);
            if ($value !== '') {
                return (int)$value;
            }
        }

        return 0;
    }

    /**
     * Extract the WeBirr payment code from a get-bill response.
     *
     * @param \stdClass $result Gateway get-bill response.
     * @return string Payment code, or empty string when absent.
     */
    private static function extract_bill_payment_code(\stdClass $result): string {
        foreach (['wbcCode', 'paymentCode', 'wbc_code', 'paymentcode'] as $key) {
            $value = self::extract_bill_value($result, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Extract a scalar value from a get-bill response or its nested data node.
     *
     * @param \stdClass $result Gateway get-bill response.
     * @param string $key Field name.
     * @return string Extracted value.
     */
    private static function extract_bill_value(\stdClass $result, string $key): string {
        $nodes = [$result];
        if (isset($result->res) && is_object($result->res)) {
            $nodes[] = $result->res;
            if (isset($result->res->data) && is_object($result->res->data)) {
                $nodes[] = $result->res->data;
            }
        }

        foreach ($nodes as $node) {
            if (isset($node->$key)) {
                return trim((string)$node->$key);
            }
        }

        return '';
    }

    /**
     * Determine whether a lookup failed before the gateway could answer.
     *
     * @param string $error Gateway/client error message.
     * @return bool Whether create should be blocked.
     */
    private static function is_transport_error(string $error): bool {
        return preg_match('/^(http error|invalid response|Moodle curl class|Unable to encode)/i', $error) === 1;
    }
}

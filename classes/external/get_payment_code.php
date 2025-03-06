// classes/external/get_payment_code.php
<?php
namespace paygw_webirr\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/payment/gateway/webirr/lib/WeBirrClient.php');
require_once($CFG->dirroot . '/payment/gateway/webirr/lib/Bill.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use WeBirr\WeBirrClient;
use WeBirr\Bill;

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
        
        // Get the payment record.
        $payable = \core_payment\helper::get_payable($component, $paymentarea, $itemid);
        $amount = $payable->get_amount();
        $currency = $payable->get_currency();
        $accountid = $payable->get_account_id();
        
        // Get the gateway configuration.
        $account = new \core_payment\account($accountid);
        $gateway = $account->get_gateway_by_type('webirr');
        if (!$gateway) {
            throw new \moodle_exception('WeBirr gateway not available');
        }
        
        $config = (array)json_decode($gateway->get_gateway_configuration());
        
        // Create a unique bill reference
        $billreference = 'moodle_' . uniqid();
        
        // Create a WeBirr client
        $isTestEnv = isset($config['testmode']) ? (bool)$config['testmode'] : true;
        $client = new WeBirrClient($config['merchantid'], $config['apikey'], $isTestEnv);
        
        // Create a Bill object for WeBirr
        $bill = new Bill();
        $bill->amount = (string)$amount;
        $bill->customerCode = (string)$USER->id;
        $bill->customerName = fullname($USER);
        $bill->time = date('Y-m-d H:i:s');
        $bill->description = $description;
        $bill->billReference = $billreference;
        $bill->merchantID = $config['merchantid'];
       
        // Create a bill with WeBirr
        $result = $client->createBill($bill);
        
        // Check if bill creation was successful
        if (!isset($result->error)) {
            $paymentcode = $result->res;
            
            // Create a record in the database.
            $record = new \stdClass();
            $record->userid = $USER->id;
            $record->component = $component;
            $record->paymentarea = $paymentarea;
            $record->itemid = $itemid;
            $record->billreference = $billreference;
            $record->wbc_code = $paymentcode;
            $record->amount = $amount;
            $record->currency = $currency;
            $record->status = 0; // 0 = pending
            $record->timecreated = time();
            $record->timemodified = time();
            
            $record->id = $DB->insert_record('paygw_webirr_payments', $record);
            
            return [
                'success' => true,
                'paymentcode' => $paymentcode,
                'paymentid' => $record->id
            ];
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
            'error' => new external_value(PARAM_TEXT, 'The error message if the payment code was not created', VALUE_OPTIONAL)
        ]);
    }
}
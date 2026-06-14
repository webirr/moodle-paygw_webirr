<?php
namespace paygw_webirr\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use paygw_webirr\local\webirr_client;

class get_payment_status extends external_api {

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'paymentid' => new external_value(PARAM_INT, 'The payment record ID')
        ]);
    }

    /**
     * Checks the status of a WeBirr payment
     *
     * @param int $paymentid The payment record ID
     * @return array
     */
    public static function execute($paymentid) {
        global $USER, $DB;
        
        $params = self::validate_parameters(self::execute_parameters(), [
            'paymentid' => $paymentid
        ]);
        
        $paymentid = $params['paymentid'];

        self::validate_context(\context_system::instance());
        
        // Get the payment record.
        $payment = $DB->get_record('paygw_webirr_payments', ['id' => $paymentid], '*', MUST_EXIST);
        
        // Check if the payment belongs to the current user.
        if ($payment->userid != $USER->id) {
            throw new \moodle_exception('invaliduserid');
        }
        
        // Get the same payment account configuration used for the payable item.
        $payable = \core_payment\helper::get_payable($payment->component, $payment->paymentarea, $payment->itemid);
        $account = new \core_payment\account($payable->get_account_id());
        $gateway = $account->get_gateway_by_type('webirr');
        if (!$gateway) {
            throw new \moodle_exception('gatewaynotfound', 'payment');
        }

        // Get the WeBirr client configuration.
        $config = (array)json_decode($gateway->get_gateway_configuration(), true);
        if (empty($config['apikey']) || empty($config['merchantid'])) {
            return [
                'success' => false,
                'error' => 'WeBirr gateway is not configured'
            ];
        }

        // Create a WeBirr client.
        $isTestEnv = isset($config['testmode']) ? (bool)$config['testmode'] : true;
        $client = new webirr_client($config['merchantid'], $config['apikey'], $isTestEnv);

        // Check the payment status.
        $paymentStatus = $client->get_payment_status($payment->wbc_code);

        // Check if payment status check was successful.
        if (empty($paymentStatus->error)) {
            $paymentObj = $paymentStatus->res;
            $statusValue = 0; // Default pending.

            if (is_object($paymentObj) && isset($paymentObj->status)) {
                $statusValue = $paymentObj->status;
            } else if (is_object($paymentObj) && method_exists($paymentObj, 'IsPaid') && $paymentObj->IsPaid()) {
                $statusValue = 2; // Paid.
            }

            // Update the payment record if the status has changed.
            if ($statusValue != $payment->status) {
                $payment->status = $statusValue;
                $payment->timemodified = time();
                $DB->update_record('paygw_webirr_payments', $payment);

                // If payment is completed, deliver the order.
                if ($statusValue == 2) {
                    \core_payment\helper::deliver_order($payment->component, $payment->paymentarea, $payment->itemid, $payment->billreference, $USER->id);
                }
            }
            
            return [
                'success' => true,
                'status' => $statusValue,
                'complete' => ($statusValue == 2)
            ];
        } else {
            return [
                'success' => false,
                'error' => $paymentStatus->error
            ];
        }
    }

    /**
     * Returns description of method result value
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the status check was successful'),
            'status' => new external_value(PARAM_INT, 'The payment status code (0=pending, 1=in progress, 2=paid, 3=reversed)', VALUE_OPTIONAL),
            'complete' => new external_value(PARAM_BOOL, 'Whether the payment is complete', VALUE_OPTIONAL),
            'error' => new external_value(PARAM_TEXT, 'The error message if the status check failed', VALUE_OPTIONAL)
        ]);
    }
}

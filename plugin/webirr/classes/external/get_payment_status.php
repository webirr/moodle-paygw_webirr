<?php
namespace paygw_webirr\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use core_payment\helper as payment_helper;
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
        try {
            return self::execute_inner($paymentid);
        } catch (\RuntimeException $exception) {
            debugging('WeBirr gateway platform failure: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return [
                'success' => false,
                'error' => get_string('gatewaynotavailable', 'paygw_webirr')
            ];
        }
    }

    /**
     * Checks the status of a WeBirr payment.
     *
     * @param int $paymentid The payment record ID
     * @return array
     */
    private static function execute_inner($paymentid) {
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
        $gateways = $account->get_gateways(false);
        $gateway = $gateways['webirr'] ?? null;
        if (!$gateway || !$gateway->get('enabled')) {
            throw new \moodle_exception('gatewaynotavailable', 'paygw_webirr');
        }

        // Get the WeBirr client configuration.
        $config = $gateway->get_configuration();
        if (empty($config['apikey']) || empty($config['merchantid'])) {
            return [
                'success' => false,
                'error' => get_string('gatewaynotconfigured', 'paygw_webirr')
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
            $paymentreference = self::extract_payment_value($paymentStatus, 'paymentReference');
            $paymentissuer = self::extract_payment_issuer($paymentStatus);

            if (is_object($paymentObj) && isset($paymentObj->status)) {
                $statusValue = $paymentObj->status;
            } else if (is_object($paymentObj) && method_exists($paymentObj, 'IsPaid') && $paymentObj->IsPaid()) {
                $statusValue = 2; // Paid.
            }

            $paymentchanged = false;
            if ($statusValue == 2 && empty($payment->paymentid)) {
                try {
                    $payment->paymentid = payment_helper::save_payment(
                        $payable->get_account_id(),
                        $payment->component,
                        $payment->paymentarea,
                        $payment->itemid,
                        (int)$USER->id,
                        (float)$payment->amount,
                        $payment->currency,
                        'webirr'
                    );

                    payment_helper::deliver_order(
                        $payment->component,
                        $payment->paymentarea,
                        $payment->itemid,
                        (int)$payment->paymentid,
                        (int)$USER->id
                    );

                    $paymentchanged = true;
                } catch (\Exception $e) {
                    debugging('Exception while trying to complete WeBirr payment: ' . $e->getMessage(), DEBUG_DEVELOPER);
                    return [
                        'success' => false,
                        'error' => get_string('paymentdeliveryfailed', 'paygw_webirr')
                    ];
                }
            }

            if ($statusValue == 2) {
                if ($paymentreference !== '' && $paymentreference !== (string)($payment->paymentreference ?? '')) {
                    $payment->paymentreference = $paymentreference;
                    $paymentchanged = true;
                }

                if ($paymentissuer !== '' && $paymentissuer !== (string)($payment->paymentissuer ?? '')) {
                    $payment->paymentissuer = $paymentissuer;
                    $paymentchanged = true;
                }
            }

            // Update the payment record if the status has changed.
            if ($statusValue != $payment->status || $paymentchanged) {
                $payment->status = $statusValue;
                $payment->timemodified = time();
                $DB->update_record('paygw_webirr_payments', $payment);
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

    /**
     * Extract a payment value from either the response payload or its data node.
     *
     * @param \stdClass $result Gateway response object.
     * @param string $key Field name to extract.
     * @return string
     */
    private static function extract_payment_value(\stdClass $result, string $key): string {
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
     * Extract a display-friendly payment issuer.
     *
     * @param \stdClass $result Gateway response object.
     * @return string
     */
    private static function extract_payment_issuer(\stdClass $result): string {
        foreach (['bankName', 'paymentIssuer', 'issuerName', 'bankID'] as $key) {
            $issuer = self::extract_payment_value($result, $key);
            if ($issuer !== '') {
                return self::format_payment_issuer($issuer);
            }
        }

        return '';
    }

    /**
     * Convert bank IDs such as cbe_mobile into display text.
     *
     * @param string $issuer Raw issuer/bank value.
     * @return string
     */
    private static function format_payment_issuer(string $issuer): string {
        $words = preg_split('/[\s_-]+/', trim($issuer)) ?: [];
        $formatted = array_map(
            static function(string $word): string {
                return strlen($word) <= 3 ? strtoupper($word) : ucfirst(strtolower($word));
            },
            $words
        );

        return implode(' ', $formatted);
    }
}

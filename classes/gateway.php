
<?php

namespace paygw_webirr;

defined('MOODLE_INTERNAL') || die();

use core_payment\form\account_gateway_form;
use core_payment\helper;
use core_payment\local\entities\payable;

class gateway extends \core_payment\gateway {
    /**
     * The full list of supported currencies
     *
     * @return string[]
     */
    public static function get_supported_currencies(): array {
        return ['ETB']; // Ethiopian Birr
    }

    /**
     * Configuration form for the gateway instance
     *
     * @param account_gateway_form $form The form instance
     */
    public static function add_configuration_to_gateway_form(account_gateway_form $form): void {
        $mform = $form->get_mform();

        $mform->addElement('text', 'apikey', get_string('apikey', 'paygw_webirr'));
        $mform->setType('apikey', PARAM_TEXT);
        $mform->addHelpButton('apikey', 'apikey', 'paygw_webirr');
        $mform->addRule('apikey', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'merchantid', get_string('merchantid', 'paygw_webirr'));
        $mform->setType('merchantid', PARAM_TEXT);
        $mform->addHelpButton('merchantid', 'merchantid', 'paygw_webirr');
        $mform->addRule('merchantid', get_string('required'), 'required', null, 'client');

        $mform->addElement('advcheckbox', 'testmode', get_string('testmode', 'paygw_webirr'));
        $mform->addHelpButton('testmode', 'testmode', 'paygw_webirr');
        $mform->setDefault('testmode', 1);
    }

    /**
     * Validates the gateway configuration form.
     *
     * @param account_gateway_form $form The submitted form
     * @param \stdClass $data The submitted data
     * @param array $files The submitted files
     * @param array $errors The errors array
     */
    public static function validate_gateway_form(account_gateway_form $form,
                                                \stdClass $data,
                                                array $files,
                                                array &$errors): void {
        if (empty($data->apikey)) {
            $errors['apikey'] = get_string('required');
        }

        if (empty($data->merchantid)) {
            $errors['merchantid'] = get_string('required');
        }
    }

    /**
     * Process the payment and return the URL to redirect to.
     *
     * @param payable $payable The payable entity
     * @param string $redirect The URL to redirect to after the payment
     * @param string $cancelurl The URL to redirect to if the payment is cancelled
     * @return string The URL to redirect to
     */
    public function get_payment_url(payable $payable, string $redirect, string $cancelurl): string {
        global $CFG;
        
        // Generate the URL for the payment page.
        $url = new \moodle_url('/payment/gateway/webirr/pay.php', [
            'component' => $payable->get_component(),
            'paymentarea' => $payable->get_payment_area(),
            'itemid' => $payable->get_item_id(),
            'description' => $payable->get_description()
        ]);
        
        return $url->out(false);
    }
}
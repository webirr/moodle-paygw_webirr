<?php

require_once('../../../config.php');

$component = required_param('component', PARAM_COMPONENT);
$paymentarea = required_param('paymentarea', PARAM_AREA);
$itemid = required_param('itemid', PARAM_INT);
$description = required_param('description', PARAM_TEXT);

require_login();

// Page setup.
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/payment/gateway/webirr/pay.php', [
    'component' => $component,
    'paymentarea' => $paymentarea,
    'itemid' => $itemid,
    'description' => $description
]);
$PAGE->set_title(get_string('pluginname', 'paygw_webirr'));
$PAGE->set_heading(get_string('pluginname', 'paygw_webirr'));

// Get the payable object for amount display only
$payable = \core_payment\helper::get_payable($component, $paymentarea, $itemid);
$amount = $payable->get_amount();
$currency = $payable->get_currency();

// Add JavaScript for payment processing
$PAGE->requires->js_call_amd('paygw_webirr/repository', 'init', [
    'component' => $component,
    'paymentarea' => $paymentarea,
    'itemid' => $itemid,
    'description' => $description
]);

// Add some CSS for the payment code display
$PAGE->requires->css_init('
    .payment-code-large {
        font-size: 32px;
        font-weight: bold;
        text-align: center;
        margin: 20px 0;
        padding: 15px;
        background-color: #f5f5f5;
        border-radius: 8px;
        border: 2px solid #ddd;
        letter-spacing: 2px;
    }
    .payment-instructions {
        text-align: center;
        margin: 15px 0;
        font-size: 16px;
    }
    .payment-loading {
        text-align: center;
        margin: 20px 0;
        font-style: italic;
    }
');

echo $OUTPUT->header();

// Display the payment information.
echo html_writer::start_div('webirr-payment-container');

echo html_writer::start_div('webirr-payment-header');
echo html_writer::tag('h3', get_string('pluginname', 'paygw_webirr'));
echo html_writer::end_div();

echo html_writer::start_div('webirr-payment-details');
echo html_writer::tag('p', get_string('gatewaydescription', 'paygw_webirr'));
echo html_writer::tag('p', get_string('amount', 'paygw_webirr') . ': ' . $amount . ' ' . $currency);

// Container for the payment code (will be populated by JavaScript)
echo html_writer::start_div('payment-code-container');
echo html_writer::tag('p', 'Generating payment code...', ['class' => 'payment-loading', 'id' => 'payment-loading']);
echo html_writer::tag('div', '', ['id' => 'payment-code-display']); // Will be populated via JavaScript
echo html_writer::end_div();

// Display payment status
echo html_writer::start_div('alert alert-info', ['id' => 'payment-status']);
echo get_string('paymentpending', 'paygw_webirr');
echo html_writer::end_div();

echo html_writer::end_div();

echo $OUTPUT->footer();
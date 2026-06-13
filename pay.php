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

// Add JavaScript for payment processing.
$PAGE->requires->js_call_amd('paygw_webirr/payment', 'init', [
    $component,
    $paymentarea,
    $itemid,
    $description,
    sesskey()
]);

// Add some CSS for the payment code display.
$PAGE->requires->css_init('
    .payment-code-large {
        font-size: 32px;
        font-weight: bold;
        text-align: center;
        margin: 8px 0 20px;
        padding: 15px;
        background-color: #f5f5f5;
        border-radius: 8px;
        border: 2px solid #ddd;
        letter-spacing: 2px;
    }
    .payment-code-title {
        margin-top: 18px;
        text-align: center;
        font-weight: 700;
        color: #333;
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
    #payment-status {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .payment-spinner {
        display: none;
        width: 18px;
        height: 18px;
        flex: 0 0 18px;
        border: 3px solid rgba(20, 92, 158, 0.25);
        border-top-color: #145c9e;
        border-radius: 50%;
        animation: payment-spinner-rotate 0.8s linear infinite;
    }
    @keyframes payment-spinner-rotate {
        to {
            transform: rotate(360deg);
        }
    }
    .webirr-checkout-brand {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .webirr-checkout-logo {
        width: 42px;
        height: 42px;
        object-fit: contain;
        flex: 0 0 42px;
    }
    .payment-actions {
        margin-top: 12px;
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    .payment-detail {
        margin-top: 10px;
        color: #555;
        font-size: 14px;
    }
    .payment-instruction-link {
        margin-top: 10px;
        font-size: 14px;
    }
');

echo $OUTPUT->header();

// Display the payment information.
echo html_writer::start_div('webirr-payment-container');

echo html_writer::start_div('webirr-payment-header');
echo html_writer::start_div('webirr-checkout-brand');
echo html_writer::empty_tag('img', [
    'src' => (new moodle_url('/payment/gateway/webirr/pix/webirr-cute-logo.png'))->out(false),
    'alt' => 'WeBirr',
    'class' => 'webirr-checkout-logo'
]);
echo html_writer::tag('h3', get_string('pluginname', 'paygw_webirr'));
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('webirr-payment-details');
echo html_writer::tag('p', get_string('gatewaydescription', 'paygw_webirr'));
echo html_writer::tag('p', get_string('amount', 'paygw_webirr') . ': ' . $amount . ' ' . $currency);

// Container for the payment code (will be populated by JavaScript).
echo html_writer::start_div('payment-code-container');
echo html_writer::tag('p', 'Generating payment code...', ['class' => 'payment-loading', 'id' => 'payment-loading']);
echo html_writer::tag('div', '', ['id' => 'payment-code-display']); // Will be populated via JavaScript.
echo html_writer::end_div();

// Display payment status.
echo html_writer::start_div('alert alert-info', ['id' => 'payment-status']);
echo html_writer::span('', 'payment-spinner', ['id' => 'payment-spinner', 'aria-hidden' => 'true']);
echo html_writer::span(get_string('paymentpending', 'paygw_webirr'), 'payment-status-text', ['id' => 'payment-status-text']);
echo html_writer::end_div();
echo html_writer::tag('div',
    html_writer::link(
        new moodle_url('https://webirr.net/instructions/all.html'),
        'Payment Instruction',
        ['target' => '_blank', 'rel' => 'noopener']
    ),
    ['class' => 'payment-instruction-link']
);
echo html_writer::start_div('payment-actions', ['id' => 'payment-actions', 'style' => 'display: none;']);
echo html_writer::tag('button', 'Refresh', [
    'type' => 'button',
    'class' => 'btn btn-primary',
    'id' => 'payment-refresh-button'
]);
echo html_writer::end_div();
echo html_writer::tag('div', '', ['class' => 'payment-detail', 'id' => 'payment-detail']);

echo html_writer::end_div();

echo $OUTPUT->footer();

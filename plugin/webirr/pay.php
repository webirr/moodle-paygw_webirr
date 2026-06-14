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
$PAGE->set_title(get_string('checkouttitle', 'paygw_webirr'));
$PAGE->set_heading(get_string('checkouttitle', 'paygw_webirr'));
$PAGE->set_pagelayout('popup');

// Get the payable object for amount display only
$payable = \core_payment\helper::get_payable($component, $paymentarea, $itemid);
$amount = $payable->get_amount();
$currency = $payable->get_currency();
$displayamount = number_format((float)$amount, 2, '.', '');
$customername = !empty($USER->firstname) ? $USER->firstname : fullname($USER);

// Add JavaScript for payment processing.
$PAGE->requires->js_call_amd('paygw_webirr/payment', 'init', [
    $component,
    $paymentarea,
    $itemid,
    $description,
    sesskey(),
    [
        'creatingpaymentcode' => get_string('creatingpaymentcode', 'paygw_webirr'),
        'webirrpaymentcode' => get_string('webirrpaymentcode', 'paygw_webirr'),
        'usepaymentcode' => get_string('usepaymentcode', 'paygw_webirr'),
        'waitingpaymentconfirmation' => get_string('waitingpaymentconfirmation', 'paygw_webirr'),
        'checkingpaymentstatusdelay' => get_string('checkingpaymentstatusdelay', 'paygw_webirr'),
        'checkpaymentstatus' => get_string('checkpaymentstatus', 'paygw_webirr'),
        'refreshingpaymentstatus' => get_string('refreshingpaymentstatus', 'paygw_webirr'),
        'paymentsuccessful' => get_string('paymentsuccessful', 'paygw_webirr'),
        'paymentnotreceived' => get_string('paymentnotreceived', 'paygw_webirr'),
    ]
]);
$PAGE->requires->css('/payment/gateway/webirr/styles.css');

echo $OUTPUT->header();

echo html_writer::start_tag('main', ['class' => 'webirr-checkout-shell']);

echo html_writer::start_div('webirr-topbar');
echo html_writer::start_div('webirr-brand');
echo html_writer::empty_tag('img', [
    'src' => (new moodle_url('/payment/gateway/webirr/pix/webirr-cute-logo.png'))->out(false),
    'alt' => 'WeBirr',
    'class' => 'webirr-brand-logo'
]);
echo html_writer::start_div();
echo html_writer::tag('h1', get_string('checkouttitle', 'paygw_webirr'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('webirr-layout');

echo html_writer::start_tag('section', ['class' => 'webirr-panel']);
echo html_writer::tag('div', 'Checkout', ['class' => 'webirr-panel-title']);

echo html_writer::start_div('webirr-field');
echo html_writer::tag('label', get_string('customer', 'paygw_webirr'), ['for' => 'webirr-customer']);
echo html_writer::empty_tag('input', [
    'id' => 'webirr-customer',
    'class' => 'webirr-readonly-input',
    'value' => $customername,
    'readonly' => 'readonly',
]);
echo html_writer::end_div();

echo html_writer::start_div('webirr-field');
echo html_writer::tag('label', get_string('amount', 'paygw_webirr'), ['for' => 'webirr-amount']);
echo html_writer::empty_tag('input', [
    'id' => 'webirr-amount',
    'class' => 'webirr-readonly-input',
    'value' => $displayamount,
    'readonly' => 'readonly',
    'data-currency' => $currency,
]);
echo html_writer::end_div();

echo html_writer::start_div('webirr-field');
echo html_writer::tag('label', 'Description', ['for' => 'webirr-description']);
echo html_writer::empty_tag('input', [
    'id' => 'webirr-description',
    'class' => 'webirr-readonly-input',
    'value' => $description,
    'readonly' => 'readonly',
]);
echo html_writer::end_div();

echo html_writer::start_div('webirr-button-row');
echo html_writer::tag('button', 'Checkout', [
    'type' => 'button',
    'class' => 'webirr-primary-button',
]);
echo html_writer::end_div();
echo html_writer::end_tag('section');

echo html_writer::start_tag('section', ['class' => 'webirr-panel']);

// Container for the payment code (will be populated by JavaScript).
echo html_writer::start_div('payment-code-container');
echo html_writer::tag('p', get_string('generatingpaymentcode', 'paygw_webirr'), [
    'class' => 'payment-loading',
    'id' => 'payment-loading',
]);
echo html_writer::tag('div', '', ['id' => 'payment-code-display']); // Will be populated via JavaScript.
echo html_writer::end_div();

// Display payment status.
echo html_writer::start_div('alert alert-info', ['id' => 'payment-status']);
echo html_writer::span('', 'payment-spinner', ['id' => 'payment-spinner', 'aria-hidden' => 'true']);
echo html_writer::span(get_string('paymentpending', 'paygw_webirr'), 'payment-status-text', ['id' => 'payment-status-text']);
echo html_writer::end_div();
$paymentinstructions = ['CBE Mobile', 'CBE Birr', 'Awash Birr', 'Telebirr', 'M-Pesa'];
echo html_writer::start_div('payment-instruction-list');
echo html_writer::tag('div', get_string('paymentinstruction', 'paygw_webirr'), ['class' => 'payment-instruction-title']);
foreach ($paymentinstructions as $paymentinstruction) {
    echo html_writer::tag(
        'div',
        html_writer::span($paymentinstruction, 'payment-instruction-channel') .
            html_writer::span('-&gt;', 'payment-instruction-arrow') .
            html_writer::span(get_string('webirrpaymenttarget', 'paygw_webirr'), 'payment-instruction-target') .
            html_writer::span('-&gt;', 'payment-instruction-arrow') .
            html_writer::span(get_string('enterpaymentcode', 'paygw_webirr'), 'payment-instruction-target'),
        ['class' => 'payment-instruction-item']
    );
}
echo html_writer::end_div();
echo html_writer::start_div('payment-actions', ['id' => 'payment-actions', 'style' => 'display: none;']);
echo html_writer::tag('button', get_string('refresh', 'paygw_webirr'), [
    'type' => 'button',
    'class' => 'btn btn-primary webirr-primary-button',
    'id' => 'payment-refresh-button'
]);
echo html_writer::end_div();
echo html_writer::tag('div', '', ['class' => 'payment-detail', 'id' => 'payment-detail']);

echo html_writer::start_tag('dl', ['class' => 'webirr-record', 'id' => 'payment-record', 'style' => 'display: none;']);
echo html_writer::tag('dt', 'Merchant reference');
echo html_writer::tag('dd', '', ['id' => 'merchant-reference']);
echo html_writer::tag('dt', 'Payment Status');
echo html_writer::tag('dd', 'pending', ['id' => 'local-payment-status']);
echo html_writer::end_tag('dl');

echo html_writer::end_tag('section');
echo html_writer::end_div();
echo html_writer::end_tag('main');

echo $OUTPUT->footer();

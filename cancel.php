<?php

require_once('../../../config.php');

require_login();
require_sesskey();

$component = required_param('component', PARAM_COMPONENT);
$paymentarea = required_param('paymentarea', PARAM_AREA);
$itemid = required_param('itemid', PARAM_INT);

// Page setup.
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/payment/gateway/webirr/cancel.php', [
    'component' => $component,
    'paymentarea' => $paymentarea,
    'itemid' => $itemid
]);
$PAGE->set_title(get_string('paymentcancelled', 'paygw_webirr'));
$PAGE->set_heading(get_string('paymentcancelled', 'paygw_webirr'));

echo $OUTPUT->header();

// Display cancellation message.
echo $OUTPUT->notification(get_string('paymentcancelled', 'paygw_webirr'), 'error');

// Provide a link to try again.
$continueurl = new \moodle_url('/');
if ($component == 'enrol_fee' && $paymentarea == 'fee') {
    // Link to the course enrollment page for course fee payments.
    $continueurl = new \moodle_url('/enrol/index.php', ['id' => $itemid]);
} else {
    // For other component types, try to determine the appropriate destination
    $parts = explode('_', $component);
    if ($parts[0] == 'enrol' && !empty($parts[1])) {
        $continueurl = new \moodle_url('/enrol/index.php', ['id' => $itemid]);
    }
}

echo $OUTPUT->continue_button($continueurl);

echo $OUTPUT->footer();
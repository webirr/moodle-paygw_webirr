<?php

require_once('../../../config.php');

require_login();

$component = optional_param('component', '', PARAM_COMPONENT);
$paymentarea = optional_param('paymentarea', '', PARAM_AREA);
$itemid = optional_param('itemid', 0, PARAM_INT);
$message = optional_param('message', '', PARAM_TEXT);

// Page setup.
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/payment/gateway/webirr/error.php', [
    'component' => $component,
    'paymentarea' => $paymentarea,
    'itemid' => $itemid,
    'message' => $message
]);
$PAGE->set_title(get_string('paymentproblem', 'paygw_webirr'));
$PAGE->set_heading(get_string('paymentproblem', 'paygw_webirr'));

echo $OUTPUT->header();

// Display error message.
if (!empty($message)) {
    echo $OUTPUT->notification($message, 'error');
} else {
    echo $OUTPUT->notification(get_string('paymentproblem', 'paygw_webirr'), 'error');
}

// Provide a link to continue.
$continueurl = new \moodle_url('/');
if (!empty($component) && !empty($paymentarea) && !empty($itemid)) {
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
}

echo $OUTPUT->continue_button($continueurl);

echo $OUTPUT->footer();
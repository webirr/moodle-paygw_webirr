<?php

require_once('../../../config.php');

require_login();
require_sesskey();

$component = required_param('component', PARAM_COMPONENT);
$paymentarea = required_param('paymentarea', PARAM_AREA);
$itemid = required_param('itemid', PARAM_INT);

// Page setup.
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/payment/gateway/webirr/success.php', [
    'component' => $component,
    'paymentarea' => $paymentarea,
    'itemid' => $itemid
]);
$PAGE->set_title(get_string('paymentsuccessful', 'paygw_webirr'));
$PAGE->set_heading(get_string('paymentsuccessful', 'paygw_webirr'));

echo $OUTPUT->header();

// Display success message.
echo $OUTPUT->notification(get_string('paymentsuccessful', 'paygw_webirr'), 'success');

// Provide a link to continue.
$continueurl = new \moodle_url('/');

// If this is a course enrollment payment, link directly to the course
if ($component == 'enrol_fee' && $paymentarea == 'fee') {
    $continueurl = new \moodle_url('/course/view.php', ['id' => $itemid]);
} else {
    // For other component types, try to determine the appropriate destination
    // This handles cases like "enrol_coursecompleted" or custom components
    $parts = explode('_', $component);
    if ($parts[0] == 'enrol' && !empty($parts[1])) {
        $continueurl = new \moodle_url('/course/view.php', ['id' => $itemid]);
    }
}

echo $OUTPUT->continue_button($continueurl);

echo $OUTPUT->footer();
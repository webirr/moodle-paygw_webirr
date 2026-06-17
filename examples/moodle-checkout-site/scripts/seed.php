<?php
define('CLI_SCRIPT', true);

require_once('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/enrol/locallib.php');

global $CFG, $DB;

$merchantid = trim((string)getenv('WEBIRR_TEST_ENV_MERCHANT_ID'));
$apikey = trim((string)getenv('WEBIRR_TEST_ENV_API_KEY'));
$username = trim((string)(getenv('WEBIRR_MOODLE_DEMO_USERNAME') ?: 'webirrstudent'));
$password = (string)(getenv('WEBIRR_MOODLE_DEMO_PASSWORD') ?: 'WebirrDemo1!');

if ($merchantid === '' || $apikey === '') {
    fwrite(STDERR, "WEBIRR_TEST_ENV_MERCHANT_ID and WEBIRR_TEST_ENV_API_KEY are required.\n");
    exit(1);
}

\core\plugininfo\paygw::enable_plugin('webirr', 1);
\core\plugininfo\enrol::enable_plugin('fee', 1);
\core_plugin_manager::reset_caches();

$context = context_system::instance();
$accountrecord = $DB->get_record('payment_accounts', ['idnumber' => 'webirr-testenv']);
$accountdata = (object)[
    'name' => 'WeBirr TestEnv',
    'idnumber' => 'webirr-testenv',
    'contextid' => $context->id,
    'enabled' => 1,
    'archived' => 0,
];
if ($accountrecord) {
    $accountdata->id = $accountrecord->id;
}
$account = \core_payment\helper::save_payment_account($accountdata);

\core_payment\helper::save_payment_gateway((object)[
    'accountid' => $account->get('id'),
    'gateway' => 'webirr',
    'enabled' => 1,
    'config' => json_encode([
        'apikey' => $apikey,
        'merchantid' => $merchantid,
        'testmode' => 1,
    ]),
]);

$user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
$userdata = (object)[
    'auth' => 'manual',
    'confirmed' => 1,
    'mnethostid' => $CFG->mnet_localhost_id,
    'username' => $username,
    'password' => $password,
    'firstname' => 'Elias',
    'lastname' => 'Student',
    'email' => $username . '@example.com',
    'city' => 'Addis Ababa',
    'country' => 'ET',
];
if ($user) {
    $userdata->id = $user->id;
    user_update_user($userdata, false, false);
    update_internal_user_password($user, $password);
    $userid = (int)$user->id;
} else {
    $userid = (int)user_create_user($userdata, false, false);
    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    update_internal_user_password($user, $password);
}

$course = $DB->get_record('course', ['shortname' => 'WEBIRR-CHECKOUT']);
if (!$course) {
    $course = create_course((object)[
        'fullname' => 'WeBirr Online Checkout Test Course',
        'shortname' => 'WEBIRR-CHECKOUT',
        'category' => 1,
        'visible' => 1,
        'format' => 'topics',
        'summary' => 'Demo course used to validate the WeBirr Moodle payment gateway checkout flow.',
    ]);
}

$studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
$instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'fee']);
$now = time();
$enroldata = (object)[
    'enrol' => 'fee',
    'status' => ENROL_INSTANCE_ENABLED,
    'courseid' => $course->id,
    'sortorder' => 0,
    'name' => 'WeBirr Course Enrollment',
    'enrolperiod' => 0,
    'enrolstartdate' => 0,
    'enrolenddate' => 0,
    'expirynotify' => 0,
    'expirythreshold' => 0,
    'notifyall' => 0,
    'password' => '',
    'cost' => '530',
    'currency' => 'ETB',
    'roleid' => $studentrole->id,
    'customint1' => $account->get('id'),
    'customchar1' => 'moodle course enrollment',
    'timemodified' => $now,
];
if ($instance) {
    $enroldata->id = $instance->id;
    $DB->update_record('enrol', $enroldata);
    $enrolid = (int)$instance->id;
} else {
    $enroldata->timecreated = $now;
    $enrolid = (int)$DB->insert_record('enrol', $enroldata);
}

$DB->delete_records('paygw_webirr_payments', [
    'userid' => $userid,
    'component' => 'enrol_fee',
    'paymentarea' => 'fee',
    'itemid' => $enrolid,
]);
$DB->delete_records('payments', [
    'userid' => $userid,
    'component' => 'enrol_fee',
    'paymentarea' => 'fee',
    'itemid' => $enrolid,
]);
foreach ($DB->get_records('user_enrolments', ['userid' => $userid, 'enrolid' => $enrolid]) as $enrolment) {
    $DB->delete_records('user_enrolments', ['id' => $enrolment->id]);
}

rebuild_course_cache($course->id, true);

echo "Seeded WeBirr Moodle checkout example.\n";
echo "Course shortname: WEBIRR-CHECKOUT\n";
echo "Fee enrolment id: {$enrolid}\n";
echo "Demo username: {$username}\n";

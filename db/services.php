<?php

defined('MOODLE_INTERNAL') || die();

$functions = [
    'paygw_webirr_get_code' => [
        'classname' => 'paygw_webirr\external\get_payment_code',
        'methodname' => 'execute',
        'description' => 'Gets a payment code from WeBirr (on sending invoice to WeBirr)',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true
    ],
    'paygw_webirr_get_status' => [
        'classname' => 'paygw_webirr\external\get_payment_status',
        'methodname' => 'execute',
        'description' => 'Checks the status of a WeBirr payment',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true
    ],
    'paygw_webirr_get_payment_code' => [
        'classname' => 'paygw_webirr\external\get_payment_code',
        'methodname' => 'execute',
        'description' => 'Backward-compatible alias for getting a payment code from WeBirr',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true
    ],
    'paygw_webirr_get_payment_status' => [
        'classname' => 'paygw_webirr\external\get_payment_status',
        'methodname' => 'execute',
        'description' => 'Backward-compatible alias for checking a WeBirr payment status',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true
    ]
];

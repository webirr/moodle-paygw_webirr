<?php

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    $settings->add(new admin_setting_configtext(
        'paygw_webirr/apikey',
        get_string('apikey', 'paygw_webirr'),
        get_string('apikey_help', 'paygw_webirr'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'paygw_webirr/merchantid',
        get_string('merchantid', 'paygw_webirr'),
        get_string('merchantid_help', 'paygw_webirr'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'paygw_webirr/testmode',
        get_string('testmode', 'paygw_webirr'),
        get_string('testmode_help', 'paygw_webirr'),
        1
    ));
}
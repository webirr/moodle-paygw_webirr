<?php

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'paygw/webirr:managepayments' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
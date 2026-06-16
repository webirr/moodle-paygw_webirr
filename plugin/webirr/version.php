<?php
// This file is part of WeBirr Moodle Payment Gateway.
//
// WeBirr Moodle Payment Gateway is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// WeBirr Moodle Payment Gateway is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.

defined('MOODLE_INTERNAL') || die();

/** @var stdClass $plugin */
$plugin->version = 2026061500;          // The current plugin version (Date: YYYYMMDDXX).
$plugin->requires = 2024100700;         // Requires Moodle 4.5 LTS or later.
$plugin->supported = [405, 502];        // Moodle 4.5 LTS through Moodle 5.2.
$plugin->component = 'paygw_webirr';    // Full name of the plugin (used for diagnostics).
$plugin->maturity = MATURITY_BETA;      // This version's maturity level.
$plugin->release = '1.0.0-beta.1';      // Version release name.

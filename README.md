# WeBirr Moodle Plugin

![WeBirr Online Checkout flow](screenshots/webirr-online-checkout-journey.jpg)

This plugin integrates WeBirr payment gateway with Moodle, allowing for Ethiopian Birr (ETB) payments via various banking apps.

The plugin uses Moodle's payment gateway system and a Moodle-native WeBirr client, so it can be packaged without requiring Composer installation on the Moodle server.

Features

Easy integration with Moodle's payment subsystem
Simple payment experience with clear payment code display
Real-time payment status monitoring
Support for both test and production environments

Requirements

- Moodle 3.11 or later
- PHP 7.4 or later
- WeBirr Merchant account

Installation

Place the plugin files in payment/gateway/webirr
Visit Site administration > Notifications to complete installation
Configure the payment gateway with your WeBirr API key and merchant ID

### Status
This plugin is being prepared for Moodle Plugins directory submission. Before using it on a live Moodle site, complete a full Moodle install test, gateway configuration test, and TestEnv checkout validation with developer debugging enabled.


## How the WeBirr Integration Works

This plugin follows the WeBirr **online checkout pattern**. The browser does
not call WeBirr directly. Moodle provides two logged-in AJAX endpoints through
external functions:

| Checkout role | Moodle method | Source | WeBirr call |
| --- | --- | --- | --- |
| Create checkout/payment code | `paygw_webirr_get_code` | `classes/external/get_payment_code.php` | Moodle-native client `create_bill(...)` |
| Check payment status | `paygw_webirr_get_status` | `classes/external/get_payment_status.php` | Moodle-native client `get_payment_status(...)` |

These endpoints are registered in `db/services.php` with `ajax => true` and are
called by `amd/src/repository.js` through Moodle `core/ajax`. They are crucial
to the checkout flow because merchant API credentials stay on the Moodle server:
the checkout endpoint creates the WeBirr bill and returns the payment code, and
the payment status endpoint forwards a single status check to WeBirr, updates
the local Moodle payment record, and completes delivery when payment is paid.
The calls are made through the internal Moodle-native client in
`classes/local/webirr_client.php`, so the plugin package does not require
Composer dependencies at runtime.

The plugin follows this flow to process payments:

1. **Creating the Payment**
  - When a user initiates payment, the plugin calls `get_payment_code` which creates an invoice at WeBirr using the WeBirr Create Bill API
  - The API returns a payment code that the user will enter in their banking app
  - This code, along with all payment details, is stored in the Moodle database for tracking and verification

2. **Payment Code Display**
  - The payment page displays this code prominently to the user 
  - The page is designed to make the code easily readable for entry in banking apps

3. **Status Monitoring**
  - While displaying the payment code, JavaScript code begins polling WeBirr's API
  - An AJAX function periodically calls `get_payment_status` (every 5 seconds) 
  - This checks if the user has completed payment through their banking app by querying the WeBirr Payment Status API
  - The polling continues until the payment is confirmed or times out

4. **Completion and Access**
  - Once payment is detected as confirmed by the WeBirr API, the payment record is updated in the database
  - The user is automatically redirected to a success page
  - The Moodle payment system grants access to the purchased item (course enrollment, etc.)
  - If payment fails or times out, appropriate error handling directs the user

This implementation follows Moodle's payment gateway architecture while incorporating the specific requirements of WeBirr's payment flow.

# WeBirr Moodle Plugin - Proof of concept

This plugin integrates WeBirr payment gateway with Moodle, allowing for Ethiopian Birr (ETB) payments via various banking apps.

**IMPORTANT: This is a proof of concept only and not ready for production use.**
Features

Easy integration with Moodle's payment subsystem
Simple payment experience with clear payment code display
Real-time payment status monitoring
Support for both test and production environments

Requirements

- Moodle 3.11 or later
- WeBirr merchant account
- PHP 7.4 or later

Installation

Place the plugin files in payment/gateway/webirr
Visit Site administration > Notifications to complete installation
Configure the payment gateway with your WeBirr API key and merchant ID

### Status
This is an early prototype to demonstrate the integration concept. It requires further development and testing before it can be used in a production environment.


## How the WeBirr Integration Works

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
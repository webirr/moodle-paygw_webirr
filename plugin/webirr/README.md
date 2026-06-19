# WeBirr Moodle Payment Gateway

This directory is the actual Moodle payment gateway plugin package root. It
integrates WeBirr online checkout with Moodle's payment subsystem.

## Installation From Source

Copy this folder into Moodle as:

```text
payment/gateway/webirr
```

Then visit Site administration > Notifications to complete plugin installation.

## Configuration

Configure the payment gateway in Moodle with:

- WeBirr merchant ID
- WeBirr API key
- Test or production environment

Merchant credentials stay on the Moodle server. The browser does not call
WeBirr directly.

## Features

- Moodle-native payment gateway integration
- WeBirr Payment Code display
- Server-side payment status checking
- Test and production environment support
- No Composer dependency required on the Moodle server

## How It Works

The plugin exposes Moodle AJAX endpoints for the checkout page:

| Checkout role | Moodle method | Responsibility |
| --- | --- | --- |
| Create checkout/payment code | `paygw_webirr_get_code` | Create or resume the WeBirr bill and return the payment code. |
| Check payment status | `paygw_webirr_get_status` | Check WeBirr payment status from Moodle and complete Moodle payment delivery when paid. |

The WeBirr calls are made through the Moodle-native client in
`classes/local/webirr_client.php`.

## WeBirr Payment Flow

At a glance, the payment flow is:

### 1. Invoice Creation / Checkout on Purchase

- The learner starts a Moodle purchase or paid enrollment checkout.
- Moodle calls `get_payment_code`, which creates or resumes a WeBirr
  bill/invoice.
- Moodle stores local payment details for verification and later access
  delivery.

### 2. Payment Code Display

- WeBirr returns a **WeBirr Payment Code** for the learner to enter in a
  supported banking or wallet app.
- The payment page displays the code prominently and lists only banks returned
  by WeBirr for the configured merchant.
- The customer payment path is:
  `{Banking App} -> WeBirr menu -> Enter Payment Code -> Pay`.

Current mobile apps integrated with WeBirr include CBE Mobile, CBE Birr, Awash
Birr, Telebirr, M-Pesa, Coopay Ebirr, and other WeBirr-enabled banking or
wallet apps. The checkout page shows only the subset configured for that
merchant.

### 3. Payment Status Monitoring

- JavaScript polls the Moodle AJAX status endpoint.
- Moodle checks WeBirr payment status from the server side.

### 4. Completion and Access

- Once paid, Moodle updates the local payment record and redirects to success.
- Moodle's payment subsystem grants access to the purchased item.

### Detailed Flow

1. A learner starts a Moodle purchase or paid enrollment checkout.
2. Moodle resolves the payable item, including amount, customer, description,
   and merchant configuration.
3. Moodle asks WeBirr to create or resume the bill/invoice using a stable
   merchant reference.
4. WeBirr returns a **WeBirr Payment Code** for that payable item.
5. Moodle displays the payment code to the learner and keeps the local payment
   record pending.
6. The learner opens a mobile banking or wallet app integrated with WeBirr.
7. The learner follows the general app path:
   `{Banking App} -> WeBirr menu -> Enter Payment Code -> Pay`.
8. The banking or wallet app sends the payment through WeBirr for that payment
   code.
9. Moodle polls its own payment-status endpoint. The server-side Moodle plugin
   checks WeBirr payment status and updates the local payment record.
10. When WeBirr reports the payment as paid, Moodle records the payment,
    redirects to success, and grants access to the purchased item.

The customer never enters Moodle or merchant API credentials in the banking app.
The WeBirr Payment Code connects the Moodle payable item to the payment made
from the customer's chosen banking or wallet app.

## Release Package

This plugin folder should be the only source used when building the Moodle
release ZIP. The ZIP top-level folder must be named `webirr`.

The repository root contains screenshots, example apps, and release notes.

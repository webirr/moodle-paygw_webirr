# Standalone Checkout Demo

This is a standalone PHP demo app for the WeBirr online checkout pattern.

It shares the actual Moodle plugin's WeBirr client:

```text
../../plugin/webirr/classes/local/webirr_client.php
```

It does not use Moodle's payment APIs, Moodle AJAX external functions, or the
plugin's AMD JavaScript. It has its own lightweight routes and SQLite demo
storage so the checkout pattern can be shown without installing Moodle.

Run it with TestEnv credentials:

```sh
WEBIRR_TEST_ENV_MERCHANT_ID=your-test-merchant-id \
WEBIRR_TEST_ENV_API_KEY=your-test-api-key \
php -S 127.0.0.1:8096 examples/standalone-checkout-demo/index.php
```

Open `http://127.0.0.1:8096/`.

Use this demo for quick visual/API checks. Use the Moodle checkout example site
for release validation of the real Moodle plugin flow.

# WeBirr Moodle Plugin Changes

## 1.0.0-beta.1 - 2026-06-14

- Prepare the plugin for Moodle Plugins directory review.
- Use a Moodle-native WeBirr client at runtime instead of requiring Composer on
  the Moodle server.
- Add TestEnv-ready checkout and payment status flow with server-side merchant
  credentials.
- Add release metadata, license, privacy metadata, localized UI strings, and
  package-readiness checks.
- Store the Moodle core payment ID after WeBirr payment confirmation before
  delivering the Moodle order.

# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [0.0.1] - Unreleased

### Added
- WooCommerce payment method "Mobile Money (MTN / Airtel)" for the classic and block checkout, with HPOS support.
- Two payment flows: Mulla hosted checkout page (default) or phone number on your own checkout/form.
- "Approve on your phone" waiting screen that follows the payment live and moves the order on when it settles.
- Mulla Pay block and `[mulla_pay]` shortcode for sites without WooCommerce; payments listed under Mulla → Payments.
- Signed webhook endpoint (`/wp-json/mulla/v1/webhook`) plus WP-Cron status polling as a fallback.
- Settings page with test/live keys, `wp-config.php` constant overrides, "Test connection" and one-click "Set up webhook".
- Amount check: an order whose paid amount does not match is put on hold instead of completed.
- Bundled `kayko/mulla-php` SDK, prefixed to `MullaPayments\Vendor\Mulla` so it cannot clash with other plugins.
- Hooks: `mulla_payments_webhook`, `mulla_payments_webhook_{event}`, `mulla_payments_charge_updated`, `mulla_payments_form_payment_succeeded`.

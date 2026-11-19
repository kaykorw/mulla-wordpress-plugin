# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [0.1.0](https://github.com/kaykorw/mulla-wordpress-plugin/compare/v0.0.1...v0.1.0) (2026-11-19)


### Features

* Add release automation and update plugin metadata ([a885085](https://github.com/kaykorw/mulla-wordpress-plugin/commit/a885085fec47d28ced7ffae1aa449ab95f521201))
* Add support for two payment flows in Mulla Payments ([e604056](https://github.com/kaykorw/mulla-wordpress-plugin/commit/e6040567d42287d42ca6dd140c384effe6676877))


### Bug Fixes

* escape exception messages and update readme for WordPress.org review ([5b52a36](https://github.com/kaykorw/mulla-wordpress-plugin/commit/5b52a36b1881764c2db87bda0a6364d9f41cfd85))
* Update contributor information in readme.txt ([857e1eb](https://github.com/kaykorw/mulla-wordpress-plugin/commit/857e1eba5d9e3e4ec0cc88d82751c0a0bf563976))


### Miscellaneous Chores

* add README.md to extra files in release-please configuration ([676f129](https://github.com/kaykorw/mulla-wordpress-plugin/commit/676f129cc9320f299a002023a15dcdd8f542a9a8))
* add README.md to extra files in release-please configuration ([3da832a](https://github.com/kaykorw/mulla-wordpress-plugin/commit/3da832ad5ef039fae346a6af409da40d5d7b4346))
* remove mulla-php checkout steps and update README and composer.json for Packagist integration ([171f9b5](https://github.com/kaykorw/mulla-wordpress-plugin/commit/171f9b57c2b992acb786ae3337b402d5e174378a))

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

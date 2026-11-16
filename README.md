# Mulla Payments for WordPress

Accept MTN Mobile Money and Airtel Money (Rwanda) on WordPress:

- **WooCommerce**: a "Mobile Money (MTN / Airtel)" payment method for the classic and block checkout (HPOS compatible).
- **Any site**: the **Mulla Pay** block or the `[mulla_pay]` shortcode, with payments listed under Mulla → Payments.

Merchant documentation (installation, FAQ) is in [readme.txt](readme.txt). This file is for developers.

## How a payment works

1. The customer picks the network and enters their number.
2. The plugin calls `charges->initiate` with a fresh idempotency key; Mulla sends a prompt to the phone.
3. The order stays *Pending payment*; the customer sees a waiting screen that polls `GET /wp-json/mulla/v1/orders/{id}`.
4. Mulla calls `POST /wp-json/mulla/v1/webhook` (HMAC-SHA512 signed). WP-Cron also checks the charge after 1, 3, 6, 11 and 21 minutes in case the webhook is missed.
5. Both paths produce a `ChargeUpdate` and fire `mulla_payments_charge_updated`. The WooCommerce module completes the order (or puts it on hold when the paid amount does not match); the form module updates the payment record.

Webhook handling is idempotent: replays and webhook + poller races are harmless.

## Hooks

| Hook | Arguments | When |
|---|---|---|
| `mulla_payments_webhook` | `Event $event` | Every verified webhook |
| `mulla_payments_webhook_{type}` | `Event $event` | e.g. `mulla_payments_webhook_charge.success` |
| `mulla_payments_charge_updated` | `ChargeUpdate $update` | A charge changed, from a webhook or polling |
| `mulla_payments_form_payment_succeeded` | `int $paymentId, ChargeUpdate $update` | A `[mulla_pay]` payment succeeded |

```php
add_action('mulla_payments_form_payment_succeeded', function (int $paymentId, $update) {
    // e.g. activate a membership; $paymentId is the mulla_payment post ID.
}, 10, 2);
```

`Event` and `ChargeUpdate` come from the bundled SDK under the `MullaPayments\Vendor\Mulla\` and `MullaPayments\Payments\` namespaces.

## Layout

```
mulla-payments.php        bootstrap: PHP check, autoloaders, Plugin::boot()
src/Plugin.php            composition root: builds services, registers modules
src/Settings/             option + wp-config.php constant overrides
src/Http/                 WordPressTransport: SDK transport over wp_remote_request
src/Webhooks/             signed webhook REST route
src/Payments/             ChargeUpdate, StatusPoller (WP-Cron fallback)
src/WooCommerce/          gateway, block checkout integration, order handling
src/Standalone/           [mulla_pay] shortcode/block, payment records (CPT)
src/Admin/ + views/       settings page
bin/prefix-sdk.php        copies mulla/mulla-php into vendor-prefixed/ under MullaPayments\Vendor\
bin/build-zip.php         builds build/mulla-payments-{version}.zip
```

The SDK is prefixed so that another plugin bundling a different `mulla/mulla-php` version can never conflict. `composer install` runs the prefixer automatically; the plugin itself only loads `vendor-prefixed/`, never `vendor/`.

## Development

Requires PHP 8.1+, Composer, and `mulla-php` checked out next to this repo (`../mulla-php`) until it is on Packagist.

```bash
composer install
composer check        # php-cs-fixer, PHPStan (level 8), PHPUnit
php bin/build-zip.php # installable zip in build/
```

### Local WordPress with wp-env

Needs Docker and Node.

```bash
npx @wordpress/env start     # http://localhost:8888, admin / password
```

WooCommerce is installed and the plugin is mounted from this folder. To keep your sandbox key out of the database, create `.wp-env.override.json` (git-ignored):

```json
{ "config": { "MULLA_TEST_SECRET_KEY": "sk_test_..." } }
```

Useful commands:

```bash
npx @wordpress/env run cli wp option update woocommerce_currency RWF
npx @wordpress/env run cli wp cron event run mulla_payments_poll_charge
npx @wordpress/env logs
```

Webhooks cannot reach `localhost`; payments are still confirmed by polling. To test webhooks, either expose the site with a tunnel and click **Set up webhook**, or post a signed payload yourself:

```bash
BODY='{"event":"charge.success","event_id":"evt_1","data":{"charge":"<charge id>","status":"SUCCESSFUL","amount":5000,"currency":"RWF"}}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha512 -hmac "<webhook secret>" -hex | sed 's/^.* //')
curl -X POST http://localhost:8888/wp-json/mulla/v1/webhook \
  -H 'content-type: application/json' -H "x-webhook-signature: $SIG" -d "$BODY"
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

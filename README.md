# Mulla for WordPress

Accept MTN Mobile Money and Airtel Money (Rwanda) on WordPress:

- **WooCommerce**: a "Mobile Money (MTN / Airtel)" payment method for the classic and block checkout (HPOS compatible).
- **Any site**: the **Mulla Pay** block or the `[mulla_pay]` shortcode, with payments listed under Mulla → Payments.

Merchant documentation (installation, FAQ) is in [readme.txt](readme.txt). This file is for developers.

## How a payment works

Two flows, set per gateway (WooCommerce → Settings → Payments → Mulla → *Payment flow*) and per form (`flow="redirect|onsite"`):

| | Redirect (default) | On-site |
|---|---|---|
| Collects network + number | Mulla's hosted checkout page | Your checkout / form |
| API call | `charges->createCheckout` (`success_url` = order-received page, `cancel_url` = WooCommerce cancel-order URL) | `charges->initiate` |
| Cart | Kept until the order-received page, so cancelling returns to a full cart | Emptied after the prompt is sent |
| Retry while pending | Reuses the stored checkout URL until it expires | No new prompt while one is pending |

1. The plugin starts the charge with a fresh idempotency key and stores the charge ID on the order (or form payment record).
2. The order stays *Pending payment*. The customer sees a waiting screen that polls `GET /wp-json/mulla/v1/orders/{id}`, which asks Mulla directly at most every 10 seconds. Returning to `success_url` proves nothing on its own.
3. Mulla calls `POST /wp-json/mulla/v1/webhook` (HMAC-SHA512 signed). WP-Cron also checks the charge after 1, 3, 6, 11 and 21 minutes in case the webhook is missed.
4. Both paths produce a `ChargeUpdate` and fire `mulla_payments_charge_updated`. The WooCommerce module completes the order (or puts it on hold when the paid amount does not match); the form module updates the payment record.

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

## Development

Requires PHP 8.1+ and Composer. `kayko/mulla-php` is installed from Packagist.

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
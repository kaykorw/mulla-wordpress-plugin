# Mulla for WordPress

<!-- x-release-please-start-version -->

> This guide applies to the **Mulla Payments** plugin **0.0.1** (slug `mulla-payments`). See [Versioning and compatibility](#versioning-and-compatibility).

<!-- x-release-please-end -->

## Introduction

Mulla Payments lets a WordPress site take **MTN Mobile Money** and **Airtel Money** payments in Rwanda through Mulla, the mobile money payment gateway.

- **WooCommerce:** a "Mobile Money (MTN / Airtel)" payment method for the classic and block checkout. Compatible with High-Performance Order Storage (HPOS).
- **Any site:** the **Mulla Pay** block or the `[mulla_pay]` shortcode, with every payment listed under **Mulla → Payments**.

**Who this file is for:** developers who extend the plugin, run it locally or contribute to it. Store owners should read [readme.txt](readme.txt), which is also the WordPress.org listing. It covers installation, settings and the FAQ.

## Table of contents

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [How a payment works](#how-a-payment-works)
- [Guides](#guides)
  - [Add a payment form](#add-a-payment-form)
  - [Run your code after a payment](#run-your-code-after-a-payment)
  - [Keep keys out of the database](#keep-keys-out-of-the-database)
  - [Run WordPress locally](#run-wordpress-locally)
  - [Test webhooks locally](#test-webhooks-locally)
  - [Build the plugin zip](#build-the-plugin-zip)
  - [Go live](#go-live)
- [Reference](#reference)
  - [Hooks](#hooks)
  - [Shortcode and block attributes](#shortcode-and-block-attributes)
  - [REST endpoints](#rest-endpoints)
  - [wp-config.php constants](#wp-configphp-constants)
- [Troubleshooting](#troubleshooting)
- [Versioning and compatibility](#versioning-and-compatibility)
- [Support](#support)
- [Contributing](#contributing)
- [License](#license)

## Requirements

- **WordPress 6.4 or newer** and **PHP 8.1 or newer**.
- **WooCommerce 8.0 or newer**, only if you want the WooCommerce payment method. The store currency must be Rwandan franc (RWF).
- **A Mulla merchant account** and a secret API key from the Mulla dashboard (Developers → API keys). Use a test key (`sk_test_`) while building.
- **A public HTTPS address** to receive webhooks. Without one (for example on `localhost`), payments are still confirmed by polling.

## Installation

**From WordPress:** Plugins → Add New → search for "Mulla Payments", or upload the zip from a [GitHub release](https://github.com/kaykorw/mulla-wordpress-plugin/releases).

**From source,** for development:

```bash
git clone https://github.com/kaykorw/mulla-wordpress-plugin.git mulla-payments
cd mulla-payments
composer install   # also builds vendor-prefixed/ (the bundled, prefixed Mulla PHP SDK)
```

Then copy or link the folder into `wp-content/plugins/`, or use [wp-env](#run-wordpress-locally).

## Quick start

This uses the sandbox, so no real money moves.

1. **Activate** the plugin.
2. Go to **Mulla → Settings**, choose **Sandbox** and paste your `sk_test_` key.
3. Click **Test connection**, then **Set up webhook**. The plugin registers its webhook URL with Mulla and saves the secret.
4. **WooCommerce:** set the store currency to RWF and enable the method under **WooCommerce → Settings → Payments → Mulla**. Place an order.
   **Any other site:** add `[mulla_pay amount="5000" reason="Test"]` to a page and pay.
5. Approve the payment on the phone. The order moves to **Processing**, or the payment appears as paid under **Mulla → Payments**.

## How a payment works

### Two payment flows

You choose the flow per gateway (WooCommerce → Settings → Payments → Mulla → **Payment flow**) and per form (`flow="redirect"` or `flow="onsite"`).

|                                  | Redirect (default)                                                                                     | On-site                            |
| -------------------------------- | ------------------------------------------------------------------------------------------------------ | ---------------------------------- |
| Who collects network and number  | Mulla's hosted checkout page                                                                           | Your checkout or form              |
| API call                         | `charges->createCheckout` (`success_url` = order-received page, `cancel_url` = WooCommerce cancel URL) | `charges->initiate`                |
| Cart                             | Kept until the order-received page, so cancelling returns to a full cart                               | Emptied once the prompt is sent    |
| Paying again while still pending | Reuses the stored checkout URL until it expires                                                        | No new prompt while one is pending |

### From order to confirmation

1. **Start.** The plugin starts the charge with a fresh idempotency key and saves the charge ID on the order (or on the form's payment record).
2. **Wait.** The order stays **Pending payment**. The customer sees a waiting screen that polls `GET /wp-json/mulla/v1/orders/{id}`. That endpoint asks Mulla directly, at most every 10 seconds. Returning to `success_url` proves nothing on its own.
3. **Confirm.** Mulla calls `POST /wp-json/mulla/v1/webhook`, signed with HMAC-SHA512. As a fallback, WP-Cron also checks the charge 1, 3, 6, 11 and 21 minutes after it starts.
4. **Update.** Both paths create a `ChargeUpdate` and fire `mulla_payments_charge_updated`. WooCommerce orders are completed, or put **on hold** if the paid amount doesn't match the order. Form payments update their payment record.

Webhooks and polling can arrive in any order, more than once. Handling is idempotent, so replays and races are harmless.

## Guides

### Add a payment form

Use the **Mulla Pay** block in the editor, or the shortcode anywhere shortcodes work:

```text
[mulla_pay amount="5000" reason="Membership" button="Pay now"]   fixed amount
[mulla_pay reason="Donation" min="500"]                          visitor chooses the amount (at least 500 RWF)
[mulla_pay amount="2000" reason="Ticket" flow="onsite"]          phone number entered on your page
```

All attributes are listed in [Shortcode and block attributes](#shortcode-and-block-attributes). Payments appear under **Mulla → Payments** as `mulla_payment` posts.

### Run your code after a payment

Use the plugin's action hooks, for example in your theme's `functions.php` or your own plugin.

**After a form payment succeeds**, for example to activate a membership:

```php
add_action('mulla_payments_form_payment_succeeded', function (int $paymentId, $update) {
    // $paymentId is the mulla_payment post ID.
    // $update->chargeId, $update->amount, $update->reference, $update->phoneNumber
}, 10, 2);
```

**After any charge changes**, from a webhook or polling:

```php
add_action('mulla_payments_charge_updated', function ($update) {
    if ($update->isSuccessful()) {
        error_log("Mulla charge {$update->chargeId} paid ({$update->source})");
    }
});
```

**For one webhook type:**

```php
add_action('mulla_payments_webhook_payout.success', function ($event) {
    // $event->objectId() is the payout ID.
});
```

For WooCommerce orders, you can also use WooCommerce's own hooks, such as `woocommerce_order_status_processing`.

> **Hooks can run more than once** for the same charge (a webhook and a poll, or a webhook retry). Make your code safe to repeat, for example by checking whether you've already handled `$update->chargeId`.

### Keep keys out of the database

Define the keys in `wp-config.php`. The matching fields in wp-admin become read-only:

```php
define('MULLA_MODE', 'production');
define('MULLA_LIVE_SECRET_KEY', 'sk_live_...');
define('MULLA_LIVE_WEBHOOK_SECRET', '...');
```

See [wp-config.php constants](#wp-configphp-constants) for the Sandbox names.

### Run WordPress locally

You need Docker and Node.js.

```bash
npx @wordpress/env start   # http://localhost:8888, user admin / password password
```

WooCommerce is installed, and the plugin is mounted from this folder. To keep your sandbox key out of the database, create `.wp-env.override.json` (it's git-ignored):

```json
{ "config": { "MULLA_TEST_SECRET_KEY": "sk_test_..." } }
```

Useful commands:

```bash
npx @wordpress/env run cli wp option update woocommerce_currency RWF   # set the store currency
npx @wordpress/env run cli wp cron event run mulla_payments_poll_charge # run pending status checks now
npx @wordpress/env logs                                                  # follow the logs
```

### Test webhooks locally

Mulla can't reach `localhost`, but payments are still confirmed by polling. To test webhooks anyway, choose one:

- **Use a tunnel.** Expose the site with a tunnel service, then click **Set up webhook** in **Mulla → Settings**.
- **Post a signed webhook yourself:**

```bash
BODY='{"event":"charge.success","event_id":"evt_1","data":{"charge":"<charge id>","status":"SUCCESSFUL","amount":5000,"currency":"RWF"}}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha512 -hmac "<webhook secret>" -hex | sed 's/^.* //')
curl -X POST http://localhost:8888/wp-json/mulla/v1/webhook \
  -H 'content-type: application/json' -H "x-webhook-signature: $SIG" -d "$BODY"
```

Use the charge ID of a real pending order, and the webhook secret saved in **Mulla → Settings** (or your constant).

### Build the plugin zip

```bash
php bin/build-zip.php   # writes an installable zip to build/
```

Releases are built and deployed by CI (see [Versioning and compatibility](#versioning-and-compatibility)), so you only need this to test an install by hand.

### Go live

Before you switch to real money, check that:

- [ ] your merchant account is approved in the Mulla dashboard;
- [ ] **Mulla → Settings** is set to **Production** with an `sk_live_` key, or `MULLA_MODE` and `MULLA_LIVE_SECRET_KEY` are defined;
- [ ] you clicked **Set up webhook** again in Production, because Sandbox and Production webhooks have different secrets;
- [ ] the site uses HTTPS and its REST API (`/wp-json/`) isn't blocked by a security plugin or firewall;
- [ ] WP-Cron runs (or a real cron job calls `wp-cron.php`), so the fallback status checks happen;
- [ ] the WooCommerce store currency is RWF;
- [ ] any code you hooked in is safe to run more than once.

## Reference

### Hooks

| Action                                  | Arguments                              | Fired when                                                                   |
| --------------------------------------- | -------------------------------------- | ---------------------------------------------------------------------------- |
| `mulla_payments_webhook`                | `Event $event`                         | Every verified webhook                                                       |
| `mulla_payments_webhook_{type}`         | `Event $event`                         | A verified webhook of one type, e.g. `mulla_payments_webhook_charge.success` |
| `mulla_payments_charge_updated`         | `ChargeUpdate $update`                 | A charge changed, from a webhook or polling                                  |
| `mulla_payments_form_payment_succeeded` | `int $paymentId, ChargeUpdate $update` | A `[mulla_pay]` or block payment succeeded                                   |

**`Event`** is the Mulla PHP SDK's webhook event, bundled under the `MullaPayments\Vendor\Mulla\Webhooks` namespace. It has `id`, `type`, `data`, `objectId()`, `reference()`, `status()`, `amount()`, `currency()` and `metadata()`.

**`ChargeUpdate`** (`MullaPayments\Payments\ChargeUpdate`) has:

| Property / method                           | Description                                                         |
| ------------------------------------------- | ------------------------------------------------------------------- |
| `chargeId`                                  | The Mulla charge ID                                                 |
| `status`                                    | A `ChargeStatus` enum: `PENDING`, `SUCCESSFUL`, `FAILED`, `EXPIRED` |
| `amount`, `reference`                       | The charged amount (RWF) and Mulla's reference                      |
| `transactionId`                             | The network's transaction ID, when the webhook includes it          |
| `failureMessage`, `phoneNumber`, `provider` | Filled when the update came from polling                            |
| `source`                                    | `'webhook'` or `'api'` (polling)                                    |
| `isSuccessful()`, `isFinal()`               | Whether the charge succeeded, or reached a final status             |

### Shortcode and block attributes

The `[mulla_pay]` shortcode and the **Mulla Pay** block accept the same attributes:

| Attribute | Default    | Description                                                                                      |
| --------- | ---------- | ------------------------------------------------------------------------------------------------ |
| `amount`  | empty      | Fixed amount in RWF. Leave empty to let the visitor choose.                                      |
| `min`     | `100`      | Lowest amount a visitor can choose, in RWF                                                       |
| `reason`  | `Payment`  | Description shown to the customer and stored with the payment                                    |
| `button`  | `Pay`      | Button label                                                                                     |
| `flow`    | `redirect` | `redirect` sends the visitor to Mulla's checkout page; `onsite` asks for the number on your page |

If the plugin isn't configured, the form is hidden from visitors and shows a notice to administrators.

### REST endpoints

All endpoints are under `/wp-json/mulla/v1/`.

| Method and path      | Used by                                                           |
| -------------------- | ----------------------------------------------------------------- |
| `POST /webhook`      | Mulla. Verifies the signature, then updates the order or payment. |
| `GET /orders/{id}`   | The WooCommerce waiting screen, to poll an order's payment status |
| `POST /payments`     | The payment form, to start a payment                              |
| `GET /payments/{id}` | The payment form, to poll a payment's status                      |

### wp-config.php constants

| Constant                    | Description                                             |
| --------------------------- | ------------------------------------------------------- |
| `MULLA_MODE`                | `sandbox` or `production` (`test` and `live` also work) |
| `MULLA_TEST_SECRET_KEY`     | Sandbox secret key (`sk_test_...`)                      |
| `MULLA_TEST_WEBHOOK_SECRET` | Sandbox webhook secret                                  |
| `MULLA_LIVE_SECRET_KEY`     | Production secret key (`sk_live_...`)                   |
| `MULLA_LIVE_WEBHOOK_SECRET` | Production webhook secret                               |

When a constant is defined, its field in **Mulla → Settings** becomes read-only.

## Troubleshooting

| Problem                                              | Likely cause and fix                                                                                                           |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| The payment method doesn't appear at checkout        | The store currency isn't RWF, no secret key is saved for the current environment, or the method is disabled.                   |
| The `[mulla_pay]` form is missing                    | The plugin isn't configured. Log in as an administrator to see the notice, then add the key in **Mulla → Settings**.           |
| Orders stay **Pending payment** for a long time      | Webhooks aren't arriving and WP-Cron isn't running. Check **Set up webhook**, and make sure WP-Cron (or a real cron job) runs. |
| An order is **On hold** after payment                | The amount paid doesn't match the order total. Check the order notes and the payment in the Mulla dashboard.                   |
| Webhooks return `401` or `403`                       | A security plugin, firewall or basic auth blocks `/wp-json/mulla/v1/webhook`. Allow that path.                                 |
| Webhooks are rejected after switching environment    | Sandbox and Production webhooks have different secrets. Save, then click **Set up webhook** again.                             |
| `composer install` doesn't create `vendor-prefixed/` | The prefix script didn't run. Run `composer prefix-namespaces`.                                                                |
| Refunds                                              | Not supported yet. Refund the customer from the Mulla dashboard.                                                               |

## Versioning and compatibility

<!-- x-release-please-start-version -->

| Plugin version | WordPress  | WooCommerce            | PHP  | Tested in CI on        |
| -------------- | ---------- | ---------------------- | ---- | ---------------------- |
| 0.0.1          | 6.4 to 7.1 | 8.0 to 11.3 (optional) | 8.1+ | PHP 8.1, 8.2, 8.3, 8.4 |

<!-- x-release-please-end -->

- The plugin follows [semantic versioning](https://semver.org). Until 1.0.0, a minor release (0.**x**.0) may contain breaking changes. Patch releases never do.
- Every change is listed in [CHANGELOG.md](CHANGELOG.md).
- Releases are made by release-please. Each release builds the zip, attaches it to the GitHub release and deploys it to WordPress.org.

## Support

- **Store owners:** use the plugin's support forum on WordPress.org, or contact Mulla support through your Mulla dashboard for account and payment questions.
- **Bugs and feature requests:** [open an issue](https://github.com/kaykorw/mulla-wordpress-plugin/issues). Include the plugin, WordPress, WooCommerce and PHP versions (Tools → Site Health → Info), and what you expected to happen. Never paste your API key or webhook secret.
- **Security problems:** don't open a public issue. Report them privately through the repository's [Security tab](https://github.com/kaykorw/mulla-wordpress-plugin/security).

## Contributing

Contributions are welcome. For anything bigger than a small fix, open an issue first so we can agree on the approach.

**1. Set up** (PHP 8.1 or newer and Composer; Docker and Node.js for wp-env): see [Installation](#installation) and [Run WordPress locally](#run-wordpress-locally).

**2. Make your change**, with tests in `tests/`. Update this file for developer-facing changes, and [readme.txt](readme.txt) for anything store owners see.

**3. Check everything** before you open a pull request:

```bash
composer check   # code style, static analysis (PHPStan level 8) and unit tests
```

Other commands:

| Command                      | What it does                          |
| ---------------------------- | ------------------------------------- |
| `composer test`              | Run the unit tests                    |
| `composer analyse`           | Static analysis                       |
| `composer cs`                | Check code style                      |
| `composer cs:fix`            | Fix code style                        |
| `composer prefix-namespaces` | Rebuild `vendor-prefixed/`            |
| `php bin/build-zip.php`      | Build the installable zip in `build/` |

**4. Open a pull request** against `main`. CI must pass.

**Rules:**

- **Follow the WordPress plugin guidelines:** escape output, sanitize input, check nonces and capabilities, and prefix everything with `mulla_payments` / `MullaPayments`.
- **Keep PHP 8.1 support.**
- **[Conventional Commits](https://www.conventionalcommits.org)** for commit messages (`feat:`, `fix:`, `docs:`, `chore:`). Releases and the changelog are generated from them.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

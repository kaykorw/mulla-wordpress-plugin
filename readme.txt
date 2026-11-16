=== Mulla Payments ===
Contributors: mulla
Tags: mobile money, momo, mtn, airtel, woocommerce
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept MTN Mobile Money and Airtel Money in Rwanda: a WooCommerce payment method and a payment form for any WordPress site.

== Description ==

Mulla Payments connects your site to Mulla, the mobile money gateway for Rwanda. Customers choose MTN MoMo or Airtel Money, enter their number, and approve the payment prompt on their phone. No card, no redirect.

**WooCommerce**

* "Mobile Money (MTN / Airtel)" payment method for the classic checkout and the block checkout.
* The order waits on a "Approve the payment on your phone" screen, then moves to Processing as soon as the payment succeeds.
* Payments are confirmed by a signed webhook, with automatic status checks as a fallback, so orders are completed even if a webhook is missed.
* Compatible with High-Performance Order Storage (HPOS).
* The store currency must be Rwandan franc (RWF).

**Any WordPress site**

* Add a payment form with the **Mulla Pay** block or the `[mulla_pay]` shortcode: fixed amounts (fees, tickets) or visitor-chosen amounts (donations).
* Every payment is listed under **Mulla → Payments**.

**Built for safety**

* Test mode with sandbox keys: no real money moves.
* Secret keys can be kept in `wp-config.php` instead of the database.
* Webhooks are verified with HMAC-SHA512 signatures; payment amounts are checked against the order.
* Payment confirmation never trusts the browser: the plugin always asks Mulla.

== Installation ==

1. Install and activate the plugin.
2. Go to **Mulla → Settings** and paste your secret key (`sk_test_…` for test mode) from the Mulla dashboard (Developers → API keys).
3. Click **Test connection**, then **Set up webhook**. The webhook URL and its secret are configured for you.
4. WooCommerce: set the currency to RWF, then enable the payment method under **WooCommerce → Settings → Payments → Mulla**.
5. Other sites: add the **Mulla Pay** block to a page, or the shortcode `[mulla_pay amount="5000" reason="Membership"]`.

When you are ready, switch the mode to **Live** and add your `sk_live_…` key.

= Keeping keys out of the database =

Add to `wp-config.php` (the matching fields in wp-admin become read-only):

`define( 'MULLA_MODE', 'live' );`
`define( 'MULLA_LIVE_SECRET_KEY', 'sk_live_...' );`
`define( 'MULLA_LIVE_WEBHOOK_SECRET', '...' );`

The test-mode equivalents are `MULLA_TEST_SECRET_KEY` and `MULLA_TEST_WEBHOOK_SECRET`.

== Frequently Asked Questions ==

= Which networks are supported? =

MTN Mobile Money and Airtel Money in Rwanda. You can turn either one off in the WooCommerce payment settings.

= The payment method does not appear at checkout. =

Check that the store currency is RWF, that a secret key for the current mode is saved, and that the method is enabled.

= My site runs on localhost. Do webhooks work? =

Mulla cannot reach a local site, but payments are still confirmed: the plugin checks the payment status on its own for about 20 minutes. To test webhooks locally, expose the site with a tunnel.

= Does the plugin support refunds? =

Not yet. Refund the customer from the Mulla dashboard.

= What does the shortcode accept? =

`[mulla_pay amount="5000" reason="Membership" button="Pay now"]` charges a fixed amount. Leave out `amount` to let the visitor choose it; `min` sets the lowest accepted amount (default 100 RWF).

= Is any personal data sent to Mulla? =

Only what a payment needs: the phone number, the amount, a short description such as "Order 123 at Your Shop", the order number and your site address. See the Mulla privacy policy for how Mulla processes payment data.

== External services ==

This plugin sends payment requests to the Mulla API (sandbox in test mode, production in live mode) when a customer pays, when a pending payment is checked, and when you use **Test connection** or **Set up webhook**. It sends the customer's phone number, the amount, the currency, a payment description, the order or form reference and your site address. Nothing is sent before a customer submits a payment or an administrator clicks one of those buttons.

== Changelog ==

= 0.0.1 =
* First release: WooCommerce payment method (classic and block checkout), Mulla Pay block and `[mulla_pay]` shortcode, signed webhooks with polling fallback, one-click webhook setup.

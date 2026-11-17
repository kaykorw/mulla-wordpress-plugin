<?php

/**
 * Plugin Name:       Mulla Payments
 * Plugin URI:        https://github.com/kaykorw/mulla-wordpress-plugin
 * Description:       Accept MTN Mobile Money and Airtel Money in Rwanda with Mulla: a WooCommerce payment method and a [mulla_pay] payment form for any site.
 * x-release-please-start-version
 * Version:           0.0.1
 * x-release-please-end
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Mulla
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mulla-payments
 * WC requires at least: 8.0
 * WC tested up to:   11.3
 *
 * @package MullaPayments
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('MULLA_PAYMENTS_VERSION', '0.0.1'); // x-release-please-version
define('MULLA_PAYMENTS_FILE', __FILE__);

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Mulla Payments requires PHP 8.1 or newer. Ask your host to upgrade PHP.', 'mulla-payments')
            . '</p></div>';
    });

    return;
}

$mulla_payments_sdk = __DIR__ . '/vendor-prefixed/autoload.php';
if (!is_file($mulla_payments_sdk)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Mulla Payments is incomplete: the bundled SDK is missing. Reinstall the plugin from its release zip.', 'mulla-payments')
            . '</p></div>';
    });

    return;
}
require $mulla_payments_sdk;

spl_autoload_register(static function (string $class): void {
    $prefix = 'MullaPayments\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0 || str_starts_with($class, 'MullaPayments\\Vendor\\')) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

(new MullaPayments\Plugin(__FILE__))->boot();

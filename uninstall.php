<?php

/**
 * Runs when the plugin is deleted from wp-admin. Removes settings and scheduled checks.
 * Payment records (Mulla → Payments) and WooCommerce order data are kept: they are financial records.
 *
 * @package MullaPayments
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('mulla_payments_settings');
delete_option('mulla_payments_device_id');
wp_unschedule_hook('mulla_payments_poll_charge');

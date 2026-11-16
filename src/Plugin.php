<?php

declare(strict_types=1);

namespace MullaPayments;

use MullaPayments\Admin\SettingsPage;
use MullaPayments\Payments\StatusPoller;
use MullaPayments\Settings\Settings;
use MullaPayments\Standalone\StandaloneModule;
use MullaPayments\Support\Logger;
use MullaPayments\Webhooks\WebhookController;
use MullaPayments\WooCommerce\WooCommerceModule;

/**
 * Composition root: creates the shared services once and hands them to the modules.
 *
 *   core        settings, Mulla client, webhook route, status poller (always)
 *   woocommerce payment gateway, classic + block checkout (when WooCommerce is active)
 *   standalone  [mulla_pay] form, "Mulla Pay" block and payments list (always)
 */
final class Plugin
{
    private readonly Settings $settings;
    private readonly Logger $logger;
    private readonly ClientFactory $clients;
    private readonly StatusPoller $poller;

    public function __construct(private readonly string $file)
    {
        $this->settings = new Settings();
        $this->logger = new Logger($this->settings);
        $this->clients = new ClientFactory($this->settings, $this->logger);
        $this->poller = new StatusPoller($this->clients, $this->logger);
    }

    public function boot(): void
    {
        add_action('init', function (): void {
            load_plugin_textdomain('mulla-payments', false, dirname(plugin_basename($this->file)) . '/languages');
        });

        $this->poller->register();

        $webhooks = new WebhookController($this->settings, $this->logger);
        add_action('rest_api_init', [$webhooks, 'register']);

        (new SettingsPage($this->file, $this->settings, $this->clients))->register();
        (new StandaloneModule($this->file, $this->settings, $this->clients, $this->poller, $this->logger))->register();

        // WooCommerce loads after us alphabetically: decide once all plugins are loaded.
        add_action('plugins_loaded', function (): void {
            if (class_exists('WooCommerce')) {
                (new WooCommerceModule($this->file, $this->settings, $this->clients, $this->poller, $this->logger))->register();
            }
        }, 11);

        add_action('before_woocommerce_init', function (): void {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $this->file, true);
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', $this->file, true);
            }
        });
    }
}

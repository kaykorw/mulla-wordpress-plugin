<?php

declare(strict_types=1);

namespace MullaPayments\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use MullaPayments\Settings\Settings;

/**
 * Makes the gateway available in the block-based Checkout (the default since WooCommerce 8.3).
 * The fields are rendered by assets/js/blocks-checkout.js and posted as payment data, which
 * WooCommerce hands to Gateway::process_payment() exactly like the classic checkout.
 */
final class BlocksIntegration extends AbstractPaymentMethodType
{
    protected $name = Gateway::ID;

    public function __construct(
        private readonly string $file,
        private readonly Gateway $gateway,
        private readonly Settings $pluginSettings,
    ) {}

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_' . Gateway::ID . '_settings', []);
    }

    public function is_active(): bool
    {
        return $this->gateway->is_available();
    }

    /** @return list<string> */
    public function get_payment_method_script_handles(): array
    {
        wp_register_script(
            'mulla-blocks-checkout',
            plugins_url('assets/js/blocks-checkout.js', $this->file),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
            MULLA_PAYMENTS_VERSION,
            true,
        );
        wp_set_script_translations('mulla-blocks-checkout', 'mulla-payments');
        wp_enqueue_style('mulla-payments', plugins_url('assets/css/mulla.css', $this->file), [], MULLA_PAYMENTS_VERSION);

        return ['mulla-blocks-checkout'];
    }

    /** @return array<string, mixed> */
    public function get_payment_method_data(): array
    {
        $providers = [];
        foreach ($this->gateway->enabledProviders() as $value => $label) {
            $providers[] = ['value' => $value, 'label' => $label];
        }

        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
            'icon' => $this->gateway->icon,
            'flow' => $this->gateway->flow(),
            'providers' => $providers,
            'testMode' => !$this->pluginSettings->isLive(),
            'supports' => array_values(array_filter($this->gateway->supports, [$this->gateway, 'supports'])),
            'i18n' => [
                'network' => __('Network', 'mulla-payments'),
                'phone' => __('Mobile Money number', 'mulla-payments'),
                'invalidPhone' => __('Enter a valid Rwandan Mobile Money number, e.g. 078 800 0000.', 'mulla-payments'),
                'testMode' => __('Test mode: no real money is taken.', 'mulla-payments'),
                'redirectNote' => __('You will be taken to a secure Mulla page to choose MTN or Airtel and approve the payment.', 'mulla-payments'),
            ],
        ];
    }
}

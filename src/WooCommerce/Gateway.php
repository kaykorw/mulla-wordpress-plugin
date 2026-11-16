<?php

declare(strict_types=1);

namespace MullaPayments\WooCommerce;

use MullaPayments\ClientFactory;
use MullaPayments\NotConfiguredException;
use MullaPayments\Payments\StatusPoller;
use MullaPayments\Settings\Settings;
use MullaPayments\Support\Logger;
use MullaPayments\Support\Phone;
use MullaPayments\Vendor\Mulla\Enums\Provider;
use MullaPayments\Vendor\Mulla\Exceptions\ApiException;
use MullaPayments\Vendor\Mulla\Exceptions\ConnectionException;
use MullaPayments\Vendor\Mulla\Exceptions\IdempotencyException;
use MullaPayments\Vendor\Mulla\Exceptions\InvalidRequestException;
use MullaPayments\Vendor\Mulla\Exceptions\MullaException;
use MullaPayments\Vendor\Mulla\Support\Uuid;
use WC_Order;
use WC_Payment_Gateway;

/**
 * "Mobile Money (MTN / Airtel)" payment method.
 *
 * The customer chooses the network and enters their number; Mulla sends a payment prompt to the
 * phone. The order stays "pending payment" until the webhook (or the status poller) confirms it.
 */
final class Gateway extends WC_Payment_Gateway
{
    public const ID = 'mulla';

    public function __construct(
        private readonly string $file,
        private readonly Settings $pluginSettings,
        private readonly ClientFactory $clients,
        private readonly StatusPoller $poller,
        private readonly Logger $logger,
    ) {
        $this->id = self::ID;
        $this->has_fields = true;
        $this->method_title = __('Mulla (MTN MoMo & Airtel Money)', 'mulla-payments');
        $this->method_description = sprintf(
            /* translators: %s: link to the Mulla settings page */
            __('Customers pay with MTN Mobile Money or Airtel Money and approve the prompt on their phone. API keys and webhooks are set in %s.', 'mulla-payments'),
            '<a href="' . esc_url(admin_url('admin.php?page=mulla-payments')) . '">' . esc_html__('Mulla → Settings', 'mulla-payments') . '</a>',
        );
        $this->supports = ['products'];
        $this->icon = plugins_url('assets/img/mobile-money.svg', $this->file);

        $this->init_form_fields();
        $this->init_settings();

        $this->title = (string) $this->get_option('title');
        $this->description = (string) $this->get_option('description');
        $this->enabled = (string) $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, function (): void {
            $this->process_admin_options();
        });
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable', 'mulla-payments'),
                'type' => 'checkbox',
                'label' => __('Accept Mobile Money with Mulla', 'mulla-payments'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'mulla-payments'),
                'type' => 'text',
                'description' => __('Name of the payment method at checkout.', 'mulla-payments'),
                'default' => __('Mobile Money (MTN / Airtel)', 'mulla-payments'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'mulla-payments'),
                'type' => 'textarea',
                'default' => __('Pay with MTN Mobile Money or Airtel Money. You will receive a prompt on your phone to approve the payment.', 'mulla-payments'),
            ],
            'providers' => [
                'title' => __('Networks', 'mulla-payments'),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'options' => self::providerOptions(),
                'default' => array_keys(self::providerOptions()),
            ],
        ];
    }

    /** @return array<string, string> provider value => label for the networks enabled in the settings */
    public function enabledProviders(): array
    {
        $enabled = $this->get_option('providers', array_keys(self::providerOptions()));
        $enabled = is_array($enabled) && $enabled !== [] ? $enabled : array_keys(self::providerOptions());

        return array_intersect_key(self::providerOptions(), array_flip($enabled));
    }

    public function is_available(): bool
    {
        return parent::is_available()
            && get_woocommerce_currency() === 'RWF'
            && $this->pluginSettings->isConfigured();
    }

    public function payment_fields(): void
    {
        if ($this->description !== '') {
            echo wp_kses_post(wpautop(wptexturize($this->description)));
        }
        if (!$this->pluginSettings->isLive()) {
            echo '<p class="mulla-test-mode">' . esc_html__('Test mode: no real money is taken.', 'mulla-payments') . '</p>';
        }

        $phone = WC()->customer ? (string) WC()->customer->get_billing_phone() : '';
        $guess = ($normalized = Phone::normalize($phone)) !== null ? Phone::guessProvider($normalized)?->value : null;

        echo '<fieldset id="mulla-payment-fields" class="mulla-fields">';
        echo '<p class="form-row form-row-wide"><label>' . esc_html__('Network', 'mulla-payments') . ' <span class="required">*</span></label>';
        $first = true;
        foreach ($this->enabledProviders() as $value => $label) {
            $checked = $guess !== null ? $guess === $value : $first;
            printf(
                '<label class="mulla-provider"><input type="radio" name="mulla_provider" value="%s" %s> %s</label> ',
                esc_attr($value),
                checked($checked, true, false),
                esc_html($label),
            );
            $first = false;
        }
        echo '</p>';
        printf(
            '<p class="form-row form-row-wide"><label for="mulla_phone">%s <span class="required">*</span></label>'
            . '<input id="mulla_phone" name="mulla_phone" type="tel" class="input-text" autocomplete="tel" inputmode="tel" placeholder="078 000 0000" value="%s"></p>',
            esc_html__('Mobile Money number', 'mulla-payments'),
            esc_attr($phone),
        );
        echo '</fieldset>';
    }

    public function validate_fields(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
        $phone = Phone::normalize(sanitize_text_field(wp_unslash((string) ($_POST['mulla_phone'] ?? ''))));
        if ($phone === null) {
            wc_add_notice(__('Enter a valid Rwandan Mobile Money number, e.g. 078 800 0000.', 'mulla-payments'), 'error');

            return false;
        }

        if ($this->postedProvider($phone) === null) {
            wc_add_notice(__('Choose MTN Mobile Money or Airtel Money.', 'mulla-payments'), 'error');

            return false;
        }

        return true;
    }

    /**
     * @param int $order_id
     * @return array{result: string, redirect?: string}
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return ['result' => 'failure'];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
        $phone = Phone::normalize(sanitize_text_field(wp_unslash((string) ($_POST['mulla_phone'] ?? ''))));
        $provider = $phone !== null ? $this->postedProvider($phone) : null;
        if ($phone === null || $provider === null) {
            $this->validate_fields();

            return ['result' => 'failure'];
        }

        // A prompt is already waiting for this order (double click, back button): don't send another.
        if ($order->get_meta(WooCommerceModule::META_CHARGE_ID) !== '' && $order->get_meta(WooCommerceModule::META_CHARGE_STATUS) === 'PENDING') {
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        $key = Uuid::v4();
        $order->update_meta_data(WooCommerceModule::META_IDEMPOTENCY_KEY, $key);

        try {
            $charge = $this->clients->client()->charges->initiate([
                'provider' => $provider,
                'phone_number' => $phone,
                'amount' => (int) round((float) $order->get_total()),
                'currency' => 'RWF',
                'reason' => mb_substr(sprintf(
                    /* translators: 1: order number, 2: site name */
                    __('Order %1$s at %2$s', 'mulla-payments'),
                    $order->get_order_number(),
                    wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                ), 0, 120),
                'application' => 'woocommerce',
                'metadata' => ['order_id' => $order->get_id(), 'order_number' => $order->get_order_number(), 'site' => home_url()],
            ], ['idempotency_key' => $key]);
        } catch (MullaException $e) {
            return $this->failure($order, $e);
        }

        $order->update_meta_data(WooCommerceModule::META_CHARGE_ID, $charge->id);
        $order->update_meta_data(WooCommerceModule::META_CHARGE_STATUS, $charge->status?->value ?? 'PENDING');
        $order->add_order_note(sprintf(
            /* translators: 1: network, 2: masked phone, 3: Mulla reference */
            __('Mulla payment prompt sent to %1$s %2$s (reference %3$s). Waiting for the customer to approve.', 'mulla-payments'),
            $provider->label(),
            Phone::mask($phone),
            (string) $charge->reference,
        ));
        $order->save();

        $this->poller->schedule($charge->id);
        /** @var \WC_Cart|null $cart null outside a frontend request, despite the stub's type */
        $cart = WC()->cart;
        $cart?->empty_cart();

        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    /** @return array{result: string} */
    private function failure(WC_Order $order, MullaException $e): array
    {
        $this->logger->error('Charge could not be started: ' . $e->getMessage(), ['order' => $order->get_id()]);
        $order->save();

        $message = match (true) {
            $e instanceof InvalidRequestException => __('The payment could not be started: please check your number and network, then try again.', 'mulla-payments'),
            $e instanceof IdempotencyException => __('A payment request was already sent for this order. Check your phone, or try again in a minute.', 'mulla-payments'),
            $e instanceof ConnectionException && $e->requestMayHaveBeenSent() => __('We could not confirm the payment request. If you receive a prompt on your phone, approve it; otherwise try again in a minute.', 'mulla-payments'),
            $e instanceof NotConfiguredException => __('This payment method is temporarily unavailable. Please choose another one.', 'mulla-payments'),
            $e instanceof ApiException && $e->getStatusCode() === 429 => __('Too many attempts. Please wait a minute and try again.', 'mulla-payments'),
            default => __('The payment service is temporarily unavailable. Please try again in a minute.', 'mulla-payments'),
        };
        wc_add_notice($message, 'error');

        return ['result' => 'failure'];
    }

    private function postedProvider(string $phone): ?Provider
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
        $posted = sanitize_text_field(wp_unslash((string) ($_POST['mulla_provider'] ?? '')));
        $provider = Provider::tryFrom($posted) ?? Phone::guessProvider($phone);

        return $provider !== null && isset($this->enabledProviders()[$provider->value]) ? $provider : null;
    }

    /** @return array<string, string> */
    private static function providerOptions(): array
    {
        $options = [];
        foreach (Provider::cases() as $provider) {
            $options[$provider->value] = $provider->label();
        }

        return $options;
    }
}

<?php

declare(strict_types=1);

namespace MullaPayments\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use MullaPayments\ClientFactory;
use MullaPayments\Payments\ChargeUpdate;
use MullaPayments\Payments\StatusPoller;
use MullaPayments\Settings\Settings;
use MullaPayments\Support\Logger;
use MullaPayments\Webhooks\WebhookController;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

/**
 * WooCommerce integration: registers the gateway (classic + block checkout), the
 * "approve on your phone" status endpoint, and turns charge updates into order changes.
 */
final class WooCommerceModule
{
    public const META_CHARGE_ID = '_mulla_charge_id';
    public const META_CHARGE_STATUS = '_mulla_charge_status';
    public const META_IDEMPOTENCY_KEY = '_mulla_idempotency_key';
    public const META_LAST_CHECK = '_mulla_last_check';

    private ?Gateway $gateway = null;

    public function __construct(
        private readonly string $file,
        private readonly Settings $settings,
        private readonly ClientFactory $clients,
        private readonly StatusPoller $poller,
        private readonly Logger $logger,
    ) {}

    public function register(): void
    {
        add_filter('woocommerce_payment_gateways', function (array $gateways): array {
            $gateways[] = $this->gateway();

            return $gateways;
        });

        add_action('woocommerce_blocks_payment_method_type_registration', function (PaymentMethodRegistry $registry): void {
            $registry->register(new BlocksIntegration($this->file, $this->gateway(), $this->settings));
        });

        add_action('mulla_payments_charge_updated', [$this, 'applyChargeUpdate']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('woocommerce_thankyou_' . Gateway::ID, [$this, 'renderWaitingScreen']);
    }

    public function gateway(): Gateway
    {
        return $this->gateway ??= new Gateway($this->file, $this->settings, $this->clients, $this->poller, $this->logger);
    }

    /** Charge reached a new state (webhook or polling): update the matching order. Idempotent. */
    public function applyChargeUpdate(ChargeUpdate $update): void
    {
        $order = self::findOrder($update->chargeId);
        if ($order === null) {
            return;
        }

        $order->update_meta_data(self::META_CHARGE_STATUS, $update->status->value);

        if ($order->is_paid()) {
            $order->save();

            return;
        }

        if ($update->isSuccessful()) {
            $expected = (int) round((float) $order->get_total());
            if ($update->amount !== null && (int) round((float) $update->amount) !== $expected) {
                $order->update_status('on-hold', sprintf(
                    /* translators: 1: paid amount, 2: order total */
                    __('Mulla reported a payment of %1$s RWF but the order total is %2$s RWF. Check the payment before shipping.', 'mulla-payments'),
                    (string) $update->amount,
                    (string) $expected,
                ));
                $this->logger->warning('Amount mismatch', ['order' => $order->get_id(), 'charge' => $update->chargeId]);

                return;
            }

            $order->add_order_note(sprintf(
                /* translators: 1: Mulla reference, 2: source (webhook/api) */
                __('Mulla payment successful (reference %1$s, confirmed by %2$s).', 'mulla-payments'),
                $update->reference ?? $update->chargeId,
                $update->source,
            ));
            $order->payment_complete($update->reference ?? $update->chargeId);

            return;
        }

        if ($update->isFinal() && $order->has_status(['pending', 'on-hold'])) {
            $reason = $update->status->value === 'EXPIRED'
                ? __('The customer did not approve the payment in time.', 'mulla-payments')
                : ($update->failureMessage ?? __('The payment was declined or failed.', 'mulla-payments'));
            /* translators: %s: failure reason */
            $order->update_status('failed', sprintf(__('Mulla payment failed: %s', 'mulla-payments'), $reason));

            return;
        }

        $order->save();
    }

    public function registerRoutes(): void
    {
        register_rest_route(WebhookController::NAMESPACE, '/orders/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'orderStatus'],
            // The order key (from the order-received URL) proves the visitor owns the order.
            'permission_callback' => '__return_true',
            'args' => ['key' => ['required' => true, 'type' => 'string']],
        ]);
    }

    /** Polled by the waiting screen. Checks Mulla directly at most every 10 s in case a webhook is late. */
    public function orderStatus(WP_REST_Request $request): WP_REST_Response
    {
        $order = wc_get_order((int) $request['id']);
        if (!$order instanceof WC_Order || !hash_equals($order->get_order_key(), (string) $request['key'])) {
            return new WP_REST_Response(['message' => 'Order not found.'], 404);
        }

        $chargeId = (string) $order->get_meta(self::META_CHARGE_ID);
        if (!$order->is_paid() && $chargeId !== '' && $order->has_status(['pending', 'on-hold'])) {
            $last = (int) $order->get_meta(self::META_LAST_CHECK);
            if (time() - $last >= 10) {
                $order->update_meta_data(self::META_LAST_CHECK, (string) time());
                $order->save();
                $this->poller->check($chargeId);
                $order = wc_get_order($order->get_id());
            }
        }

        return new WP_REST_Response(self::statusPayload($order instanceof WC_Order ? $order : null));
    }

    /** Order-received page: shows "approve on your phone" and follows the payment live. */
    public function renderWaitingScreen(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order || $order->is_paid() || (string) $order->get_meta(self::META_CHARGE_ID) === '') {
            return;
        }

        wp_enqueue_script('mulla-order-status', plugins_url('assets/js/order-status.js', $this->file), [], MULLA_PAYMENTS_VERSION, true);
        wp_enqueue_style('mulla-payments', plugins_url('assets/css/mulla.css', $this->file), [], MULLA_PAYMENTS_VERSION);

        $payload = self::statusPayload($order);
        printf(
            '<div class="mulla-status" data-mulla-order-status data-url="%s" data-state="%s" data-retry-label="%s" role="status" aria-live="polite">'
            . '<span class="mulla-status__spinner" aria-hidden="true"></span><p class="mulla-status__message">%s</p></div>',
            esc_url(add_query_arg('key', $order->get_order_key(), rest_url(WebhookController::NAMESPACE . '/orders/' . $order->get_id()))),
            esc_attr($payload['state']),
            esc_attr__('Try again', 'mulla-payments'),
            esc_html($payload['message']),
        );
    }

    /** @return array{state: string, message: string, retry_url?: string} */
    private static function statusPayload(?WC_Order $order): array
    {
        if ($order === null) {
            return ['state' => 'failed', 'message' => __('Order not found.', 'mulla-payments')];
        }
        if ($order->is_paid()) {
            return ['state' => 'paid', 'message' => __('Payment received. Thank you!', 'mulla-payments')];
        }
        if ($order->has_status('failed')) {
            return [
                'state' => 'failed',
                'message' => __('The payment did not go through. You can try again.', 'mulla-payments'),
                'retry_url' => $order->get_checkout_payment_url(),
            ];
        }
        if ($order->has_status('on-hold')) {
            return ['state' => 'review', 'message' => __('We received your payment and are reviewing it. You will get an email shortly.', 'mulla-payments')];
        }

        return ['state' => 'pending', 'message' => __('Approve the payment on your phone: check for the Mobile Money prompt and enter your PIN.', 'mulla-payments')];
    }

    public static function findOrder(string $chargeId): ?WC_Order
    {
        $orders = wc_get_orders([
            'limit' => 1,
            'payment_method' => Gateway::ID,
            'meta_query' => [['key' => self::META_CHARGE_ID, 'value' => $chargeId]],
        ]);
        $order = is_array($orders) ? ($orders[0] ?? null) : null;

        return $order instanceof WC_Order ? $order : null;
    }
}

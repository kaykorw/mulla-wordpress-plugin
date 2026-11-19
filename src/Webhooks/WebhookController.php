<?php

declare(strict_types=1);

namespace MullaPayments\Webhooks;

use MullaPayments\Payments\ChargeUpdate;
use MullaPayments\Settings\Settings;
use MullaPayments\Support\Logger;
use MullaPayments\Vendor\Mulla\Exceptions\SignatureVerificationException;
use MullaPayments\Vendor\Mulla\Webhooks\Event;
use MullaPayments\Vendor\Mulla\Webhooks\Webhook;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/mulla/v1/webhook
 *
 * Verifies the signature on the raw body, then publishes the event through WordPress actions:
 *   - mulla_payments_webhook              (every event)
 *   - mulla_payments_webhook_{type}       (e.g. mulla_payments_webhook_charge.success)
 *   - mulla_payments_charge_updated       (charge.* events, shared with the status poller)
 *
 * Handlers are idempotent, so Mulla's redeliveries are harmless.
 */
final class WebhookController
{
    public const NAMESPACE = 'mulla/v1';
    public const ROUTE = '/webhook';

    public function __construct(
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {}

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            // Public by design: authenticity is proven by the HMAC signature, not by a WordPress login.
            'permission_callback' => '__return_true',
        ]);
    }

    public static function url(): string
    {
        return rest_url(self::NAMESPACE . self::ROUTE);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $secret = $this->settings->webhookSecret();
        if ($secret === '') {
            $this->logger->error('Webhook received but no ' . Settings::modeLabel($this->settings->mode()) . ' webhook secret is configured (Mulla → Settings).');

            return new WP_REST_Response(['message' => 'Webhook secret is not configured.'], 500);
        }

        try {
            $event = (new Webhook($secret))->parse($request->get_body(), $this->headers($request));
        } catch (SignatureVerificationException $e) {
            $this->logger->warning('Webhook rejected: ' . $e->getMessage());

            return new WP_REST_Response(['message' => 'Invalid Mulla signature.'], 400);
        }

        try {
            $this->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook handler failed: ' . $e->getMessage(), ['event' => $event->type, 'id' => $event->id]);

            return new WP_REST_Response(['status' => 'error'], 500);
        }

        $this->logger->info('Webhook processed', ['event' => $event->type, 'id' => $event->id]);

        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    private function dispatch(Event $event): void
    {
        do_action('mulla_payments_webhook', $event);
        do_action('mulla_payments_webhook_' . $event->type, $event);

        if (str_starts_with($event->type, 'charge.')) {
            $update = ChargeUpdate::fromEvent($event);
            if ($update !== null) {
                do_action('mulla_payments_charge_updated', $update);
            }
        }
    }

    /** @return array<string, string> */
    private function headers(WP_REST_Request $request): array
    {
        $headers = [];
        foreach ($request->get_headers() as $name => $values) {
            // WordPress stores header names lower-cased with "_" instead of "-".
            $headers[str_replace('_', '-', strtolower((string) $name))] = is_array($values) ? (string) reset($values) : (string) $values;
        }

        return $headers;
    }
}

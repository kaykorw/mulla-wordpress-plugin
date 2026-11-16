<?php

declare(strict_types=1);

namespace MullaPayments\Standalone;

use MullaPayments\ClientFactory;
use MullaPayments\Payments\ChargeUpdate;
use MullaPayments\Payments\StatusPoller;
use MullaPayments\Settings\Settings;
use MullaPayments\Support\Logger;
use MullaPayments\Support\Phone;
use MullaPayments\Vendor\Mulla\Enums\Provider;
use MullaPayments\Vendor\Mulla\Exceptions\ConnectionException;
use MullaPayments\Vendor\Mulla\Exceptions\InvalidRequestException;
use MullaPayments\Vendor\Mulla\Exceptions\MullaException;
use MullaPayments\Vendor\Mulla\Support\Uuid;
use MullaPayments\Webhooks\WebhookController;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Payment form for any WordPress site (no WooCommerce needed):
 *
 *     [mulla_pay amount="5000" reason="Membership fee"]
 *     [mulla_pay reason="Donation" min="500"]            visitor enters the amount
 *
 * Also available as the "Mulla Pay" block. The visitor picks the network, enters their number,
 * approves the prompt on their phone, and the form shows the result live.
 */
final class StandaloneModule
{
    private const RATE_LIMIT = 5;
    private const RATE_WINDOW = 600;
    private const MIN_AMOUNT = 100;
    private const MAX_AMOUNT = 5_000_000;

    private readonly PaymentRecords $records;

    public function __construct(
        private readonly string $file,
        private readonly Settings $settings,
        private readonly ClientFactory $clients,
        private readonly StatusPoller $poller,
        private readonly Logger $logger,
    ) {
        $this->records = new PaymentRecords();
    }

    public function register(): void
    {
        add_action('init', [$this->records, 'register']);
        add_action('init', [$this, 'registerBlock']);
        add_shortcode('mulla_pay', [$this, 'renderShortcode']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('mulla_payments_charge_updated', [$this, 'applyChargeUpdate']);
    }

    public function registerBlock(): void
    {
        register_block_type(dirname($this->file) . '/blocks/pay', [
            'render_callback' => fn(array $attributes): string => $this->renderShortcode([
                'amount' => (string) ($attributes['amount'] ?? ''),
                'reason' => (string) ($attributes['reason'] ?? ''),
                'button' => (string) ($attributes['button'] ?? ''),
                'min' => (string) ($attributes['min'] ?? ''),
            ]),
        ]);
    }

    /** @param array<string, string>|string $atts */
    public function renderShortcode(array|string $atts = []): string
    {
        $atts = shortcode_atts([
            'amount' => '',
            'reason' => __('Payment', 'mulla-payments'),
            'button' => __('Pay', 'mulla-payments'),
            'min' => (string) self::MIN_AMOUNT,
        ], is_array($atts) ? $atts : [], 'mulla_pay');

        if (!$this->settings->isConfigured()) {
            return current_user_can('manage_options')
                ? '<p class="mulla-notice">' . esc_html__('Mulla Pay: add your secret key in Mulla → Settings to show this form.', 'mulla-payments') . '</p>'
                : '';
        }

        $amount = absint($atts['amount']);
        $reason = sanitize_text_field($atts['reason']);
        $min = max(self::MIN_AMOUNT, absint($atts['min']));
        $form = $this->signForm($amount, $reason, $min, (int) get_the_ID());

        wp_enqueue_script('mulla-pay-form', plugins_url('assets/js/pay-form.js', $this->file), [], MULLA_PAYMENTS_VERSION, true);
        wp_enqueue_style('mulla-payments', plugins_url('assets/css/mulla.css', $this->file), [], MULLA_PAYMENTS_VERSION);

        $providers = '';
        foreach (Provider::cases() as $i => $provider) {
            $providers .= sprintf(
                '<label class="mulla-provider"><input type="radio" name="provider" value="%s"%s required> %s</label>',
                esc_attr($provider->value),
                $i === 0 ? ' checked' : '',
                esc_html($provider->label()),
            );
        }

        $amountField = $amount > 0
            ? sprintf('<p class="mulla-pay__amount">%s</p>', esc_html(number_format_i18n($amount) . ' RWF'))
            : sprintf(
                '<p><label>%s<br><input type="number" name="amount" min="%d" max="%d" step="1" required inputmode="numeric"> RWF</label></p>',
                esc_html__('Amount', 'mulla-payments'),
                $min,
                self::MAX_AMOUNT,
            );

        return sprintf(
            '<form class="mulla-pay" data-mulla-pay data-endpoint="%1$s" novalidate>'
            . '<p class="mulla-pay__reason">%2$s</p>%3$s'
            . '<input type="hidden" name="form" value="%4$s">'
            . '<fieldset class="mulla-pay__providers"><legend>%5$s</legend>%6$s</fieldset>'
            . '<p><label>%7$s<br><input type="tel" name="phone" autocomplete="tel" inputmode="tel" placeholder="078 000 0000" required></label></p>'
            . '<p><label>%8$s<br><input type="text" name="name" autocomplete="name" maxlength="80"></label></p>'
            . '%9$s'
            . '<button type="submit" class="mulla-pay__button wp-element-button">%10$s</button>'
            . '<div class="mulla-status" role="status" aria-live="polite" hidden><span class="mulla-status__spinner" aria-hidden="true"></span><p class="mulla-status__message"></p></div>'
            . '</form>',
            esc_url(rest_url(WebhookController::NAMESPACE . '/payments')),
            esc_html($reason),
            $amountField,
            esc_attr($form),
            esc_html__('Network', 'mulla-payments'),
            $providers,
            esc_html__('Mobile Money number', 'mulla-payments'),
            esc_html__('Your name (optional)', 'mulla-payments'),
            $this->settings->isLive() ? '' : '<p class="mulla-test-mode">' . esc_html__('Test mode: no real money is taken.', 'mulla-payments') . '</p>',
            esc_html(sanitize_text_field($atts['button'])),
        );
    }

    public function registerRoutes(): void
    {
        register_rest_route(WebhookController::NAMESPACE, '/payments', [
            'methods' => 'POST',
            'callback' => [$this, 'startPayment'],
            // Public form. Abuse is limited by the signed form token and a per-IP rate limit.
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(WebhookController::NAMESPACE, '/payments/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'paymentStatus'],
            // The random access token returned when the payment started proves ownership.
            'permission_callback' => '__return_true',
        ]);
    }

    public function startPayment(WP_REST_Request $request): WP_REST_Response
    {
        $form = $this->verifyForm((string) $request->get_param('form'));
        if ($form === null) {
            return self::error(__('This form has expired. Reload the page and try again.', 'mulla-payments'), 400);
        }

        if (!$this->withinRateLimit()) {
            return self::error(__('Too many attempts. Please wait a few minutes and try again.', 'mulla-payments'), 429);
        }

        $phone = Phone::normalize(sanitize_text_field((string) $request->get_param('phone')));
        if ($phone === null) {
            return self::error(__('Enter a valid Rwandan Mobile Money number, e.g. 078 800 0000.', 'mulla-payments'), 422);
        }
        $provider = Provider::tryFrom((string) $request->get_param('provider')) ?? Phone::guessProvider($phone);
        if ($provider === null) {
            return self::error(__('Choose MTN Mobile Money or Airtel Money.', 'mulla-payments'), 422);
        }

        $amount = $form['amount'] > 0 ? $form['amount'] : absint($request->get_param('amount'));
        if ($amount < $form['min'] || $amount > self::MAX_AMOUNT) {
            /* translators: %s: minimum amount */
            return self::error(sprintf(__('Enter an amount of at least %s RWF.', 'mulla-payments'), number_format_i18n($form['min'])), 422);
        }

        $payer = mb_substr(sanitize_text_field((string) $request->get_param('name')), 0, 80);
        $id = $this->records->create($amount, $phone, $provider, $form['reason'], $payer, $form['page']);

        try {
            $charge = $this->clients->client()->charges->initiate([
                'provider' => $provider,
                'phone_number' => $phone,
                'amount' => $amount,
                'currency' => 'RWF',
                'reason' => mb_substr($form['reason'], 0, 120),
                'application' => 'wordpress',
                'metadata' => ['payment_id' => $id, 'page_id' => $form['page'], 'site' => home_url()],
            ], ['idempotency_key' => Uuid::v4()]);
        } catch (MullaException $e) {
            $this->records->set($id, 'status', 'FAILED');
            $this->logger->error('Form payment could not be started: ' . $e->getMessage(), ['payment' => $id]);

            return self::error(match (true) {
                $e instanceof InvalidRequestException => __('The payment could not be started: check your number and network, then try again.', 'mulla-payments'),
                $e instanceof ConnectionException && $e->requestMayHaveBeenSent() => __('We could not confirm the payment request. If you receive a prompt on your phone, approve it.', 'mulla-payments'),
                default => __('The payment service is temporarily unavailable. Please try again in a minute.', 'mulla-payments'),
            }, 502);
        }

        $this->records->set($id, 'charge_id', $charge->id);
        $this->records->set($id, 'reference', (string) $charge->reference);
        $this->records->set($id, 'status', $charge->status?->value ?? 'PENDING');
        $this->poller->schedule($charge->id);

        return new WP_REST_Response([
            'payment' => $id,
            'token' => $this->records->get($id, 'access_token'),
            'status' => 'PENDING',
            'message' => sprintf(
                /* translators: 1: network, 2: masked phone */
                __('We sent a payment request to %1$s %2$s. Approve it on your phone with your PIN.', 'mulla-payments'),
                $provider->label(),
                Phone::mask($phone),
            ),
        ], 201);
    }

    public function paymentStatus(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request['id'];
        if (!$this->records->exists($id) || !hash_equals($this->records->get($id, 'access_token'), (string) $request->get_param('token'))) {
            return self::error(__('Payment not found.', 'mulla-payments'), 404);
        }

        $chargeId = $this->records->get($id, 'charge_id');
        if ($this->records->get($id, 'status') === 'PENDING' && $chargeId !== '' && time() - (int) $this->records->get($id, 'last_check') >= 10) {
            $this->records->set($id, 'last_check', time());
            $this->poller->check($chargeId);
        }

        $status = $this->records->get($id, 'status');

        return new WP_REST_Response([
            'status' => $status,
            'message' => match ($status) {
                'SUCCESSFUL' => sprintf(
                    /* translators: %s: Mulla reference */
                    __('Payment received. Thank you! Reference: %s', 'mulla-payments'),
                    $this->records->get($id, 'reference'),
                ),
                'FAILED' => __('The payment did not go through. You can try again.', 'mulla-payments'),
                'EXPIRED' => __('The payment request expired before it was approved. You can try again.', 'mulla-payments'),
                default => __('Waiting for you to approve the payment on your phone…', 'mulla-payments'),
            },
        ]);
    }

    /** Charge reached a new state: update the matching form payment. Idempotent. */
    public function applyChargeUpdate(ChargeUpdate $update): void
    {
        $id = $this->records->findByCharge($update->chargeId);
        if ($id === null || $this->records->get($id, 'status') === $update->status->value) {
            return;
        }

        $this->records->set($id, 'status', $update->status->value);
        if ($update->reference !== null) {
            $this->records->set($id, 'reference', $update->reference);
        }

        if ($update->isSuccessful()) {
            /**
             * A [mulla_pay] payment succeeded. Use it to send a receipt, unlock content, etc.
             *
             * @param int          $paymentId post id of the mulla_payment record
             * @param ChargeUpdate $update
             */
            do_action('mulla_payments_form_payment_succeeded', $id, $update);
        }
    }

    /** Signed description of the form, so the amount and reason cannot be changed in the browser. */
    private function signForm(int $amount, string $reason, int $min, int $page): string
    {
        $payload = base64_encode((string) wp_json_encode(['a' => $amount, 'r' => $reason, 'm' => $min, 'p' => $page]));

        return $payload . '.' . hash_hmac('sha256', $payload, wp_salt('auth') . 'mulla_pay');
    }

    /** @return array{amount: int, reason: string, min: int, page: int}|null */
    private function verifyForm(string $token): ?array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || !hash_equals(hash_hmac('sha256', $payload, wp_salt('auth') . 'mulla_pay'), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode($payload, true), true);
        if (!is_array($data)) {
            return null;
        }

        return [
            'amount' => absint($data['a'] ?? 0),
            'reason' => sanitize_text_field((string) ($data['r'] ?? '')),
            'min' => max(self::MIN_AMOUNT, absint($data['m'] ?? self::MIN_AMOUNT)),
            'page' => absint($data['p'] ?? 0),
        ];
    }

    private function withinRateLimit(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : 'unknown';
        $key = 'mulla_pay_rl_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            return false;
        }
        set_transient($key, $count + 1, self::RATE_WINDOW);

        return true;
    }

    private static function error(string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['message' => $message], $status);
    }
}

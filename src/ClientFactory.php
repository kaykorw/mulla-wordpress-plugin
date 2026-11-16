<?php

declare(strict_types=1);

namespace MullaPayments;

use MullaPayments\Http\WordPressTransport;
use MullaPayments\Settings\Settings;
use MullaPayments\Support\Logger;
use MullaPayments\Vendor\Mulla\Exceptions\InvalidArgumentException;
use MullaPayments\Vendor\Mulla\MullaClient;
use MullaPayments\Vendor\Mulla\Support\Uuid;

/**
 * Factory: builds the Mulla client from the plugin settings (one per request, reused).
 */
final class ClientFactory
{
    private const DEVICE_ID_OPTION = 'mulla_payments_device_id';

    private ?MullaClient $client = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {}

    /**
     * @throws NotConfiguredException when no valid secret key is set for the active mode
     */
    public function client(): MullaClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (!$this->settings->isConfigured()) {
            throw new NotConfiguredException(sprintf(
                /* translators: %s: test or live */
                __('Mulla is not configured: add your %s secret key in Settings → Mulla Payments.', 'mulla-payments'),
                $this->settings->mode(),
            ));
        }

        $options = [
            'transport' => new WordPressTransport(),
            'deviceId' => $this->deviceId(),
            'appInfo' => 'mulla-wordpress/' . MULLA_PAYMENTS_VERSION . ' wordpress/' . get_bloginfo('version')
                . (defined('WC_VERSION') ? ' woocommerce/' . WC_VERSION : ''),
            'logger' => $this->logger->sdkLogger(),
            'timeout' => 30,
            'maxRetries' => 1,
        ];
        if ($this->settings->baseUrl() !== null) {
            $options['baseUrl'] = $this->settings->baseUrl();
        }

        try {
            return $this->client = new MullaClient($this->settings->secretKey(), $options);
        } catch (InvalidArgumentException $e) {
            throw new NotConfiguredException($e->getMessage(), 0, $e);
        }
    }

    /** Forget the cached client (after settings change). */
    public function reset(): void
    {
        $this->client = null;
    }

    /** Stable per-site UUID sent as x-device-id. */
    private function deviceId(): string
    {
        $id = get_option(self::DEVICE_ID_OPTION);
        if (!is_string($id) || !Uuid::isValidV4($id)) {
            $id = Uuid::v4();
            update_option(self::DEVICE_ID_OPTION, $id, false);
        }

        return $id;
    }
}

<?php

declare(strict_types=1);

namespace MullaPayments\Settings;

/**
 * Plugin settings, stored in one option. Every secret can instead be defined as a constant in
 * wp-config.php (recommended on production), which then wins and is never shown in wp-admin:
 *
 *     define('MULLA_MODE', 'live');
 *     define('MULLA_LIVE_SECRET_KEY', 'sk_live_...');
 *     define('MULLA_LIVE_WEBHOOK_SECRET', '...');
 */
final class Settings
{
    public const OPTION = 'mulla_payments_settings';

    /** @var array<string, string> setting => constant that overrides it */
    private const CONSTANTS = [
        'mode' => 'MULLA_MODE',
        'test_secret_key' => 'MULLA_TEST_SECRET_KEY',
        'live_secret_key' => 'MULLA_LIVE_SECRET_KEY',
        'test_webhook_secret' => 'MULLA_TEST_WEBHOOK_SECRET',
        'live_webhook_secret' => 'MULLA_LIVE_WEBHOOK_SECRET',
        'base_url' => 'MULLA_BASE_URL',
    ];

    private const DEFAULTS = [
        'mode' => 'test',
        'test_secret_key' => '',
        'live_secret_key' => '',
        'test_webhook_secret' => '',
        'live_webhook_secret' => '',
        'base_url' => '',
        'debug' => 'no',
    ];

    /** 'test' (shown as Sandbox) or 'live' (shown as Production). 'production' and 'sandbox' are accepted too. */
    public function mode(): string
    {
        return in_array($this->get('mode'), ['live', 'production'], true) ? 'live' : 'test';
    }

    /** The name merchants see for a mode: "Sandbox" or "Production". */
    public static function modeLabel(string $mode): string
    {
        return $mode === 'live' ? __('Production', 'mulla-payments') : __('Sandbox', 'mulla-payments');
    }

    public function isLive(): bool
    {
        return $this->mode() === 'live';
    }

    public function secretKey(): string
    {
        return $this->get($this->mode() . '_secret_key');
    }

    public function webhookSecret(): string
    {
        return $this->get($this->mode() . '_webhook_secret');
    }

    public function baseUrl(): ?string
    {
        $url = $this->get('base_url');

        return $url !== '' ? $url : null;
    }

    public function debug(): bool
    {
        return $this->get('debug') === 'yes';
    }

    /** True when a secret key for the active mode is set and matches the mode. */
    public function isConfigured(): bool
    {
        return str_starts_with($this->secretKey(), 'sk_' . $this->mode() . '_');
    }

    public function get(string $key): string
    {
        $constant = self::CONSTANTS[$key] ?? null;
        if ($constant !== null && defined($constant)) {
            $value = constant($constant);

            return is_scalar($value) ? trim((string) $value) : '';
        }

        $stored = get_option(self::OPTION, []);
        $value = is_array($stored) && isset($stored[$key]) && is_scalar($stored[$key]) ? (string) $stored[$key] : (self::DEFAULTS[$key] ?? '');

        return trim($value);
    }

    /** Whether the value comes from wp-config.php (then it cannot be edited in wp-admin). */
    public function isLocked(string $key): bool
    {
        $constant = self::CONSTANTS[$key] ?? null;

        return $constant !== null && defined($constant);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }
}

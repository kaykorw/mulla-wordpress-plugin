<?php

declare(strict_types=1);

namespace MullaPayments\Support;

use MullaPayments\Settings\Settings;

/**
 * Writes to WooCommerce → Status → Logs (source "mulla-payments") when WooCommerce is active,
 * otherwise to the PHP error log. Debug lines are only written when debug logging is enabled.
 * Never pass secrets or full webhook bodies here.
 */
final class Logger
{
    public function __construct(private readonly Settings $settings) {}

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if ($level === 'debug' && !$this->settings->debug()) {
            return;
        }

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message . ($context !== [] ? ' ' . wp_json_encode($context) : ''), ['source' => 'mulla-payments']);

            return;
        }

        // Without WooCommerce, only log when the site owner enabled debug logging in wp-config.php.
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in via WP_DEBUG_LOG.
            error_log('[mulla-payments] ' . strtoupper($level) . ' ' . $message . ($context !== [] ? ' ' . wp_json_encode($context) : ''));
        }
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** SDK request logger (one debug line per API call when debug logging is on). */
    public function sdkLogger(): callable
    {
        return function (string $level, string $message, array $context): void {
            $this->log($level === 'debug' ? 'debug' : $level, $message, $context);
        };
    }
}

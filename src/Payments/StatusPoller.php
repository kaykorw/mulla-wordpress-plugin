<?php

declare(strict_types=1);

namespace MullaPayments\Payments;

use MullaPayments\ClientFactory;
use MullaPayments\Support\Logger;
use MullaPayments\Vendor\Mulla\Exceptions\MullaException;

/**
 * Safety net for missed webhooks: after a charge starts, checks its status with the API on a
 * schedule (WP-Cron) until it is final or Mulla has expired it (charges expire after 10 minutes).
 */
final class StatusPoller
{
    public const HOOK = 'mulla_payments_poll_charge';
    /** Seconds to wait before each attempt. */
    private const SCHEDULE = [60, 120, 180, 300, 600];

    public function __construct(
        private readonly ClientFactory $clients,
        private readonly Logger $logger,
    ) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'poll'], 10, 2);
    }

    public function schedule(string $chargeId, int $attempt = 0): void
    {
        if (!isset(self::SCHEDULE[$attempt])) {
            return;
        }
        $args = [$chargeId, $attempt];
        if (wp_next_scheduled(self::HOOK, $args) === false) {
            wp_schedule_single_event(time() + self::SCHEDULE[$attempt], self::HOOK, $args);
        }
    }

    public function poll(string $chargeId, int $attempt = 0): void
    {
        $update = $this->check($chargeId);
        if ($update === null || !$update->isFinal()) {
            $this->schedule($chargeId, $attempt + 1);
        }
    }

    /** Fetches the charge now and publishes its state. Returns null when the API could not be reached. */
    public function check(string $chargeId): ?ChargeUpdate
    {
        try {
            $update = ChargeUpdate::fromCharge($this->clients->client()->charges->retrieve($chargeId));
        } catch (MullaException $e) {
            $this->logger->warning('Could not check charge status: ' . $e->getMessage(), ['charge' => $chargeId]);

            return null;
        }

        if ($update !== null) {
            /** Fires whenever a charge's state is learned (webhook or API). */
            do_action('mulla_payments_charge_updated', $update);
        }

        return $update;
    }
}

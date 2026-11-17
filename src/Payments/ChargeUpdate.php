<?php

declare(strict_types=1);

namespace MullaPayments\Payments;

use MullaPayments\Vendor\Mulla\Enums\ChargeStatus;
use MullaPayments\Vendor\Mulla\Enums\Provider;
use MullaPayments\Vendor\Mulla\Models\Charge;
use MullaPayments\Vendor\Mulla\Webhooks\Event;

/**
 * A charge's latest known state, whether it came from a webhook or from polling the API.
 * Modules (WooCommerce, payment form) receive this through the `mulla_payments_charge_updated` action.
 */
final class ChargeUpdate
{
    public function __construct(
        public readonly string $chargeId,
        public readonly ChargeStatus $status,
        public readonly int|float|null $amount,
        public readonly ?string $reference,
        public readonly ?string $transactionId,
        public readonly string $source,
        public readonly ?string $failureMessage = null,
        public readonly ?string $phoneNumber = null,
        public readonly ?Provider $provider = null,
    ) {}

    public static function fromEvent(Event $event): ?self
    {
        $chargeId = $event->objectId();
        $status = ChargeStatus::tryFrom((string) $event->status());
        if ($chargeId === null || $status === null) {
            return null;
        }

        return new self($chargeId, $status, $event->amount(), $event->reference(), $event->transactionId(), 'webhook');
    }

    public static function fromCharge(Charge $charge): ?self
    {
        if ($charge->status === null) {
            return null;
        }

        return new self($charge->id, $charge->status, $charge->amount, $charge->reference, null, 'api', $charge->failureMessage, $charge->phoneNumber, $charge->provider);
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }
}

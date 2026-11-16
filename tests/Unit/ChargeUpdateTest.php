<?php

declare(strict_types=1);

namespace MullaPayments\Tests\Unit;

use MullaPayments\Payments\ChargeUpdate;
use MullaPayments\Vendor\Mulla\Enums\ChargeStatus;
use MullaPayments\Vendor\Mulla\Models\Charge;
use MullaPayments\Vendor\Mulla\Webhooks\Event;
use PHPUnit\Framework\TestCase;

final class ChargeUpdateTest extends TestCase
{
    public function testFromAChargeSuccessWebhook(): void
    {
        $update = ChargeUpdate::fromEvent(Event::fromPayload([
            'event' => 'charge.success',
            'event_id' => 'evt_1',
            'data' => [
                'charge' => 'ch_1',
                'payment' => 'pay_1',
                'transaction_id' => 'tx_1',
                'reference' => 'REF1',
                'amount' => 5000,
                'currency' => 'RWF',
                'status' => 'SUCCESSFUL',
            ],
        ]));

        self::assertNotNull($update);
        self::assertSame('ch_1', $update->chargeId);
        self::assertSame(ChargeStatus::SUCCESSFUL, $update->status);
        self::assertSame(5000, $update->amount);
        self::assertSame('REF1', $update->reference);
        self::assertSame('tx_1', $update->transactionId);
        self::assertSame('webhook', $update->source);
        self::assertTrue($update->isFinal());
        self::assertTrue($update->isSuccessful());
    }

    public function testIgnoresEventsWithoutAChargeOrStatus(): void
    {
        self::assertNull(ChargeUpdate::fromEvent(Event::fromPayload(['event' => 'webhook.test', 'event_id' => 'e', 'data' => []])));
        self::assertNull(ChargeUpdate::fromEvent(Event::fromPayload(['event' => 'charge.success', 'event_id' => 'e', 'data' => ['charge' => 'ch_1', 'status' => 'WEIRD']])));
    }

    public function testFromAPolledCharge(): void
    {
        $update = ChargeUpdate::fromCharge(new Charge([
            'id' => 'ch_2',
            'status' => 'FAILED',
            'amount' => 1200,
            'reference' => 'REF2',
            'failure_message' => 'Insufficient balance',
        ]));

        self::assertNotNull($update);
        self::assertSame('api', $update->source);
        self::assertSame('Insufficient balance', $update->failureMessage);
        self::assertTrue($update->isFinal());
        self::assertFalse($update->isSuccessful());
    }

    public function testAPendingChargeIsNotFinal(): void
    {
        $update = ChargeUpdate::fromCharge(new Charge(['id' => 'ch_3', 'status' => 'PENDING']));

        self::assertNotNull($update);
        self::assertFalse($update->isFinal());
    }
}

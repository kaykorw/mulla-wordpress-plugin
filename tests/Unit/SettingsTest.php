<?php

declare(strict_types=1);

namespace MullaPayments\Tests\Unit;

use MullaPayments\Settings\Settings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        \WpStubs::reset();
    }

    public function testDefaultsToTestModeAndNotConfigured(): void
    {
        $settings = new Settings();

        self::assertSame('test', $settings->mode());
        self::assertFalse($settings->isLive());
        self::assertFalse($settings->isConfigured());
        self::assertNull($settings->baseUrl());
        self::assertFalse($settings->debug());
    }

    public function testUsesTheKeysOfTheActiveMode(): void
    {
        \WpStubs::$options[Settings::OPTION] = [
            'mode' => 'live',
            'test_secret_key' => 'sk_test_abc',
            'live_secret_key' => ' sk_live_xyz ',
            'live_webhook_secret' => 'whsec',
            'debug' => 'yes',
        ];
        $settings = new Settings();

        self::assertTrue($settings->isLive());
        self::assertSame('sk_live_xyz', $settings->secretKey());
        self::assertSame('whsec', $settings->webhookSecret());
        self::assertTrue($settings->isConfigured());
        self::assertTrue($settings->debug());
    }

    public function testAKeyForTheOtherModeIsNotConfigured(): void
    {
        \WpStubs::$options[Settings::OPTION] = ['mode' => 'live', 'live_secret_key' => 'sk_test_abc'];

        self::assertFalse((new Settings())->isConfigured());
    }

    public function testUnknownModeFallsBackToTest(): void
    {
        \WpStubs::$options[Settings::OPTION] = ['mode' => 'staging'];

        self::assertSame('test', (new Settings())->mode());
    }

    public function testAcceptsTheSandboxAndProductionNames(): void
    {
        \WpStubs::$options[Settings::OPTION] = ['mode' => 'production'];
        self::assertSame('live', (new Settings())->mode());

        \WpStubs::$options[Settings::OPTION] = ['mode' => 'sandbox'];
        self::assertSame('test', (new Settings())->mode());
    }

    public function testModeLabels(): void
    {
        self::assertSame('Sandbox', Settings::modeLabel('test'));
        self::assertSame('Production', Settings::modeLabel('live'));
    }

    public function testIgnoresACorruptOption(): void
    {
        \WpStubs::$options[Settings::OPTION] = 'not an array';
        $settings = new Settings();

        self::assertSame('test', $settings->mode());
        self::assertSame('', $settings->secretKey());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstantsWinAndLockTheSetting(): void
    {
        \WpStubs::reset();
        \WpStubs::$options[Settings::OPTION] = ['mode' => 'test', 'test_secret_key' => 'sk_test_from_db'];
        \define('MULLA_MODE', 'live');
        \define('MULLA_LIVE_SECRET_KEY', 'sk_live_from_config');
        $settings = new Settings();

        self::assertSame('live', $settings->mode());
        self::assertSame('sk_live_from_config', $settings->secretKey());
        self::assertTrue($settings->isLocked('mode'));
        self::assertTrue($settings->isLocked('live_secret_key'));
        self::assertFalse($settings->isLocked('test_secret_key'));
        self::assertFalse($settings->isLocked('debug'));
    }
}

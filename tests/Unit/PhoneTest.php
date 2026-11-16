<?php

declare(strict_types=1);

namespace MullaPayments\Tests\Unit;

use MullaPayments\Support\Phone;
use MullaPayments\Vendor\Mulla\Enums\Provider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function numbers(): iterable
    {
        yield 'local with spaces' => ['078 800 0000', '250788000000'];
        yield 'local' => ['0788000000', '250788000000'];
        yield 'international' => ['+250 788 000 000', '250788000000'];
        yield 'without zero' => ['788000000', '250788000000'];
        yield 'airtel' => ['0731234567', '250731234567'];
        yield 'dashes' => ['079-123-4567', '250791234567'];
        yield 'too short' => ['078800000', null];
        yield 'unknown prefix' => ['0758000000', null];
        yield 'other country' => ['+254712345678', null];
        yield 'empty' => ['', null];
        yield 'letters' => ['call me', null];
    }

    #[DataProvider('numbers')]
    public function testNormalize(string $input, ?string $expected): void
    {
        self::assertSame($expected, Phone::normalize($input));
    }

    public function testGuessProvider(): void
    {
        self::assertSame(Provider::MTN_RW, Phone::guessProvider('250788000000'));
        self::assertSame(Provider::MTN_RW, Phone::guessProvider('250791234567'));
        self::assertSame(Provider::AIRTEL_RW, Phone::guessProvider('250721234567'));
        self::assertSame(Provider::AIRTEL_RW, Phone::guessProvider('250731234567'));
        self::assertNull(Phone::guessProvider('250751234567'));
    }

    public function testMaskHidesTheMiddleDigits(): void
    {
        self::assertSame('250788***000', Phone::mask('250788123000'));
        self::assertSame('short', Phone::mask('short'));
    }
}

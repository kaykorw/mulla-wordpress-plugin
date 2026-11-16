<?php

declare(strict_types=1);

namespace MullaPayments\Support;

use MullaPayments\Vendor\Mulla\Enums\Provider;

/**
 * Rwandan mobile number helpers. Accepts what customers actually type
 * ("078 800 0000", "+250788000000", "0788000000") and returns 250XXXXXXXXX.
 */
final class Phone
{
    private function __construct() {}

    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if (strlen($digits) === 10 && str_starts_with($digits, '07')) {
            $digits = '25' . $digits;
        } elseif (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            $digits = '250' . $digits;
        }

        return preg_match('/^2507[2389]\d{7}$/', $digits) === 1 ? $digits : null;
    }

    /** Best guess of the network from the prefix: 078/079 MTN, 072/073 Airtel. */
    public static function guessProvider(string $normalized): ?Provider
    {
        return match (substr($normalized, 0, 5)) {
            '25078', '25079' => Provider::MTN_RW,
            '25072', '25073' => Provider::AIRTEL_RW,
            default => null,
        };
    }

    /** 250788***000, for admin screens and notes. */
    public static function mask(string $normalized): string
    {
        return strlen($normalized) === 12 ? substr($normalized, 0, 6) . '***' . substr($normalized, -3) : $normalized;
    }
}

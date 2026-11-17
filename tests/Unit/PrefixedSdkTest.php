<?php

declare(strict_types=1);

namespace MullaPayments\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards bin/prefix-sdk.php: the bundled SDK must live entirely under MullaPayments\Vendor\Mulla,
 * otherwise another plugin shipping kayko/mulla-php could load a conflicting copy.
 */
final class PrefixedSdkTest extends TestCase
{
    private const DIR = __DIR__ . '/../../vendor-prefixed/kayko/mulla-php/src';

    public function testEveryFileIsInThePrefixedNamespace(): void
    {
        $files = $this->phpFiles();
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            self::assertMatchesRegularExpression('/^namespace MullaPayments\\\\Vendor\\\\Mulla(\\\\|;)/m', $code, $file);
            self::assertDoesNotMatchRegularExpression('/^(namespace|use) Mulla\\\\/m', $code, $file);
            self::assertDoesNotMatchRegularExpression('/(?<![\w\\\\])\\\\Mulla\\\\/', $code, "{$file} has an unprefixed fully qualified name");
        }
    }

    public function testThePrefixedClientLoadsAndTheOriginalDoesNot(): void
    {
        self::assertTrue(class_exists(\MullaPayments\Vendor\Mulla\MullaClient::class));
        self::assertFalse(class_exists('Mulla\MullaClient', false));
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DIR, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}

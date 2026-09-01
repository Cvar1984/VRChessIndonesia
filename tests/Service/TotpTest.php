<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VRchessIndo\Service\Totp;

/**
 * Verifies Totp::generate() against the official RFC 6238 Appendix B test
 * vectors (SHA1, 30s step, secret ASCII "12345678901234567890" — base32
 * GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ). The RFC's own vectors are 8-digit
 * codes; truncating to 6 digits is `% 10^6` on the same underlying integer,
 * so the last 6 digits of each RFC value are the expected 6-digit code.
 *
 * Pure math, no I/O — doesn't need the database, so this is a plain
 * TestCase rather than KernelTestCase.
 */
class TotpTest extends TestCase
{
    private const string RFC_SECRET_BASE32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function rfcTestVectors(): array
    {
        return [
            'T=59' => [59, '287082'],
            'T=1111111109' => [1111111109, '081804'],
            'T=1111111111' => [1111111111, '050471'],
            'T=1234567890' => [1234567890, '005924'],
            'T=2000000000' => [2000000000, '279037'],
        ];
    }

    #[DataProvider('rfcTestVectors')]
    public function testMatchesRfc6238TestVectors(int $timestamp, string $expectedCode): void
    {
        self::assertSame($expectedCode, Totp::generate(self::RFC_SECRET_BASE32, $timestamp));
    }

    public function testCodeIsSixDigitsZeroPadded(): void
    {
        $code = Totp::generate(self::RFC_SECRET_BASE32, 59);
        self::assertSame(6, strlen($code));
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testIgnoresWhitespaceAndCaseInSecret(): void
    {
        $spaced = 'gezd gnbv gy3t qojq gezd gnbv gy3t qojq';
        self::assertSame(Totp::generate(self::RFC_SECRET_BASE32, 59), Totp::generate($spaced, 59));
    }

    public function testSameThirtySecondStepProducesSameCode(): void
    {
        self::assertSame(Totp::generate(self::RFC_SECRET_BASE32, 60), Totp::generate(self::RFC_SECRET_BASE32, 89));
    }

    public function testNextStepProducesDifferentCode(): void
    {
        self::assertNotSame(Totp::generate(self::RFC_SECRET_BASE32, 89), Totp::generate(self::RFC_SECRET_BASE32, 90));
    }
}

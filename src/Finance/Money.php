<?php

namespace Onekana\Api\Finance;

use Onekana\Api\Http\HttpException;

final class Money
{
    public static function cents(mixed $value, string $field = 'amount'): int
    {
        $normalized = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized)) {
            throw new HttpException(422, "Le montant {$field} doit contenir au maximum deux décimales.");
        }
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$units, $decimals] = array_pad(explode('.', $normalized, 2), 2, '');
        $cents = ((int) $units * 100) + (int) str_pad($decimals, 2, '0');
        return $negative ? -$cents : $cents;
    }

    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function output(mixed $value): string
    {
        return self::decimal(self::cents($value ?? '0.00'));
    }
}

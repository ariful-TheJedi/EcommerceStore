<?php

namespace App\Orders\Domain\Services;

use Illuminate\Validation\ValidationException;

class Money
{
    public static function minor(string|int $value): int
    {
        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', (string) $value, $parts)) {
            throw ValidationException::withMessages(['amount' => 'Invalid money amount.']);
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function decimal(int $minor): string
    {
        if ($minor < 0 || $minor > 999999999999) {
            throw ValidationException::withMessages(['amount' => 'The order total is outside the permitted range.']);
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}

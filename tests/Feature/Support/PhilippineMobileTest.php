<?php

declare(strict_types=1);

use App\Support\PhilippineMobile;

it('normalizes what people type to 09XXXXXXXXX', function (string $input): void {
    expect(PhilippineMobile::normalize($input))->toBe('09171234567');
})->with([
    '09171234567',
    '0917 123 4567',
    '0917-123-4567',
    '(0917) 123-4567',
    '+63 917 123 4567',
    '+639171234567',
    '639171234567',
    '917 123 4567',
    '  0917.123.4567  ',
]);

it('rejects what is not a PH mobile number', function (?string $input): void {
    expect(PhilippineMobile::normalize($input))->toBeNull();
})->with([
    'nothing' => null,
    'empty' => '',
    'too short' => '0917 123 456',
    'too long' => '0917 123 45678',
    'Manila landline' => '(02) 8123 4567',
    'landline with country code' => '+63 2 8123 4567',
    'another country' => '+1 917 123 4567',
    'letters' => '0917 ABC 4567',
    'extension' => '0917 123 4567 ext 2',
]);

it('formats a normalized number for display', function (): void {
    expect(PhilippineMobile::format('09171234567'))->toBe('0917 123 4567');
});

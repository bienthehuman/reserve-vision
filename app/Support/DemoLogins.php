<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The demo accounts (slice 2.2, README "Demo logins") for the login page's demo
 * panel, shown only when APP_DEMO=true. tests/Feature/Auth/DemoLoginsTest.php
 * checks each one against what DemoSeeder creates.
 */
final class DemoLogins
{
    /** Every demo account's password (DemoSeeder). */
    public const PASSWORD = 'password';

    /**
     * @return list<array{email: string, password: string, role: string, note: string}>
     */
    public static function all(): array
    {
        return array_map(fn (array $login): array => [...$login, 'password' => self::PASSWORD], [
            ['email' => 'owner@example.com', 'role' => __('Owner'), 'note' => __('Santos Family Rentals, verified')],
            ['email' => 'caretaker@example.com', 'role' => __('Caretaker'), 'note' => __('Same account as the owner')],
            ['email' => 'owner2@example.com', 'role' => __('Owner'), 'note' => __('Cruz Lettings, a different account')],
            ['email' => 'renter1@example.com', 'role' => __('Renter'), 'note' => __('No reservations yet')],
            ['email' => 'renter2@example.com', 'role' => __('Renter'), 'note' => __('No reservations yet')],
        ]);
    }
}

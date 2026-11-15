<?php

declare(strict_types=1);

use App\Enums\NameSuffix;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// CONVENTIONS.md, "Names": first name + last name + optional suffix, never one field.

it('tells Joshua Gonzales and Joshua Gonzales Jr. apart', function (): void {
    $father = User::factory()->create(['first_name' => 'Joshua', 'last_name' => 'Gonzales']);
    $son = User::factory()->withSuffix()->create(['first_name' => 'Joshua', 'last_name' => 'Gonzales']);

    expect($father->full_name)->toBe('Joshua Gonzales')
        ->and($son->full_name)->toBe('Joshua Gonzales Jr.')
        ->and($son->suffix)->toBe(NameSuffix::Jr)
        ->and($father->id)->not->toBe($son->id);
});

it('writes every suffix the way it is conventionally written', function (NameSuffix $suffix, string $written): void {
    $user = User::factory()->withSuffix($suffix)->make(['first_name' => 'Ana', 'last_name' => 'Reyes']);

    expect($user->full_name)->toBe("Ana Reyes {$written}");
})->with([
    [NameSuffix::Jr, 'Jr.'], [NameSuffix::Sr, 'Sr.'], [NameSuffix::Ii, 'II'],
    [NameSuffix::Iii, 'III'], [NameSuffix::Iv, 'IV'], [NameSuffix::V, 'V'],
]);

it('trims names and collapses inner whitespace', function (): void {
    $user = User::factory()->make(['first_name' => '  Maria   Cristina ', 'last_name' => "\tDela  Cruz "]);

    expect($user->first_name)->toBe('Maria Cristina')
        ->and($user->last_name)->toBe('Dela Cruz')
        ->and($user->full_name)->toBe('Maria Cristina Dela Cruz');
});

it('has no middle name unless given, and skips it when blank', function (): void {
    $none = User::factory()->make(['first_name' => 'Ana', 'last_name' => 'Reyes']);
    $blank = User::factory()->make(['first_name' => 'Ana', 'middle_name' => '   ', 'last_name' => 'Reyes']);
    $some = User::factory()->withSuffix()->make(['first_name' => 'Joshua', 'middle_name' => ' Michael ', 'last_name' => 'Gonzales']);

    expect($none->middle_name)->toBeNull()
        ->and($blank->middle_name)->toBeNull()
        ->and($blank->full_name)->toBe('Ana Reyes')
        ->and($some->middle_name)->toBe('Michael')
        ->and($some->full_name)->toBe('Joshua Michael Gonzales Jr.');
});

it('knows only the suffixes of the list (the form validates with the enum, the database has no CHECK)', function (): void {
    expect(NameSuffix::tryFrom('junior'))->toBeNull()
        ->and(NameSuffix::tryFrom('jr'))->toBe(NameSuffix::Jr);
});

it('requires a first and a last name', function (string $column): void {
    $user = User::factory()->create();

    expect(dbErrorCode(fn () => DB::table('users')->where('id', $user->id)->update([$column => null])))->toBe(1048);
})->with(['first_name', 'last_name']);

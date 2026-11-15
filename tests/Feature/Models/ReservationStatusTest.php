<?php

declare(strict_types=1);

use App\Enums\ReservationStatus as S;
use App\Exceptions\Domain\InvalidTransition;

it('allows exactly the transitions in the table', function (): void {
    $allowed = [];

    foreach (S::transitions() as $row) {
        if ($row['from'] !== null) {
            $allowed[$row['from']->value.'>'.$row['to']->value] = true;
        }
    }

    foreach (S::cases() as $from) {
        foreach (S::cases() as $to) {
            expect($from->canTransitionTo($to))->toBe(isset($allowed[$from->value.'>'.$to->value]), "{$from->value} -> {$to->value}");
        }
    }
});

it('treats expired, declined, cancelled and ended as final', function (S $status): void {
    foreach (S::cases() as $to) {
        expect($status->canTransitionTo($to))->toBeFalse();
    }
})->with([S::Expired, S::Declined, S::Cancelled, S::Ended]);

it('throws InvalidTransition with a readable message for a move the table lacks', function (): void {
    expect(fn () => S::Held->assertCanTransitionTo(S::Confirmed))
        ->toThrow(InvalidTransition::class, "A reservation that is held can't become confirmed.")
        ->and(fn () => S::Held->assertCanTransitionTo(S::ProofUploaded))->not->toThrow(InvalidTransition::class);
});

it('always blocks only for proof_uploaded, confirmed and active', function (): void {
    $blocking = array_filter(S::cases(), fn (S $s): bool => $s->isBlockingStatus());

    expect(array_map(fn (S $s): string => $s->value, array_values($blocking)))
        ->toBe(['proof_uploaded', 'confirmed', 'active']);
});

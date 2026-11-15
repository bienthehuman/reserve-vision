<?php

declare(strict_types=1);

use App\Models\Reservation;
use App\Models\Space;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-15 10:00', 'Asia/Manila'));
});

it('blocks for a live hold, proof_uploaded, confirmed and active only', function (string $state, bool $blocks): void {
    $reservation = Reservation::factory()->{$state}()->create();

    expect(Reservation::query()->whereKey($reservation->id)->blocking()->exists())->toBe($blocks);
})->with([
    'live hold' => ['held', true],
    'proof_uploaded' => ['proofUploaded', true],
    'confirmed' => ['confirmed', true],
    'active' => ['active', true],
    'expired hold, job not run' => ['expiredHold', false],
    'expired' => ['expired', false],
    'declined' => ['declined', false],
    'cancelled' => ['cancelled', false],
    'ended' => ['ended', false],
]);

it('counts only live holds and proof_uploaded as open', function (string $state, bool $open): void {
    $reservation = Reservation::factory()->{$state}()->create();

    expect(Reservation::query()->openForRenter($reservation->renter()->sole())->exists())->toBe($open);
})->with([
    'live hold' => ['held', true],
    'proof_uploaded' => ['proofUploaded', true],
    'confirmed' => ['confirmed', false],
    'active' => ['active', false],
    'expired hold, job not run' => ['expiredHold', false],
    'expired' => ['expired', false],
    'declined' => ['declined', false],
    'cancelled' => ['cancelled', false],
    'ended' => ['ended', false],
]);

it('treats move_out as exclusive when testing overlap', function (string $from, string $to, bool $overlaps): void {
    $reservation = Reservation::factory()->confirmed()->create(['move_in' => '2027-04-01', 'move_out' => '2027-06-01']);

    expect(Reservation::query()->whereKey($reservation->id)
        ->overlapping(CarbonImmutable::parse($from), CarbonImmutable::parse($to))->exists())->toBe($overlaps);
})->with([
    'ends on its move-in' => ['2027-03-01', '2027-04-01', false],
    'starts on its move-out' => ['2027-06-01', '2027-07-01', false],
    'first night' => ['2027-03-01', '2027-04-02', true],
    'last night' => ['2027-05-31', '2027-07-01', true],
    'inside' => ['2027-04-10', '2027-04-20', true],
    'around' => ['2027-03-01', '2027-07-01', true],
]);

it('qualifies its columns, so the availability search can use it on a join', function (): void {
    $reservation = Reservation::factory()->confirmed()->create(['move_in' => '2027-04-01', 'move_out' => '2027-06-01']);

    $sql = Reservation::query()->blocking()->overlapping(CarbonImmutable::parse('2027-05-01'), CarbonImmutable::parse('2027-05-02'))->toSql();
    expect($sql)->not->toMatch('/(?<![.`])`?(status|move_in|move_out|hold_expires_at)`?\s*(=|<|>|in)/i');

    $roomIds = Reservation::query()
        ->join('spaces', 'spaces.id', '=', 'reservations.space_id')
        ->join('rooms', 'rooms.id', '=', 'spaces.room_id')
        ->blocking()
        ->overlapping(CarbonImmutable::parse('2027-05-01'), CarbonImmutable::parse('2027-05-02'))
        ->pluck('rooms.id');

    expect($roomIds->all())->toBe([Space::query()->findOrFail($reservation->space_id)->room_id]);
});

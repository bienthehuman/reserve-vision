<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Concerns\BelongsToLandlordAccount;
use App\Models\LandlordAccount;
use App\Models\LandlordInvitation;
use App\Models\LandlordMember;
use App\Models\PaymentProof;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;
use App\Models\User;
use App\Models\ViewingRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Isolation\Support\IsolationProbeJob;

// The isolation scope (docs/ACCESS.md "Isolation"): which queries it filters,
// and when it stays out of the way. Fixtures are created before actingAs():
// with a landlord logged in, factories are isolated too.

/**
 * One reservation chain (property → room → space → reservation → proof) plus a
 * photo, a viewing request, an invitation and an audit row in `$account`.
 *
 * @return array<class-string<Model>, Model>
 */
function isolatedRecords(LandlordAccount $account): array
{
    $property = Property::factory()->for($account)->published()->create();
    $room = Room::factory()->for($property)->create();
    $reservation = Reservation::factory()->for($room->spaces()->sole())->proofUploaded()->create();

    return [
        Property::class => $property,
        Room::class => $room,
        Space::class => $room->spaces()->sole(),
        Reservation::class => $reservation,
        PaymentProof::class => $reservation->paymentProofs()->sole(),
        PropertyPhoto::class => PropertyPhoto::factory()->for($property)->create(),
        ViewingRequest::class => ViewingRequest::factory()->for($property)->create(),
        LandlordInvitation::class => LandlordInvitation::factory()->for($account)->create(),
        AuditLog::class => AuditLog::factory()->create(['landlord_account_id' => $account->id]),
    ];
}

/** @return array{User, LandlordAccount} */
function ownerOfNewAccount(): array
{
    $account = LandlordAccount::factory()->create(['is_verified' => true, 'verified_at' => now()]);
    $owner = User::factory()->landlord()->create();
    LandlordMember::factory()->for($account)->for($owner)->owner()->create();

    return [$owner, $account];
}

/** The models docs/ACCESS.md lists under "Isolation", as class names. */
function documentedIsolatedModels(): array
{
    $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/ACCESS.md');
    preg_match('/Every landlord-owned model \(([^)]+)\)/', $doc, $m);
    preg_match_all('/`(\w+)`/', $m[1] ?? '', $names);

    return array_map(fn (string $name): string => "App\\Models\\{$name}", $names[1]);
}

it('is on exactly the models ACCESS.md lists, which are every model with a landlord_account_id but landlord_members', function (): void {
    $withTrait = [];
    $withColumn = [];

    foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        $model = new $class;
        assert($model instanceof Model);

        if (in_array(BelongsToLandlordAccount::class, class_uses_recursive($class), true)) {
            $withTrait[] = $class;
        }

        if (Schema::hasColumn($model->getTable(), 'landlord_account_id')) {
            $withColumn[] = $class;
        }
    }

    $documented = documentedIsolatedModels();
    sort($documented);

    expect($withTrait)->toEqual($documented)
        // landlord_members is what the scope reads to find the account; its policy guards it.
        ->and(array_values(array_diff($withColumn, $withTrait)))->toBe([LandlordMember::class]);
});

it('filters every landlord-owned model to the logged-in landlord\'s account', function (string $model): void {
    [$owner, $own] = ownerOfNewAccount();
    $mine = isolatedRecords($own)[$model];
    $theirs = isolatedRecords(LandlordAccount::factory()->create())[$model];

    $this->actingAs($owner);

    expect($model::query()->pluck('landlord_account_id')->unique()->values()->all())->toBe([$own->id])
        ->and($model::query()->find($mine->getKey()))->not->toBeNull()
        ->and($model::query()->find($theirs->getKey()))->toBeNull();
})->with(fn (): array => documentedIsolatedModels());

it('filters for caretakers the same way', function (): void {
    [, $own] = ownerOfNewAccount();
    $caretaker = User::factory()->landlord()->create();
    LandlordMember::factory()->for($own)->for($caretaker)->caretaker()->create();
    $mine = isolatedRecords($own)[Reservation::class];
    $theirs = isolatedRecords(LandlordAccount::factory()->create())[Reservation::class];

    $this->actingAs($caretaker);

    expect(Reservation::query()->pluck('id')->all())->toContain($mine->getKey())->not->toContain($theirs->getKey());
});

it('doesn\'t filter for renters or guests', function (?string $who): void {
    $a = isolatedRecords(LandlordAccount::factory()->create());
    $b = isolatedRecords(LandlordAccount::factory()->create());

    if ($who === 'renter') {
        $this->actingAs(User::factory()->renter()->create());
    }

    foreach (array_keys($a) as $model) {
        $ids = $model::query()->pluck('id')->all();
        expect(in_array($a[$model]->getKey(), $ids, true) && in_array($b[$model]->getKey(), $ids, true))->toBeTrue($model);
    }
})->with(['renter' => 'renter', 'guest' => null]);

it('fails closed for a landlord user without a membership: nothing matches', function (): void {
    isolatedRecords(LandlordAccount::factory()->create());
    $orphan = User::factory()->landlord()->create();

    $this->actingAs($orphan);

    foreach (documentedIsolatedModels() as $model) {
        expect($model::query()->count())->toBe(0, $model);
    }
});

it('qualifies its column, so it works on joins', function (): void {
    [$owner, $own] = ownerOfNewAccount();
    $mine = isolatedRecords($own)[Reservation::class];
    isolatedRecords(LandlordAccount::factory()->create());

    $this->actingAs($owner);

    // spaces and rooms have landlord_account_id too: an unqualified column would be ambiguous (error 1052).
    $ids = Reservation::query()
        ->join('spaces', 'spaces.id', '=', 'reservations.space_id')
        ->join('rooms', 'rooms.id', '=', 'spaces.room_id')
        ->pluck('reservations.id')
        ->all();

    expect($ids)->toBe([$mine->getKey()]);
});

it('applies to eager loads, relations and whereHas', function (): void {
    [$owner, $own] = ownerOfNewAccount();
    $mine = isolatedRecords($own);
    $theirs = isolatedRecords(LandlordAccount::factory()->create());

    $this->actingAs($owner);

    $theirReservation = Reservation::query()->withoutGlobalScopes()->findOrFail($theirs[Reservation::class]->getKey());

    expect($theirReservation->space()->first())->toBeNull()
        ->and(Space::query()->whereHas('reservations')->pluck('id')->all())->toBe([$mine[Space::class]->getKey()])
        ->and(Room::query()->with('spaces')->get()->flatMap->spaces->pluck('id')->all())->toBe([$mine[Space::class]->getKey()]);
});

it('makes another account\'s record a 404 through route model binding', function (): void {
    [$owner, $own] = ownerOfNewAccount();
    $mine = isolatedRecords($own)[Property::class];
    $theirs = isolatedRecords(LandlordAccount::factory()->create())[Property::class];

    Route::middleware(['web', 'auth'])->get('/_isolation/properties/{property}', fn (Property $property): int => $property->id);

    $this->actingAs($owner)->get("/_isolation/properties/{$mine->id}")->assertOk()->assertSee((string) $mine->id);
    $this->actingAs($owner)->get("/_isolation/properties/{$theirs->id}")->assertNotFound();
});

it('isn\'t active in a console command', function (): void {
    $a = isolatedRecords(LandlordAccount::factory()->create())[Property::class];
    $b = isolatedRecords(LandlordAccount::factory()->create())[Property::class];

    Artisan::command('isolation:probe', function (): void {
        foreach (Property::query()->pluck('id') as $id) {
            $this->line("property {$id}");
        }
    });

    $this->artisan('isolation:probe')->expectsOutput("property {$a->id}")->expectsOutput("property {$b->id}")->assertExitCode(0);
});

it('isn\'t active in a queued job on a worker', function (): void {
    $a = isolatedRecords(LandlordAccount::factory()->create())[Property::class];
    $b = isolatedRecords(LandlordAccount::factory()->create())[Property::class];
    IsolationProbeJob::$seen = [];

    dispatch(new IsolationProbeJob('system'))->onConnection('database');
    $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 1024])->assertExitCode(0);

    expect(IsolationProbeJob::$seen['system']['applies'])->toBeFalse()
        ->and(IsolationProbeJob::$seen['system']['properties'])->toContain($a->id)->toContain($b->id);
});

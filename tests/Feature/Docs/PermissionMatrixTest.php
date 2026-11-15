<?php

declare(strict_types=1);

use App\Models\AuditLog;
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
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

// CONVENTIONS.md, "Docs that are tested": the permission matrix in
// docs/ACCESS.md is the source of truth for who may do what. Each row maps to
// the abilities that implement it (MATRIX_ABILITIES); each cell is checked
// against what the policies actually allow, for each role, on records of the
// actor's own account (or own reservation), and every ability is checked
// against another account's or renter's records. Add, remove or reword a row,
// change a cell, or add a policy ability, and this fails until both agree.

/**
 * Row => the abilities that implement it. A target is one of:
 *   [ability, 'subject']                 an ability on a record ('reservation', 'room', …)
 *   [ability, Model::class]              an ability with no record yet (create, list)
 *   [ability, Model::class, 'parent']    creating a record under a parent record
 * "Browse published listings" is the public named scopes, not a policy.
 */
const MATRIX_ABILITIES = [
    'Browse published listings' => 'public scopes',
    "View own account's properties, rooms, spaces and photos" => [
        ['viewAny', Property::class], ['view', 'property'], ['view', 'room'], ['view', 'space'], ['view', 'photo'],
    ],
    'Create or edit properties, rooms, spaces, rates' => [
        ['create', Property::class], ['update', 'property'],
        ['create', Room::class, 'property'], ['update', 'room'], ['updateRate', 'room'],
        ['create', Space::class, 'room'], ['update', 'space'],
    ],
    'Upload, reorder or delete photos' => [
        ['upload', PropertyPhoto::class, 'property'], ['reorder', PropertyPhoto::class, 'property'], ['delete', 'photo'],
    ],
    'Publish or unpublish a property' => [['publish', 'property'], ['unpublish', 'property']],
    'Archive or unarchive a room or space' => [
        ['archive', 'room'], ['unarchive', 'room'], ['archive', 'space'], ['unarchive', 'space'],
    ],
    'View reservations for own account' => [['viewAny', Reservation::class], ['view', 'reservation']],
    'View own reservations' => [['viewOwn', 'reservation']],
    'View a payment proof' => [['view', 'proof']],
    'Reject proof, confirm or decline' => [['rejectProof', 'reservation'], ['confirm', 'reservation'], ['decline', 'reservation']],
    'Undo a confirmation' => [['undoConfirmation', 'reservation']],
    'Mark moved in, end stay' => [['markMovedIn', 'reservation'], ['endStay', 'reservation']],
    'Withdraw a confirmed reservation' => [['withdraw', 'reservation']],
    'Record a refund' => [['recordRefund', 'reservation']],
    'Answer viewing requests' => [['answer', 'viewing'], ['markDone', 'viewing']],
    'Invite or remove caretakers, revoke invites' => [
        ['viewAny', LandlordInvitation::class], ['create', LandlordInvitation::class], ['revoke', 'invitation'], ['remove', 'member'],
    ],
    'View audit log' => [['viewAny', AuditLog::class], ['view', 'auditLog']],
    'Reserve a space, upload proof, cancel own reservation' => [
        ['create', Reservation::class, 'room'], ['upload', PaymentProof::class, 'reservation'], ['cancel', 'reservation'],
    ],
    'Request or cancel a viewing' => [['create', ViewingRequest::class, 'property'], ['cancel', 'viewing']],
    "See a renter's contact details" => [['viewRenterContact', 'reservation'], ['viewRenterContact', 'viewing']],
];

/** The subject keys above and their models. */
const MATRIX_SUBJECTS = [
    'property' => Property::class, 'room' => Room::class, 'space' => Space::class, 'photo' => PropertyPhoto::class,
    'reservation' => Reservation::class, 'proof' => PaymentProof::class, 'viewing' => ViewingRequest::class,
    'invitation' => LandlordInvitation::class, 'member' => LandlordMember::class, 'auditLog' => AuditLog::class,
];

/** Columns of the matrix, left to right. */
const MATRIX_ROLES = ['owner', 'caretaker', 'renter', 'public'];

/**
 * Cell text => allowed on the actor's own records. Anything else in the doc
 * fails the test, so a reworded cell needs a decision here.
 */
const MATRIX_CELLS = [
    '✓' => true,
    '—' => false,
    'own only' => true,
    'for reservations and viewings on own account' => true,
];

/**
 * The matrix as row => [role => allowed], "same" resolved to the cell on its left.
 *
 * @return array<string, array<string, bool>>
 */
function documentedMatrix(): array
{
    $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/ACCESS.md');
    $start = strpos($doc, "## Permission matrix\n");

    if ($start === false) {
        throw new RuntimeException('docs/ACCESS.md has no "## Permission matrix" heading.');
    }

    $section = substr($doc, $start + 1);
    $end = strpos($section, "\n#");
    $section = $end === false ? $section : substr($section, 0, $end);
    $matrix = [];

    foreach (explode("\n", $section) as $line) {
        if (! str_starts_with($line, '|')) {
            continue;
        }

        $cells = array_map('trim', explode('|', trim($line, '| ')));

        if ($cells[0] === 'Action' || preg_match('/^-+$/', $cells[0]) === 1) {
            continue; // header and separator
        }

        if (count($cells) !== 1 + count(MATRIX_ROLES)) {
            throw new RuntimeException("Matrix row [{$cells[0]}] has ".count($cells).' cells.');
        }

        $previous = null;

        foreach (MATRIX_ROLES as $i => $role) {
            $cell = $cells[$i + 1] === 'same' ? $previous : $cells[$i + 1];

            if (! is_string($cell) || ! array_key_exists($cell, MATRIX_CELLS)) {
                throw new RuntimeException("Unknown matrix cell [{$cells[$i + 1]}] in row [{$cells[0]}], column {$role}: add it to MATRIX_CELLS.");
            }

            $matrix[$cells[0]][$role] = MATRIX_CELLS[$cell];
            $previous = $cell;
        }
    }

    return $matrix;
}

/**
 * One landlord account with an owner, a caretaker, and one of every
 * landlord-owned record; `$renter` has the reservation, proof and viewing request.
 *
 * @return array{actors: array{owner: User, caretaker: User}, subjects: array<string, Model>}
 */
function matrixAccount(User $renter): array
{
    $account = LandlordAccount::factory()->create(['is_verified' => true, 'verified_at' => now()]);
    $owner = User::factory()->landlord()->create();
    $caretaker = User::factory()->landlord()->create();
    LandlordMember::factory()->for($account)->for($owner)->owner()->create();
    $caretakerMember = LandlordMember::factory()->for($account)->for($caretaker)->caretaker()->create();

    $property = Property::factory()->for($account)->published()->create();
    $room = Room::factory()->for($property)->create();
    $reservation = Reservation::factory()->for($room->spaces()->sole())->for($renter, 'renter')->proofUploaded()->create();

    return [
        'actors' => ['owner' => $owner, 'caretaker' => $caretaker],
        'subjects' => [
            'property' => $property,
            'room' => $room,
            'space' => $room->spaces()->sole(),
            'photo' => PropertyPhoto::factory()->for($property)->create(),
            'reservation' => $reservation,
            'proof' => $reservation->paymentProofs()->sole(),
            'viewing' => ViewingRequest::factory()->for($property)->for($renter, 'renter')->create(),
            'invitation' => LandlordInvitation::factory()->for($account)->create(['invited_by' => $owner->id]),
            'member' => $caretakerMember,
            'auditLog' => AuditLog::factory()->create(['landlord_account_id' => $account->id, 'actor_id' => $owner->id]),
        ],
    ];
}

/**
 * Two accounts: the actors' own ("own": owner, caretaker, renter) and another
 * account whose records belong to another renter ("other").
 *
 * @return array{actors: array<string, User|null>, own: array<string, Model>, other: array<string, Model>}
 */
function matrixWorld(): array
{
    $renter = User::factory()->renter()->create();
    $own = matrixAccount($renter);
    $other = matrixAccount(User::factory()->renter()->create());

    return [
        'actors' => [...$own['actors'], 'renter' => $renter, 'public' => null],
        'own' => $own['subjects'],
        'other' => $other['subjects'],
    ];
}

/**
 * @param  array{0: string, 1: string, 2?: string}  $target
 * @param  array<string, Model>  $subjects
 */
function inspectTarget(?User $actor, array $target, array $subjects): Response
{
    [$ability, $what] = $target;
    $parent = $target[2] ?? null;

    $arguments = match (true) {
        ! class_exists($what) => [$subjects[$what]],
        $parent === null => [$what],
        default => [$what, $subjects[$parent]],
    };

    return Gate::forUser($actor)->inspect($ability, $arguments);
}

/** @param  array{0: string, 1: string, 2?: string}  $target */
function describeTarget(array $target): string
{
    return $target[0].' '.class_basename($target[1]).(isset($target[2]) ? " (in a {$target[2]})" : '');
}

it('maps every row of the matrix, and only those', function (): void {
    expect(array_keys(documentedMatrix()))->toEqual(array_keys(MATRIX_ABILITIES));
});

it('maps every policy ability to a row', function (): void {
    $mapped = [];

    foreach (MATRIX_ABILITIES as $targets) {
        foreach (is_array($targets) ? $targets : [] as $target) {
            $model = class_exists($target[1]) ? $target[1] : MATRIX_SUBJECTS[$target[1]];
            $mapped[] = 'App\\Policies\\'.class_basename($model).'Policy::'.$target[0];
        }
    }

    $abilities = [];

    foreach (glob(dirname(__DIR__, 3).'/app/Policies/*Policy.php') ?: [] as $file) {
        $class = 'App\\Policies\\'.basename($file, '.php');

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $abilities[] = "{$class}::{$method->getName()}";
        }
    }

    sort($mapped);
    sort($abilities);

    expect(array_values(array_unique($mapped)))->toEqual($abilities);
});

it('allows exactly what the matrix says, on the actor\'s own records', function (string $row): void {
    $world = matrixWorld();
    $targets = MATRIX_ABILITIES[$row];
    expect($targets)->toBeArray();

    foreach (documentedMatrix()[$row] as $role => $allowed) {
        foreach (is_array($targets) ? $targets : [] as $target) {
            $response = inspectTarget($world['actors'][$role], $target, $world['own']);
            $what = "{$role}: ".describeTarget($target)." ({$row})";

            expect($response->allowed())->toBe($allowed, $what);

            // Wrong role on a record they may see is a 403, never a 404 (ACCESS.md).
            if (! $allowed && $role !== 'public') {
                expect($response->status())->not->toBe(404, "{$what} should be a 403");
            }
        }
    }
})->with(fn (): array => array_keys(array_filter(MATRIX_ABILITIES, 'is_array')));

it('denies every ability on another account\'s or another renter\'s records, as a 404 where the role could act on its own', function (): void {
    $world = matrixWorld();
    $matrix = documentedMatrix();

    foreach (MATRIX_ABILITIES as $row => $targets) {
        foreach (is_array($targets) ? $targets : [] as $target) {
            $parent = $target[2] ?? null;

            if (class_exists($target[1]) && $parent === null) {
                continue; // no record: always the actor's own account
            }

            foreach (['owner', 'caretaker', 'renter'] as $role) {
                // A renter may reserve or ask to view any listing, whichever account it's in.
                if ($role === 'renter' && in_array($parent, ['room', 'property'], true)) {
                    continue;
                }

                $response = inspectTarget($world['actors'][$role], $target, $world['other']);
                $what = "{$role}: ".describeTarget($target)." on another account's record ({$row})";

                expect($response->denied())->toBeTrue($what);

                if ($matrix[$row][$role]) {
                    expect($response->status())->toBe(404, $what);
                }
            }
        }
    }
});

it('lets each role browse published listings of every account as the matrix says', function (string $role): void {
    $world = matrixWorld();
    $allowed = documentedMatrix()['Browse published listings'][$role];
    $actor = $world['actors'][$role];

    if ($actor !== null) {
        $this->actingAs($actor);
    }

    expect(Property::query()->published()->whereKey($world['other']['property']->getKey())->exists())->toBe($allowed)
        ->and(Room::query()->listed()->whereKey($world['other']['room']->getKey())->exists())->toBe($allowed);
})->with(MATRIX_ROLES);

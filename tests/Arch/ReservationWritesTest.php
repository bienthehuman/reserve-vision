<?php

declare(strict_types=1);
use Illuminate\Database\Eloquent\Model;

// CONVENTIONS.md: a reservation's status changes only inside an action
// (App\Actions), which goes through ReservationStatus::assertCanTransitionTo().
// Pest's arch() sees classes, not statements, so this scans app/ for the
// writes. Factories and tests may build any status: they are not in app/.

/**
 * @return list<string> the forbidden writes found in the PHP source
 */
function forbiddenReservationWrites(string $source): array
{
    $patterns = [
        'Reservation::create/insert/upsert' => '/\bReservation::(create|forceCreate|insert|upsert|updateOrCreate|firstOrCreate)\s*\(/',
        'update([\'status\' => …])' => '/->(update|forceFill|fill)\(\s*\[\s*[\'"]status[\'"]/',
        '->status = …' => '/->status\s*=(?![=>])/',
    ];

    $hits = [];

    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $source) === 1) {
            $hits[] = $name;
        }
    }

    return $hits;
}

it('detects the writes it forbids', function (string $code): void {
    expect(forbiddenReservationWrites($code))->not->toBeEmpty();
})->with([
    'create' => 'Reservation::create([]);',
    'forceCreate' => 'Reservation::forceCreate($a);',
    'update' => '$r->update(["status" => "held"]);',
    'update, single quotes and spacing' => "\$r->update([ 'status'=>\$s ]);",
    'assignment' => '$reservation->status = ReservationStatus::Held;',
]);

it('ignores reads and comparisons', function (string $code): void {
    expect(forbiddenReservationWrites($code))->toBeEmpty();
})->with([
    'comparison' => 'if ($r->status === ReservationStatus::Held) {}',
    'loose comparison' => 'if ($r->status == $x) {}',
    'array key' => "['status' => \$r->status]",
    'update of another field' => '$r->update(["fee_refunded" => true]);',
]);

test('reservation status is only written inside App\\Actions', function (): void {
    $root = dirname(__DIR__, 2);
    $actions = $root.'/app/Actions/';
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/app", FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        /** @var SplFileInfo $file */
        $path = $file->getPathname();

        if ($file->getExtension() !== 'php' || str_starts_with($path, $actions)) {
            continue;
        }

        foreach (forbiddenReservationWrites((string) file_get_contents($path)) as $hit) {
            $violations[] = substr($path, strlen($root) + 1).": {$hit}";
        }
    }

    expect($violations)->toBeEmpty();
});

arch('enums are enums')
    ->expect('App\\Enums')
    ->toBeEnums();

arch('models are Eloquent models')
    ->expect('App\\Models')
    ->toExtend(Model::class);

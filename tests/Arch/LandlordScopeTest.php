<?php

declare(strict_types=1);

use App\Console\Commands\DoctorCommand;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Space;

// docs/ACCESS.md "Isolation": leaving the isolation scope is explicit and
// greppable. withoutGlobalScope(…) / withoutGlobalScopes() may appear only in
// the named public and renter scopes below, which add their own constraint, and
// in the system classes allowlisted here. Raw DB::table() on a landlord-owned
// table skips Eloquent (and so the scope) entirely: same rule. Pest's arch()
// sees classes, not calls, so this scans the source.

/** The named escapes: each removes the scope and constrains the query itself. */
const NAMED_ESCAPES = [
    Property::class.'::scopePublished',
    Room::class.'::scopeListed',
    Space::class.'::scopeListed',
    Reservation::class.'::scopeOwnedByRenter',
    Reservation::class.'::scopeOnListedSpaces',
];

/**
 * System code that removes the scope on purpose, with the reason in its
 * docblock. Empty: console commands, jobs and seeders have no landlord user, so
 * the scope is already inactive there. A class goes here only if it must act
 * across accounts while a landlord user may be logged in.
 *
 * @var list<class-string>
 */
const SYSTEM_CLASSES_WITHOUT_SCOPE = [];

/** System code that reads or writes a landlord-owned table with the query builder. */
const SYSTEM_CLASSES_WITH_RAW_TABLES = [
    DoctorCommand::class, // the audit_logs trigger probe, rolled back
];

const LANDLORD_OWNED_TABLES = [
    'properties', 'rooms', 'spaces', 'reservations', 'payment_proofs',
    'viewing_requests', 'property_photos', 'audit_logs', 'landlord_invitations',
];

/**
 * Every call that leaves the scope, as "Class::method" (or "Class" outside a method).
 *
 * @return list<array{where: string, call: string}>
 */
function scopeEscapes(string $source): array
{
    $tables = implode('|', LANDLORD_OWNED_TABLES);
    $patterns = [
        'withoutGlobalScope' => '/->\s*withoutGlobalScopes?\s*\(|::\s*withoutGlobalScopes?\s*\(/',
        'DB::table' => "/DB::table\\(\\s*['\"]({$tables})['\"]/",
    ];

    $namespace = preg_match('/^namespace\s+([^;]+);/m', $source, $m) === 1 ? $m[1].'\\' : '';
    $hits = [];

    foreach ($patterns as $call => $pattern) {
        preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [, $offset]) {
            $before = substr($source, 0, $offset);
            $class = preg_match_all('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|trait|enum)\s+(\w+)/m', $before, $c) > 0 ? $namespace.end($c[1]) : '(file)';
            $method = preg_match_all('/function\s+(\w+)\s*\(/', $before, $f) > 0 ? end($f[1]) : null;
            $hits[] = ['where' => $method === null ? $class : "{$class}::{$method}", 'call' => $call];
        }
    }

    return $hits;
}

it('finds the calls it forbids', function (string $code, string $where): void {
    expect(scopeEscapes($code))->toContain(['where' => $where, 'call' => str_contains($code, 'DB::') ? 'DB::table' : 'withoutGlobalScope']);
})->with([
    'all scopes' => ["<?php\nnamespace App\\X;\nfinal class A { public function b() { return P::query()->withoutGlobalScopes(); } }", 'App\\X\\A::b'],
    'one scope' => ["<?php\nnamespace App\\X;\nfinal class A { public function c() { \$q->withoutGlobalScope(S::class); } }", 'App\\X\\A::c'],
    'static' => ["<?php\nnamespace App\\X;\nfinal class A { public function d() { return Room::withoutGlobalScopes()->get(); } }", 'App\\X\\A::d'],
    'raw table' => ["<?php\nnamespace App\\X;\nfinal class A { public function e() { DB::table('reservations')->get(); } }", 'App\\X\\A::e'],
]);

it('ignores tables that are not landlord-owned', function (): void {
    expect(scopeEscapes("<?php\nfinal class A { public function f() { DB::table('jobs')->count(); DB::table('users')->first(); } }"))->toBeEmpty();
});

test('the isolation scope is left only in the named scopes and allowlisted system classes', function (): void {
    $root = dirname(__DIR__, 2);
    $violations = [];

    foreach (['app', 'database', 'routes'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // The seeders and factories insert with DB::table and build any account's rows: system code by definition.
            $path = substr($file->getPathname(), strlen($root) + 1);
            $isSeedingCode = str_starts_with($path, 'database/');

            foreach (scopeEscapes((string) file_get_contents($file->getPathname())) as ['where' => $where, 'call' => $call]) {
                $class = explode('::', $where)[0];
                $allowed = $call === 'DB::table'
                    ? $isSeedingCode || in_array($class, SYSTEM_CLASSES_WITH_RAW_TABLES, true)
                    : in_array($where, NAMED_ESCAPES, true) || in_array($class, SYSTEM_CLASSES_WITHOUT_SCOPE, true);

                if (! $allowed) {
                    $violations[] = "{$path}: {$call} in {$where}";
                }
            }
        }
    }

    expect($violations)->toBeEmpty();
});

test('every named escape exists and removes only the isolation scope', function (): void {
    foreach (NAMED_ESCAPES as $escape) {
        [$class, $method] = explode('::', $escape);
        $reflection = new ReflectionMethod($class, $method);
        $lines = array_slice(
            file((string) $reflection->getFileName()) ?: [],
            (int) $reflection->getStartLine() - 1,
            (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1,
        );
        $body = implode('', $lines);

        expect($body)->toContain('withoutGlobalScope(LandlordAccountScope::class)')
            ->not->toContain('withoutGlobalScopes(');
    }
});

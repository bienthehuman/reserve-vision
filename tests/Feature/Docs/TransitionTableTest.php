<?php

declare(strict_types=1);

use App\Enums\ReservationStatus;

// CONVENTIONS.md, "Docs that are tested": docs/RESERVATIONS.md is the source of
// truth for the lifecycle. Change a row there and in ReservationStatus::transitions()
// together, or this fails.

/**
 * The From, To and Action columns of the "Transition table" in the doc.
 *
 * @return list<string> "from|to|Action", from is "-" for the creating action
 */
function documentedTransitions(): array
{
    $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/RESERVATIONS.md');

    $start = strpos($doc, "### Transition table\n");
    expect($start)->not->toBeFalse('docs/RESERVATIONS.md has no "### Transition table" heading');

    $section = substr($doc, (int) $start + 1);
    $end = strpos($section, "\n#");
    $section = $end === false ? $section : substr($section, 0, $end);

    $rows = [];

    foreach (explode("\n", $section) as $line) {
        if (! str_starts_with($line, '|')) {
            continue;
        }

        $cells = array_map(fn (string $cell): string => trim($cell, " \t`"), explode('|', trim($line, '| ')));

        if ($cells[0] === 'From' || preg_match('/^-+$/', $cells[0]) === 1) {
            continue; // header and separator
        }

        $rows[] = ($cells[0] === '—' ? '-' : $cells[0]).'|'.$cells[1].'|'.$cells[2];
    }

    return $rows;
}

/** @return list<string> */
function codedTransitions(): array
{
    return array_map(
        fn (array $row): string => ($row['from']->value ?? '-').'|'.$row['to']->value.'|'.$row['action'],
        ReservationStatus::transitions(),
    );
}

it('matches the transition table in docs/RESERVATIONS.md exactly', function (): void {
    $documented = documentedTransitions();
    $coded = codedTransitions();

    expect($documented)->not->toBeEmpty();

    sort($documented);
    sort($coded);

    expect($coded)->toBe($documented);
});

it('lists every status in the doc table and every doc status in the enum', function (): void {
    $statuses = array_map(fn (ReservationStatus $s): string => $s->value, ReservationStatus::cases());

    foreach (documentedTransitions() as $row) {
        [$from, $to] = explode('|', $row);

        expect($statuses)->toContain($to);
        $from === '-' || expect($statuses)->toContain($from);
    }
});

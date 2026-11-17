<?php

declare(strict_types=1);

use App\Enums\ViewingRequestStatus;

// CONVENTIONS.md, "Docs that are tested": docs/ACCESS.md (flow C) is the source of truth for the viewing
// request lifecycle. Change a row there and in ViewingRequestStatus::transitions() together, or this fails.

/**
 * The From, To and Action columns of the "Transition table" under flow C in the doc.
 *
 * @return list<string> "from|to|Action", from is "-" for the creating action
 */
function documentedViewingTransitions(): array
{
    $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/ACCESS.md');

    $start = strpos($doc, "#### Transition table\n");
    expect($start)->not->toBeFalse('docs/ACCESS.md has no "#### Transition table" heading (flow C)');

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
function codedViewingTransitions(): array
{
    return array_map(
        fn (array $row): string => ($row['from']->value ?? '-').'|'.$row['to']->value.'|'.$row['action'],
        ViewingRequestStatus::transitions(),
    );
}

it('matches the viewing transition table in docs/ACCESS.md exactly', function (): void {
    $documented = documentedViewingTransitions();
    $coded = codedViewingTransitions();

    expect($documented)->not->toBeEmpty();

    sort($documented);
    sort($coded);

    expect($coded)->toBe($documented);
});

it('lists every viewing status in the enum, and no status the enum lacks', function (): void {
    $statuses = array_map(fn (ViewingRequestStatus $s): string => $s->value, ViewingRequestStatus::cases());

    foreach (documentedViewingTransitions() as $row) {
        [$from, $to] = explode('|', $row);

        expect($statuses)->toContain($to);
        $from === '-' || expect($statuses)->toContain($from);
    }

    $reached = array_map(fn (array $row): string => $row['to']->value, ViewingRequestStatus::transitions());

    foreach ($statuses as $status) {
        expect($reached)->toContain($status);
    }
});

it('only offers moves the table has', function (): void {
    expect(ViewingRequestStatus::Requested->canTransitionTo(ViewingRequestStatus::Accepted))->toBeTrue()
        ->and(ViewingRequestStatus::Declined->canTransitionTo(ViewingRequestStatus::Accepted))->toBeTrue()
        ->and(ViewingRequestStatus::Accepted->canTransitionTo(ViewingRequestStatus::Done))->toBeTrue()
        ->and(ViewingRequestStatus::Requested->canTransitionTo(ViewingRequestStatus::Done))->toBeFalse()
        ->and(ViewingRequestStatus::Declined->canTransitionTo(ViewingRequestStatus::Cancelled))->toBeFalse()
        ->and(ViewingRequestStatus::Done->canTransitionTo(ViewingRequestStatus::Accepted))->toBeFalse()
        ->and(ViewingRequestStatus::Cancelled->canTransitionTo(ViewingRequestStatus::Requested))->toBeFalse();
});

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Enums\ViewingRequestStatus;
use App\Models\ViewingRequest;
use App\Support\Viewings\ViewingSlots;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The account's viewing requests (slice 6.2), 15 a page, optionally one status (`?status=requested`; an unknown
 * value shows all) and optionally the dashboard's list (`?show=waiting`). Order: viewings still to come
 * **today** (Manila) first, marked "Today"; then the others still to come, soonest first; then the past ones,
 * latest first. The renter shows by name only: email and phone are on the detail page (ACCESS.md).
 */
final class ListViewings
{
    public const int PER_PAGE = 15;

    /** `?show=` values: the list behind the dashboard's card. */
    public const array SHOWS = ['waiting'];

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', ViewingRequest::class);

        // `expired` is a display status (requested, unanswered, the time gone); `requested` then means still waiting.
        $filter = $request->string('status')->toString();
        $expired = $filter === 'expired';
        $status = $expired ? null : ViewingRequestStatus::tryFrom($filter);
        $show = in_array($request->string('show')->toString(), self::SHOWS, true) ? $request->string('show')->toString() : null;

        $now = ViewingRequest::now();
        $endOfToday = CarbonImmutable::instance(ViewingSlots::now()->endOfDay())->utc();
        $format = 'Y-m-d H:i:s';

        $page = ViewingRequest::query()
            ->with(['property', 'renter'])
            ->when($expired, fn (Builder $query) => $query->unanswered())
            ->when($status === ViewingRequestStatus::Requested, fn (Builder $query) => $query->waiting())
            ->when($status !== null && $status !== ViewingRequestStatus::Requested, fn (Builder $query) => $query->where('viewing_requests.status', $status))
            ->when($show === 'waiting', fn (Builder $query) => $query->waiting())
            // 0: still to come today and open, 1: still to come, 2: past.
            ->orderByRaw(
                'case when viewing_requests.preferred_at < ? then 2 when viewing_requests.preferred_at <= ? and viewing_requests.status in (?, ?) then 0 else 1 end',
                [$now->format($format), $endOfToday->format($format), ViewingRequestStatus::Requested->value, ViewingRequestStatus::Accepted->value],
            )
            ->orderByRaw('case when viewing_requests.preferred_at >= ? then viewing_requests.preferred_at end asc', [$now->format($format)])
            ->orderByDesc('viewing_requests.preferred_at')
            ->orderByDesc('viewing_requests.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Landlord/Viewings/Index', [
            'viewings' => $page->getCollection()->map(fn (ViewingRequest $viewing): array => [
                'id' => $viewing->id,
                'status' => $viewing->shownStatus(),
                'renter_name' => $viewing->renter->full_name,
                'property_name' => $viewing->property->name,
                'preferred_at' => $viewing->preferred_at->toIso8601String(),
                'is_today' => $viewing->isUpcoming() && $viewing->preferred_at->lte($endOfToday)
                    && in_array($viewing->status, [ViewingRequestStatus::Requested, ViewingRequestStatus::Accepted], true),
                'is_upcoming' => $viewing->isUpcoming(),
                'message' => $viewing->message,
            ])->all(),
            'pagination' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
            ],
            'filter' => ['status' => $expired ? 'expired' : $status?->value, 'show' => $show],
            'statuses' => [...array_map(fn (ViewingRequestStatus $s): string => $s->value, ViewingRequestStatus::cases()), 'expired'],
        ]);
    }
}

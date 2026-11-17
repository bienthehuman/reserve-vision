<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord\Concerns;

use App\Models\ViewingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The answer to a landlord move on a viewing request (slice 6.2): back to its page with the confirmation, or
 * `200 {message, status}` for JSON. A refusal is the domain exception's: the `error` flash, or `422 {message}`.
 */
trait AnswersViewings
{
    private function answer(Request $request, ViewingRequest $viewing, string $message): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message, 'status' => $viewing->status->value])
            : redirect()->route('landlord.viewings.show', $viewing->id)->with('status', $message);
    }
}

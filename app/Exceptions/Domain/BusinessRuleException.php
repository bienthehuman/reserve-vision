<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A request broke a business rule. The message is written for the user and is
 * shown as is (CONVENTIONS.md "Code"):
 *
 * - JSON requests get `422 {"message": …}`.
 * - Inertia requests are redirected back with the message in the `error` flash
 *   (shared as `flash.error` by HandleInertiaRequests), so the form stays put.
 * - A plain browser form post (no JavaScript: it has a session and says where it came from) is redirected
 *   back the same way, so the page shows the message above the form.
 * - Anything else gets the 422 error page with the message.
 *
 * Not reported to the log (bootstrap/app.php): it's an expected outcome.
 */
abstract class BusinessRuleException extends DomainException
{
    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        if ($request->hasHeader('X-Inertia')) {
            return back(303)->with('error', $this->getMessage());
        }

        if ($request->isMethod('POST') && $request->hasSession() && $request->headers->has('referer')) {
            return back(303)->with('error', $this->getMessage());
        }

        return app(ExceptionHandler::class)->render($request, new HttpException(422, $this->getMessage(), $this));
    }
}

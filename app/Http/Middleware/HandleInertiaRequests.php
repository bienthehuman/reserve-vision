<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Props shared with every page. Typed in resources/js/types/index.ts.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            // A broken business rule on a form post (BusinessRuleException::render()).
            'flash' => [
                'error' => fn (): mixed => $request->hasSession() ? $request->session()->get('error') : null,
            ],
        ];
    }
}

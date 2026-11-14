<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

final class HomeController
{
    public function __invoke(): Response
    {
        return Inertia::render('Public/Home', [
            'pitch' => __('Reserve apartments and boarding-house beds online, with no double bookings.'),
        ]);
    }
}

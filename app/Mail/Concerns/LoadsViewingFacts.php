<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Data\Mail\ViewingFacts;
use App\Models\ViewingRequest;

/** A viewing mail carries the request's id and reads the facts when it is rendered (see LoadsReservationFacts). */
trait LoadsViewingFacts
{
    private ?ViewingFacts $loadedFacts = null;

    private function facts(): ViewingFacts
    {
        return $this->loadedFacts ??= ViewingFacts::of(ViewingRequest::query()->findOrFail($this->viewingId));
    }
}

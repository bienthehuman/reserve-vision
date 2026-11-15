<?php

declare(strict_types=1);

namespace Tests\Feature\Isolation\Support;

use App\Models\Property;
use App\Models\User;
use App\Support\CurrentLandlordAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;

/**
 * Records what the isolation machinery sees inside a queued job. With
 * `$logInAs`, the job first logs that user in, as a careless job might, to
 * prove the next job on the same worker doesn't inherit them.
 */
final class IsolationProbeJob implements ShouldQueue
{
    use Queueable;

    /** @var array<string, array{applies: bool, account: int|null, properties: list<int>}> */
    public static array $seen = [];

    public function __construct(public string $name, public ?int $logInAs = null) {}

    public function handle(CurrentLandlordAccount $current): void
    {
        if ($this->logInAs !== null) {
            Auth::guard()->setUser(User::query()->findOrFail($this->logInAs));
        }

        /** @var list<int> $properties */
        $properties = Property::query()->orderBy('id')->pluck('id')->all();

        self::$seen[$this->name] = [
            'applies' => $current->applies(),
            'account' => $current->idOrNull(),
            'properties' => $properties,
        ];
    }
}

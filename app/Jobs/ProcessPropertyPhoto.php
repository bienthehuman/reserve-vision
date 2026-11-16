<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\FailPropertyPhoto;
use App\Actions\ResizePropertyPhoto;
use App\Models\PropertyPhoto;
use App\Support\Photos\UnreadablePhoto;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Turns one upload into the thumbnail and the full size (slice 4.2). Queued after the upload's
 * transaction commits. Three tries, 10 s and then 60 s apart, for storage that is briefly away;
 * a file that can't be decoded fails at once. The work and the status changes are the actions'
 * (ResizePropertyPhoto, FailPropertyPhoto): a status is only written in App\Actions.
 */
final class ProcessPropertyPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $photoId)
    {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ResizePropertyPhoto $resize): void
    {
        $photo = PropertyPhoto::query()->find($this->photoId);

        if ($photo === null) {
            return; // deleted while it waited
        }

        try {
            $resize->handle($photo);
        } catch (UnreadablePhoto $e) {
            $this->fail($e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $photo = PropertyPhoto::query()->find($this->photoId);

        if ($photo !== null) {
            app(FailPropertyPhoto::class)->handle($photo);
        }
    }
}

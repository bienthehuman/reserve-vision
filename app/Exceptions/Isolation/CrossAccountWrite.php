<?php

declare(strict_types=1);

namespace App\Exceptions\Isolation;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A landlord user's request tried to write a row that belongs to another
 * landlord account (BelongsToLandlordAccount). Never expected: the scope and the
 * policies stop it earlier, so this is the last line, and it fails loudly.
 */
final class CrossAccountWrite extends LogicException
{
    public static function for(Model $model, mixed $accountId, ?int $currentId): self
    {
        $account = is_scalar($accountId) ? (string) $accountId : 'unknown';
        $current = $currentId === null ? 'none (no membership)' : (string) $currentId;

        return new self(sprintf(
            'Refusing to write %s for landlord account %s: the current landlord account is %s.',
            class_basename($model), $account, $current,
        ));
    }
}

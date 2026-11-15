<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\Isolation\CrossAccountWrite;
use App\Models\Scopes\LandlordAccountScope;
use App\Support\CurrentLandlordAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * A landlord-owned model (docs/ACCESS.md "Isolation"): the isolation scope on
 * every query, and a write guard. While a landlord user is logged in, a new
 * row gets the current account if it has none, and saving a row of another
 * account (or with no membership at all) throws CrossAccountWrite. Without a
 * landlord user (renters, console commands, jobs) the caller sets the account.
 *
 * @mixin Model
 */
trait BelongsToLandlordAccount
{
    public static function bootBelongsToLandlordAccount(): void
    {
        static::addGlobalScope(new LandlordAccountScope);

        static::saving(function (Model $model): void {
            $current = app(CurrentLandlordAccount::class);

            if (! $current->applies()) {
                return;
            }

            $currentId = $current->idOrNull();

            if (! $model->exists && $model->getAttribute('landlord_account_id') === null && $currentId !== null) {
                $model->setAttribute('landlord_account_id', $currentId);
            }

            $accountId = $model->getAttribute('landlord_account_id');

            if ($currentId === null || ! is_numeric($accountId) || (int) $accountId !== $currentId) {
                throw CrossAccountWrite::for($model, $accountId, $currentId);
            }
        });
    }
}

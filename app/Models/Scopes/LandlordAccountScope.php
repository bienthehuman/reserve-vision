<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\CurrentLandlordAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The isolation scope (docs/ACCESS.md "Isolation"). While a landlord user is
 * logged in, every query on a landlord-owned model gets
 * `where <table>.landlord_account_id = <their account>`; a landlord user
 * without a membership gets a condition that matches nothing (fail closed).
 * With no landlord user (guests, renters, console commands, queued jobs) it
 * adds nothing: those go through named scopes that say what they may see.
 *
 * Removing it is allowed only in the named scopes and system classes listed
 * in tests/Arch/LandlordScopeTest.php.
 *
 * @implements Scope<Model>
 */
final class LandlordAccountScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentLandlordAccount::class);

        if (! $current->applies()) {
            return;
        }

        $id = $current->idOrNull();

        if ($id === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('landlord_account_id'), $id);
    }
}

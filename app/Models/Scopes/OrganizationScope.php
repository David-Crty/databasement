<?php

namespace App\Models\Scopes;

use App\Services\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** @implements Scope<Model> */
class OrganizationScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * In CLI context (artisan commands) no org is resolved, so the scope is
     * not applied — commands process all orgs.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $currentOrg = app(CurrentOrganization::class);

        if ($currentOrg->isResolved()) {
            $builder->getQuery()->where($model->getTable().'.organization_id', $currentOrg->id());
        }
    }
}

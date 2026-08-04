<?php

namespace App\Models\Scopes;

use App\Services\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Organization scope for models that own no `organization_id` column and
 * inherit their tenant from a related database server. Like {@see OrganizationScope},
 * it does not apply in CLI context where no org is resolved.
 *
 * @implements Scope<Model>
 */
readonly class DatabaseServerOrganizationScope implements Scope
{
    /**
     * @param  string  $foreignKey  Column on the scoped table pointing at `database_servers.id`.
     */
    public function __construct(private string $foreignKey) {}

    public function apply(Builder $builder, Model $model): void
    {
        $currentOrg = app(CurrentOrganization::class);

        if (! $currentOrg->isResolved()) {
            return;
        }

        $orgId = $currentOrg->id();
        $column = $model->getTable().'.'.$this->foreignKey;

        $builder->getQuery()->whereExists(function (QueryBuilder $query) use ($column, $orgId) {
            $query->from('database_servers')
                ->whereColumn('database_servers.id', $column)
                ->where('database_servers.organization_id', $orgId);
        });
    }
}

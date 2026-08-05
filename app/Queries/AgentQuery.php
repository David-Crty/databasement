<?php

namespace App\Queries;

use App\Models\Agent;
use App\Support\Formatters;
use Illuminate\Database\Eloquent\Builder;

class AgentQuery
{
    private const ALLOWED_SORT_COLUMNS = [
        'name',
        'last_heartbeat_at',
        'created_at',
    ];

    /**
     * Apply a validated sort to the query.
     *
     * @param  Builder<Agent>  $query
     * @param  array<string, string>  $sortBy
     * @return Builder<Agent>
     */
    public static function applySort(Builder $query, array $sortBy): Builder
    {
        $column = Formatters::sortColumn($sortBy['column'] ?? null, self::ALLOWED_SORT_COLUMNS);
        $direction = Formatters::sortDirection($sortBy['direction'] ?? 'desc');

        return $query->orderBy($column, $direction);
    }
}

<?php

namespace App\Queries;

use App\Models\Agent;
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
        $column = in_array($sortBy['column'] ?? 'created_at', self::ALLOWED_SORT_COLUMNS, true)
            ? $sortBy['column']
            : 'created_at';

        $direction = strtolower($sortBy['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($column, $direction);
    }
}

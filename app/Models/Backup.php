<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'database_server_id',
        'volume_id',
        'recurrence',
    ];

    public function databaseServer(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class);
    }

    public function volume(): BelongsTo
    {
        return $this->belongsTo(Volume::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\DatabaseServerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DatabaseServer extends Model
{
    /** @use HasFactory<DatabaseServerFactory> */
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'name',
        'host',
        'port',
        'database_type',
        'username',
        'password',
        'database_name',
        'description',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
        ];
    }
}

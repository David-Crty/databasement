<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Volume extends Model
{
    /** @use HasFactory<\Database\Factories\VolumeFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'name',
        'type',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }
}

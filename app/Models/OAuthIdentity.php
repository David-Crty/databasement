<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperOAuthIdentity
 */
class OAuthIdentity extends Model
{
    protected $table = 'oauth_identities';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'email',
        'name',
        'avatar',
        'access_token',
        'refresh_token',
        'token_expires_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<User, OAuthIdentity>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

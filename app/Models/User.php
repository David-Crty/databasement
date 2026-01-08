<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property string|null $two_factor_confirmed_at
 * @property string $role
 * @property string|null $invitation_token
 * @property Carbon|null $invitation_accepted_at
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, Snapshot> $triggeredSnapshots
 * @property-read int|null $triggered_snapshots_count
 * @property-read Collection<int, OAuthIdentity> $oauthIdentities
 * @property-read int|null $oauth_identities_count
 *
 * @method static Builder<static>|User active()
 * @method static UserFactory factory($count = null, $state = [])
 * @method static Builder<static>|User newModelQuery()
 * @method static Builder<static>|User newQuery()
 * @method static Builder<static>|User pending()
 * @method static Builder<static>|User query()
 * @method static Builder<static>|User whereCreatedAt($value)
 * @method static Builder<static>|User whereEmail($value)
 * @method static Builder<static>|User whereEmailVerifiedAt($value)
 * @method static Builder<static>|User whereId($value)
 * @method static Builder<static>|User whereInvitationAcceptedAt($value)
 * @method static Builder<static>|User whereInvitationToken($value)
 * @method static Builder<static>|User whereName($value)
 * @method static Builder<static>|User wherePassword($value)
 * @method static Builder<static>|User whereRememberToken($value)
 * @method static Builder<static>|User whereRole($value)
 * @method static Builder<static>|User whereTwoFactorConfirmedAt($value)
 * @method static Builder<static>|User whereTwoFactorRecoveryCodes($value)
 * @method static Builder<static>|User whereTwoFactorSecret($value)
 * @method static Builder<static>|User whereUpdatedAt($value)
 *
 * @property-read Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    public const ROLE_DEMO = 'demo';

    public const ROLE_VIEWER = 'viewer';

    public const ROLE_MEMBER = 'member';

    public const ROLE_ADMIN = 'admin';

    public const ROLES = [
        self::ROLE_DEMO,
        self::ROLE_VIEWER,
        self::ROLE_MEMBER,
        self::ROLE_ADMIN,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'invitation_token',
        'invitation_accepted_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'invitation_accepted_at' => 'datetime',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /**
     * @return HasMany<Snapshot, User>
     */
    public function triggeredSnapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class, 'triggered_by_user_id');
    }

    /**
     * @return HasMany<OAuthIdentity, User>
     */
    public function oauthIdentities(): HasMany
    {
        return $this->hasMany(OAuthIdentity::class);
    }

    public function isDemo(): bool
    {
        return $this->role === self::ROLE_DEMO;
    }

    public function isViewer(): bool
    {
        return $this->role === self::ROLE_VIEWER;
    }

    public function isMember(): bool
    {
        return $this->role === self::ROLE_MEMBER;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function canManageUsers(): bool
    {
        return $this->isAdmin();
    }

    public function canPerformActions(): bool
    {
        return ! $this->isViewer();
    }

    public function isPending(): bool
    {
        return $this->invitation_token !== null && $this->password === null;
    }

    public function isActive(): bool
    {
        return $this->invitation_accepted_at !== null;
    }

    /**
     * Check if user authenticated only via OAuth (no password set).
     */
    public function isOAuthOnly(): bool
    {
        return $this->password === null;
    }

    public function generateInvitationToken(): string
    {
        $this->invitation_token = Str::random(64);
        $this->save();

        return $this->invitation_token;
    }

    public function getInvitationUrl(): ?string
    {
        return $this->invitation_token
            ? route('invitation.accept', $this->invitation_token)
            : null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->whereNotNull('invitation_accepted_at');
    }
}

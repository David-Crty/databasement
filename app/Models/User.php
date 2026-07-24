<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Silber\Bouncer\Database\HasRolesAndAbilities;

/**
 * @mixin IdeHelperUser
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRolesAndAbilities, Notifiable, TwoFactorAuthenticatable;

    /**
     * The email address of the demo user. In demo mode the account with this
     * exact address is the read-only demo user (see isDemo()); there is no
     * dedicated "demo" role.
     */
    public const DEMO_EMAIL = 'demo@example.com';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'super_admin',
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
            'super_admin' => 'boolean',
            'invitation_accepted_at' => 'datetime',
        ];
    }

    /**
     * Transient role name used by UserFactory to assign a Bouncer role after the
     * user is created. Not persisted to the database.
     */
    public ?string $pendingRole = null;

    /**
     * Transient list of catalogue ability names used by UserFactory to grant
     * direct (per-org) abilities after the user is created. Not persisted.
     *
     * @var list<string>|null
     */
    public ?array $pendingAbilities = null;

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

    /**
     * @return BelongsToMany<Organization, User>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return $this->super_admin;
    }

    /**
     * The name of the role assigned to the user in an organization (built-in or
     * custom), or null if none.
     */
    public function roleNameIn(Organization $organization): ?string
    {
        return $this->roleNamesIn($organization)[0] ?? null;
    }

    /**
     * Names of every role (built-in and custom) the user is assigned in an org.
     *
     * @return list<string>
     */
    public function roleNamesIn(Organization $organization): array
    {
        return array_values(
            DB::table('assigned_roles')
                ->join('roles', 'roles.id', '=', 'assigned_roles.role_id')
                ->where('assigned_roles.entity_id', $this->getKey())
                ->where('assigned_roles.entity_type', $this->getMorphClass())
                ->where('assigned_roles.scope', $organization->id)
                ->pluck('roles.name')
                ->map(fn ($name) => (string) $name)
                ->all()
        );
    }

    /**
     * Names of the abilities granted directly to the user (not via a role) in an
     * organization. These are additive: a user's effective abilities are their
     * role's abilities plus these.
     *
     * @return list<string>
     */
    public function directAbilitiesIn(Organization $organization): array
    {
        return array_values(
            DB::table('permissions')
                ->join('abilities', 'abilities.id', '=', 'permissions.ability_id')
                ->where('permissions.entity_id', $this->getKey())
                ->where('permissions.entity_type', $this->getMorphClass())
                ->where('permissions.forbidden', false)
                ->where('permissions.scope', $organization->id)
                ->pluck('abilities.name')
                ->map(fn ($name) => (string) $name)
                ->all()
        );
    }

    /**
     * Check if user belongs to a given organization.
     */
    public function belongsToOrganization(Organization $organization): bool
    {
        return $this->organizations()->wherePivot('organization_id', $organization->id)->exists();
    }

    /**
     * Get the user's role name in the current org context.
     */
    public function currentOrgRoleName(): ?string
    {
        return $this->roleNameIn(app(\App\Services\CurrentOrganization::class)->model());
    }

    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->currentOrgRoleName() === 'admin';
    }

    /**
     * Whether this is the demo account: demo mode must be enabled and the email
     * must match the fixed demo address. Deliberately independent of roles —
     * there is no "demo" role.
     */
    public function isDemo(): bool
    {
        return config('app.demo_mode') === true && $this->email === self::DEMO_EMAIL;
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
     * Check if user authenticated via OAuth.
     */
    public function isOAuth(): bool
    {
        return $this->oauthIdentities()->exists();
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

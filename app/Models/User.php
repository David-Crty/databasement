<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @mixin IdeHelperUser
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

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

    /** Transient property used by UserFactory to propagate the pivot role. */
    public ?UserRole $pendingPivotRole = null;

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
        return $this->belongsToMany(Organization::class)->withPivot('role')->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return $this->super_admin;
    }

    /** @var array<string, UserRole|null> */
    private array $cachedRoles = [];

    /**
     * @return $this
     */
    public function refresh(): static
    {
        $this->cachedRoles = [];

        return parent::refresh();
    }

    /**
     * Get the user's role in a specific organization.
     */
    public function roleIn(Organization $organization): ?UserRole
    {
        if (! isset($this->cachedRoles[$organization->id])) {
            if ($this->relationLoaded('organizations')) {
                $match = $this->organizations->firstWhere('id', $organization->id);
                $pivotRole = $match?->pivot?->role; // @phpstan-ignore property.notFound
            } else {
                $pivot = $this->organizations()->wherePivot('organization_id', $organization->id)->first();
                $pivotRole = $pivot?->pivot?->role; // @phpstan-ignore property.notFound
            }
            $this->cachedRoles[$organization->id] = $pivotRole ? UserRole::tryFrom($pivotRole) : null;
        }

        return $this->cachedRoles[$organization->id];
    }

    /**
     * Check if user belongs to a given organization.
     */
    public function belongsToOrganization(Organization $organization): bool
    {
        return $this->organizations()->wherePivot('organization_id', $organization->id)->exists();
    }

    /**
     * Get the user's role in the current org context.
     */
    private function currentOrgRole(): ?UserRole
    {
        return $this->roleIn(app(\App\Services\CurrentOrganization::class)->model());
    }

    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->currentOrgRole() === UserRole::Admin;
    }

    public function canPerformActions(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($this->currentOrgRole(), [UserRole::Admin, UserRole::Member]);
    }

    public function canManageUsers(): bool
    {
        return $this->isAdmin();
    }

    public function isDemo(): bool
    {
        return $this->currentOrgRole() === UserRole::Demo;
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

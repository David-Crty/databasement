<?php

namespace Database\Factories;

use App\Enums\Ability;
use App\Models\Organization;
use App\Models\User;
use App\Services\Roles\AssignRoleToUserAction;
use App\Services\Roles\SyncUserAbilitiesAction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= 'password',
            'super_admin' => false,
            'role' => 'viewer', // Virtual — not a DB column; intercepted by newModel()
            'invitation_accepted_at' => now(),
            'remember_token' => Str::random(10),
            'two_factor_secret' => Str::random(10),
            'two_factor_recovery_codes' => Str::random(10),
            'two_factor_confirmed_at' => now(),
        ];
    }

    /**
     * Intercept the virtual 'role' attribute before forceFill() tries to write
     * it to the model. The value (a role name) is stashed on
     * {@see User::$pendingRole} so that {@see configure()}'s afterCreating hook
     * can assign the matching Bouncer role within the organization scope.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = [])
    {
        $role = (string) ($attributes['role'] ?? 'viewer');
        unset($attributes['role']);

        $abilities = $attributes['abilities'] ?? null;
        unset($attributes['abilities']);

        $model = parent::newModel($attributes);
        $model->pendingRole = $role;
        $model->pendingAbilities = is_array($abilities) ? array_values($abilities) : null;

        return $model;
    }

    /**
     * After creating, add the user to the default org and assign their role as a
     * Bouncer assignment scoped to that org. The built-in roles already exist
     * (seeded by migration), so this only assigns.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            $org = rescue(fn () => Organization::default(), fn () => Organization::factory()->default()->create());
            $role = $user->pendingRole ?? 'viewer';
            $abilities = $user->pendingAbilities;
            $user->pendingRole = null;
            $user->pendingAbilities = null;

            if (! $user->organizations()->where('organization_id', $org->id)->exists()) {
                $user->organizations()->attach($org->id);
            }

            app(AssignRoleToUserAction::class)->execute($user, $role, $org);

            if (is_array($abilities) && $abilities !== []) {
                app(SyncUserAbilitiesAction::class)->execute($user, $abilities, $org);
            }
        });
    }

    /**
     * Set the user as a super admin (with admin role in org).
     */
    public function superAdmin(): static
    {
        return $this->state(['super_admin' => true, 'role' => 'admin']);
    }

    /**
     * Grant exactly the given catalogue abilities to the user in the default org,
     * on top of a baseline role that grants nothing (viewer). The user's effective
     * abilities then equal precisely the ones listed — ideal for authorization
     * tests that exercise a single ability in isolation. Pass an empty array (the
     * default) for a user with no special abilities, used for "access denied"
     * assertions.
     *
     * @param  list<string>  $abilities  ability names from the Ability catalogue
     */
    public function withAbilities(array $abilities = []): static
    {
        return $this->state(['role' => 'viewer', 'abilities' => $abilities]);
    }

    /**
     * Grant every catalogue ability EXCEPT the listed ones, on top of a baseline
     * role that grants nothing (viewer). This is the stronger "access denied"
     * actor for authorization deny cases: because the user holds every other
     * ability and is still forbidden, it proves the guarded action is gated by
     * precisely the excluded ability — not merely by an empty ability set.
     *
     * @param  string  ...$except  ability names withheld from the Ability catalogue
     */
    public function withAllAbilitiesExcept(string ...$except): static
    {
        return $this->withAbilities(array_values(array_diff(Ability::names(), $except)));
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model does not have two-factor authentication configured.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
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
            'role' => UserRole::Member, // Virtual — not a DB column; intercepted by newModel()
            'invitation_accepted_at' => now(),
            'remember_token' => Str::random(10),
            'two_factor_secret' => Str::random(10),
            'two_factor_recovery_codes' => Str::random(10),
            'two_factor_confirmed_at' => now(),
        ];
    }

    /**
     * Intercept the virtual 'role' attribute before forceFill() tries to
     * write it to the model. The value is stashed on {@see User::$pendingPivotRole}
     * so that {@see configure()}'s afterCreating hook can attach the user to the
     * org with the correct role.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = [])
    {
        $role = $attributes['role'] ?? UserRole::Member;
        unset($attributes['role']);

        if (is_string($role)) {
            $role = UserRole::from($role);
        }

        $model = parent::newModel($attributes);
        $model->pendingPivotRole = $role;

        return $model;
    }

    /**
     * After creating, attach the user to the main org with the configured role.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            $org = rescue(fn () => Organization::default(), fn () => Organization::factory()->default()->create());
            $role = $user->pendingPivotRole ?? UserRole::Member;
            $user->pendingPivotRole = null;

            if (! $user->organizations()->where('organization_id', $org->id)->exists()) {
                $user->organizations()->attach($org->id, ['role' => $role->value]);
            }
        });
    }

    /**
     * Set the user as a super admin (with admin role in org).
     */
    public function superAdmin(): static
    {
        return $this->state(['super_admin' => true, 'role' => UserRole::Admin]);
    }

    /**
     * Set the user's role in the default org to admin.
     */
    public function admin(): static
    {
        return $this->state(['role' => UserRole::Admin]);
    }

    /**
     * Set the user's role in the default org to operator.
     */
    public function operator(): static
    {
        return $this->state(['role' => UserRole::Operator]);
    }

    /**
     * Set the user's role in the default org to viewer.
     */
    public function viewer(): static
    {
        return $this->state(['role' => UserRole::Viewer]);
    }

    /**
     * Set the user's role in the default org to demo.
     */
    public function demo(): static
    {
        return $this->state(['role' => UserRole::Demo]);
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

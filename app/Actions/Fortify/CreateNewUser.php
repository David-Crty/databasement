<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\DemoBackupService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private readonly DemoBackupService $demoBackupService
    ) {}

    /**
     * Validate and create a newly registered user.
     *
     * Only the first user can register via this route.
     * All other users must be invited by an admin.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // Only allow registration if no users exist (first admin)
        if (User::count() > 0) {
            abort(403, 'Registration is closed. Please contact an administrator for an invitation.');
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        $createDemoBackup = ! empty($input['create_demo_backup']);

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => User::ROLE_ADMIN, // First user is always admin
            'invitation_accepted_at' => now(),
        ]);

        if ($createDemoBackup) {
            try {
                $this->demoBackupService->createDemoBackup();
            } catch (\Throwable $e) {
                // Log the error but don't fail registration
                Log::warning('Failed to create demo backup during registration', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $user;
    }
}

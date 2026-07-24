<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class ResetUserPassword extends Command
{
    protected $signature = 'user:reset-password {email? : The email address of the user}';

    protected $description = 'Reset a user password from the command line';

    public function handle(): int
    {
        $email = $this->argument('email') ?? text(
            label: 'What is the email address of the user?',
            required: true,
        );

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        $newPassword = password(
            label: 'Enter the new password',
            required: true,
            validate: fn (string $value): ?string => Validator::make(
                ['password' => $value],
                ['password' => ['string', Password::default()]],
            )->errors()->first('password') ?: null,
        );

        $user->update(['password' => $newPassword]);

        $this->info("Password reset for {$user->email}.");

        return self::SUCCESS;
    }
}

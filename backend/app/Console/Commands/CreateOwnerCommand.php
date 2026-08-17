<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates (or re-passwords) the one account that may change the archive.
 *
 * There is no sign-up screen: an archive has exactly one owner, and adding
 * them is a deployment step, not a feature.
 */
class CreateOwnerCommand extends Command
{
    protected $signature = 'archive:owner
        {--email= : The owner\'s email address}
        {--name= : The owner\'s display name}
        {--password= : Set non-interactively (avoid on a shared shell)}';

    protected $description = 'Create or update the archive owner';

    public function handle(): int
    {
        $email = $this->option('email') ?: config('memories.owner.email') ?: $this->ask('Email address');
        $name = $this->option('name') ?: config('memories.owner.name') ?: 'Owner';
        $password = $this->option('password') ?: config('memories.owner.password') ?: $this->secret('Password');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:120'],
                'password' => ['required', 'string', Password::min(12)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        // Any token issued against the previous password should stop working.
        $revoked = $user->tokens()->delete();

        $this->components->info("Owner ready: {$user->email}");

        if ($revoked > 0) {
            $this->components->warn("{$revoked} existing session(s) were signed out.");
        }

        return self::SUCCESS;
    }
}

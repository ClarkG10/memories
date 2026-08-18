<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Memory;
use App\Models\User;
use App\Services\MemoryCache;
use Illuminate\Console\Command;

/**
 * Put every memory under one owner.
 *
 * An archive belongs to one person, and the policy guarding every edit is
 * `$user->id === $memory->user_id`. Two users rows therefore split it: signing
 * in as one shows every memory and lets you change none of the other's, which
 * reaches the browser as "not authorized" and nothing more.
 *
 * That happens more easily than it should — `archive:owner` given a second
 * email creates a second person without saying so, and anything that had to
 * pick an owner for itself picked the first one it found.
 */
class ReassignCommand extends Command
{
    protected $signature = 'memories:reassign
        {--to= : The email address every memory should belong to}
        {--dry-run : Show what would move and change nothing}';

    protected $description = 'Give every memory to a single owner';

    public function handle(MemoryCache $cache): int
    {
        $owners = User::query()->withCount('memories')->orderBy('id')->get();

        if ($owners->isEmpty()) {
            $this->components->error('There is no owner yet. Run `php artisan archive:owner` first.');

            return self::FAILURE;
        }

        $this->components->info('Who holds what');

        foreach ($owners as $owner) {
            $this->line(sprintf('  %s — %d memor%s',
                $owner->email,
                (int) $owner->memories_count,
                (int) $owner->memories_count === 1 ? 'y' : 'ies',
            ));
        }

        $email = (string) ($this->option('to') ?? '');

        if ($email === '') {
            $this->newLine();
            $this->components->error('Say who they should belong to: --to=you@example.com');
            $this->line('  Use the address you sign in with, not necessarily the first one listed.');

            return self::FAILURE;
        }

        $keeper = $owners->firstWhere('email', $email);

        if ($keeper === null) {
            $this->components->error("No owner with the address {$email}.");

            return self::FAILURE;
        }

        $moving = Memory::withTrashed()->where('user_id', '!=', $keeper->id)->count();

        $this->newLine();

        if ($moving === 0) {
            $this->components->info("Everything already belongs to {$keeper->email}.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info("{$moving} memor(y/ies) would move to {$keeper->email}. Nothing changed.");

            return self::SUCCESS;
        }

        // withTrashed: a deleted memory still has an owner, and leaving it
        // behind would strand it if it were ever restored.
        Memory::withTrashed()
            ->where('user_id', '!=', $keeper->id)
            ->update(['user_id' => $keeper->id]);

        $cache->flush();

        $this->components->info("Moved {$moving} memor(y/ies) to {$keeper->email}.");
        $this->line('  Sign in as that address and everything is editable again.');

        return self::SUCCESS;
    }
}

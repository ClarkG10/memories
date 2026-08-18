<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Memory;
use App\Models\User;

/**
 * Who may change a memory.
 *
 * Reading is not decided here: the archive is public or private as a whole,
 * which the EnsureArchiveIsViewable middleware settles once for every read
 * route rather than per record.
 *
 * Writing is decided the same way, and deliberately. There is no sign-up —
 * the only accounts that exist are ones the owner made themselves from the
 * command line — so "signed in" and "the owner" are the same set of people.
 * Comparing the row's user_id on top of that guarded against a second tenant
 * who cannot exist, and in exchange gave the archive a way to refuse its owner
 * their own memories: anything created under a second account, by a command or
 * by an owner address that had been changed, became permanently uneditable and
 * said only "This action is unauthorized" about it.
 *
 * If this archive ever holds more than one person, this is the file that has
 * to change, and it should change to something richer than an id comparison.
 */
class MemoryPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Memory $memory): bool
    {
        return true;
    }

    public function delete(User $user, Memory $memory): bool
    {
        return $this->update($user, $memory);
    }
}

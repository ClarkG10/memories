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
 */
class MemoryPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Memory $memory): bool
    {
        return $user->id === $memory->user_id;
    }

    public function delete(User $user, Memory $memory): bool
    {
        return $this->update($user, $memory);
    }
}

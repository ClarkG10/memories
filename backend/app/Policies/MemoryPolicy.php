<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Memory;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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

    /**
     * A refusal that says why.
     *
     * An archive is meant to belong to one person, so this can only fail when
     * there are accidentally two of them — and the bare "This action is
     * unauthorized" that a boolean produces gives the owner nothing to act on
     * while they stare at their own memory refusing to be edited.
     */
    public function update(User $user, Memory $memory): Response
    {
        if ($user->id === $memory->user_id) {
            return Response::allow();
        }

        return Response::deny(
            'This memory belongs to a different sign-in on this archive. '
            .'Run `php artisan memories:reassign` on the server to put them back together.'
        );
    }

    public function delete(User $user, Memory $memory): Response
    {
        return $this->update($user, $memory);
    }
}

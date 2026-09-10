<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class UsernameResolver
{
    /**
     * @param  list<string>  $usernames
     * @return Collection<int, User>
     *
     * @throws ValidationException naming the first handle that matched nobody
     */
    public static function resolve(array $usernames, string $field = 'usernames'): Collection
    {
        if ($usernames === []) {
            return collect();
        }

        $found = User::query()->whereIn('username', $usernames)->get();
        $missing = collect($usernames)->diff($found->pluck('username'));

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                $field => 'No one has the username @'.$missing->first().'.',
            ]);
        }

        return $found;
    }
}

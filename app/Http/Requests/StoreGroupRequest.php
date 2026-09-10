<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreGroupRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'usernames' => collect($this->input('usernames', []))
                ->map(fn ($u) => User::normalizeUsername($u))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:60'],
            // A group of one is legal — people build the room before filling it.
            'usernames' => ['array', 'max:50'],
            'usernames.*' => ['string', 'regex:'.User::USERNAME_REGEX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'usernames.*.regex' => 'No one has that username.',
        ];
    }
}

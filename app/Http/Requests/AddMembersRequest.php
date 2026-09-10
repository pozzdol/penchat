<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class AddMembersRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
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
            'usernames' => ['required', 'array', 'min:1', 'max:50'],
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

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['body' => trim((string) $this->input('body'))]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // Attachments arrive in a later phase; until then a message is its text.
        return [
            'body' => ['required', 'string', 'max:4000'],
        ];
    }
}

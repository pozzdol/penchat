<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMessageRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['body' => trim((string) $this->input('body'))]);
    }

    /**
     * An edit cannot empty a message — that is what Delete is for, and it
     * leaves a tombstone rather than a blank bubble.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:4000'],
        ];
    }
}

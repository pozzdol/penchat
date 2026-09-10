<?php

namespace App\Http\Requests;

use App\Enums\ConversationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(ConversationRole::class)],
        ];
    }
}

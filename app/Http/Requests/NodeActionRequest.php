<?php

namespace App\Http\Requests;

use App\DTO\NodeActionDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NodeActionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['add', 'remove'])],
            'address' => ['required', 'url'],
        ];
    }

    public function toDTO(): NodeActionDTO
    {
        return new NodeActionDTO(
            action: $this->validated('action'),
            address: $this->validated('address'),
        );
    }
}
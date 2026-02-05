<?php

namespace App\Http\Requests;

use App\DTO\PressDTO;
use Illuminate\Foundation\Http\FormRequest;

class PressRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'string', 'max:255'],
        ];
    }

    public function toDTO(): PressDTO
    {
        return new PressDTO(
            sessionId: $this->validated('session_id'),
        );
    }
}
<?php

namespace App\DTO;

readonly class PressDTO
{
    public function __construct(
        public string $sessionId,
    ) {}
}
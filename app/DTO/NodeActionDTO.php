<?php

namespace App\DTO;

readonly class NodeActionDTO
{
    public function __construct(
        public string $action,
        public string $address,
    ) {}
}
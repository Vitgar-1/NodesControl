<?php

namespace App\Repositories;

use App\Models\SessionArchive;

class SessionArchiveRepository
{
    public function find(string $sessionId): ?SessionArchive
    {
        return SessionArchive::find($sessionId);
    }

    public function upsert(string $sessionId, int $clicks): void
    {
        SessionArchive::updateOrCreate(
            ['session_id' => $sessionId],
            ['clicks' => $clicks, 'archived_at' => now()],
        );
    }
}
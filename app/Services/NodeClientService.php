<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class NodeClientService
{
    /**
     * @throws ConnectionException
     */
    public function press(string $nodeAddress, string $sessionId): string
    {
        $response = Http::timeout(5)->post("$nodeAddress/press", [
            'session_id' => $sessionId,
        ]);

        return $response->body();
    }

    /**
     * @throws ConnectionException
     */
    public function clean(string $nodeAddress, bool $archive): void
    {
        Http::timeout(10)->post("$nodeAddress/clean", [
            'archive' => $archive,
        ]);
    }
}

<?php

namespace App\Console\Commands;

use App\Services\BalancerService;
use App\Services\NodeClientService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanExpiredSessions extends Command
{
    protected $signature = 'sessions:clean-expired';
    protected $description = 'Архивирует и удаляет истекающие сессии';

    public function __construct(
        private readonly BalancerService $balancerService,
        private readonly NodeClientService $nodeClient,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $expiringSessions = $this->balancerService->getExpiringSessions();

        if (empty($expiringSessions)) {
            return;
        }

        $nodeSessionGroups = [];
        foreach ($expiringSessions as $sessionId => $nodeAddress) {
            $nodeSessionGroups[$nodeAddress][] = $sessionId;
        }

        foreach ($nodeSessionGroups as $nodeAddress => $sessionIds) {
            try {
                $this->nodeClient->clean($nodeAddress, archive: true);
            } catch (Throwable $e) {
                Log::error("Не удалось архивировать сессии на ноде $nodeAddress: {$e->getMessage()}");
            }

            foreach ($sessionIds as $sessionId) {
                $this->balancerService->removeSession($sessionId, $nodeAddress);
            }
        }

        $this->info("Очищено сессий: " . count($expiringSessions));
    }
}

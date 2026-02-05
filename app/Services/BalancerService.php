<?php

namespace App\Services;

use App\Repositories\SessionArchiveRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

class BalancerService
{
    private const int SESSION_TTL_SECONDS = 600;
    private const int MAX_RETRIES = 3;
    private const string REDIS_NODES_KEY = 'balancer:nodes';
    private const string REDIS_SESSION_PREFIX = 'balancer:session:';

    public function __construct(
        private readonly NodeClientService $nodeClient,
        private readonly SessionArchiveRepository $archiveRepository,
    ) {}

    public function press(string $sessionId): string
    {
        $nodeAddress = $this->getSessionNode($sessionId);

        if ($nodeAddress) {
            return $this->forwardPress($sessionId, $nodeAddress);
        }

        return $this->createSessionAndPress($sessionId);
    }

    public function addNode(string $address): void
    {
        Redis::hset(self::REDIS_NODES_KEY, $address, 0);
    }

    public function removeNode(string $address): void
    {
        try {
            $this->nodeClient->clean($address, archive: true);
        } catch (Throwable $e) {
            Log::warning("Нода $address недоступна при удалении: {$e->getMessage()}");
        }

        $this->removeNodeSessions($address);
        Redis::hdel(self::REDIS_NODES_KEY, $address);
    }

    private function forwardPress(string $sessionId, string $nodeAddress): string
    {
        try {
            $response = $this->nodeClient->press($nodeAddress, $sessionId);
            $this->refreshSessionTTL($sessionId);
            return $this->adjustResponse($sessionId, $response);
        } catch (Throwable $e) {
            Log::error("Нода $nodeAddress недоступна: {$e->getMessage()}");
            $this->handleNodeFailure($nodeAddress);
            return $this->createSessionAndPress($sessionId);
        }
    }

    private function createSessionAndPress(string $sessionId, int $retries = 0): string
    {
        if ($retries >= self::MAX_RETRIES) {
            throw new RuntimeException('Все доступные ноды недоступны');
        }

        $nodeAddress = $this->findNodeWithMinSessions();
        $this->assignSession($sessionId, $nodeAddress);
        $this->restoreFromArchive($sessionId);

        try {
            $response = $this->nodeClient->press($nodeAddress, $sessionId);
            return $this->adjustResponse($sessionId, $response);
        } catch (Throwable $e) {
            Log::error("Нода $nodeAddress недоступна при создании сессии: {$e->getMessage()}");
            $this->handleNodeFailure($nodeAddress);
            return $this->createSessionAndPress($sessionId, $retries + 1);
        }
    }

    private function restoreFromArchive(string $sessionId): void
    {
        $archived = $this->archiveRepository->find($sessionId);

        if ($archived && $archived->clicks > 0) {
            Redis::setex(
                self::REDIS_SESSION_PREFIX . $sessionId . ':base',
                self::SESSION_TTL_SECONDS,
                $archived->clicks,
            );
        }
    }

    private function adjustResponse(string $sessionId, string $response): string
    {
        $baseClicks = $this->getBaseClicks($sessionId);

        if ($baseClicks <= 0) {
            return $response;
        }

        if (!preg_match('/\d+/', $response, $matches)) {
            return $response;
        }

        $totalClicks = $baseClicks + (int) $matches[0];

        return preg_replace('/\d+/', (string) $totalClicks, $response, 1);
    }

    private function getSessionNode(string $sessionId): ?string
    {
        return Redis::get(self::REDIS_SESSION_PREFIX . $sessionId) ?: null;
    }

    private function assignSession(string $sessionId, string $nodeAddress): void
    {
        Redis::setex(
            self::REDIS_SESSION_PREFIX . $sessionId,
            self::SESSION_TTL_SECONDS,
            $nodeAddress,
        );
        Redis::hincrby(self::REDIS_NODES_KEY, $nodeAddress, 1);
    }

    private function refreshSessionTTL(string $sessionId): void
    {
        Redis::expire(self::REDIS_SESSION_PREFIX . $sessionId, self::SESSION_TTL_SECONDS);
        Redis::expire(self::REDIS_SESSION_PREFIX . $sessionId . ':base', self::SESSION_TTL_SECONDS);
    }

    private function getBaseClicks(string $sessionId): int
    {
        return (int) Redis::get(self::REDIS_SESSION_PREFIX . $sessionId . ':base');
    }

    private function findNodeWithMinSessions(): string
    {
        $nodes = Redis::hgetall(self::REDIS_NODES_KEY);

        if (empty($nodes)) {
            throw new RuntimeException('Нет доступных нод в кластере');
        }

        asort($nodes);

        return array_key_first($nodes);
    }

    private function handleNodeFailure(string $nodeAddress): void
    {
        $this->removeNodeSessions($nodeAddress);
        Redis::hdel(self::REDIS_NODES_KEY, $nodeAddress);
    }

    private function removeNodeSessions(string $address): void
    {
        $cursor = null;
        $prefix = config('database.redis.options.prefix', '');
        $pattern = $prefix . self::REDIS_SESSION_PREFIX . '*';
        $removed = 0;

        do {
            $result = Redis::scan($cursor, ['match' => $pattern, 'count' => 100]);

            if ($result === false) {
                break;
            }

            [$cursor, $keys] = $result;

            foreach ($keys as $key) {
                $keyWithoutPrefix = str_replace($prefix, '', $key);

                if (str_ends_with($keyWithoutPrefix, ':base')) {
                    continue;
                }

                $value = Redis::get($keyWithoutPrefix);

                if ($value === $address) {
                    Redis::del($keyWithoutPrefix);
                    Redis::del($keyWithoutPrefix . ':base');
                    $removed++;
                }
            }
        } while ($cursor != 0);

        if ($removed > 0 && Redis::hexists(self::REDIS_NODES_KEY, $address)) {
            Redis::hincrby(self::REDIS_NODES_KEY, $address, -$removed);
        }
    }

    public function getExpiringSessions(): array
    {
        $expiring = [];
        $cursor = null;
        $prefix = config('database.redis.options.prefix', '');
        $pattern = $prefix . self::REDIS_SESSION_PREFIX . '*';

        do {
            $result = Redis::scan($cursor, ['match' => $pattern, 'count' => 100]);

            if ($result === false) {
                break;
            }

            [$cursor, $keys] = $result;

            foreach ($keys as $key) {
                $keyWithoutPrefix = str_replace($prefix, '', $key);

                if (str_ends_with($keyWithoutPrefix, ':base')) {
                    continue;
                }

                $ttl = Redis::ttl($keyWithoutPrefix);

                if ($ttl <= 60 && $ttl > 0) {
                    $sessionId = str_replace(self::REDIS_SESSION_PREFIX, '', $keyWithoutPrefix);
                    $nodeAddress = Redis::get($keyWithoutPrefix);
                    $expiring[$sessionId] = $nodeAddress;
                }
            }
        } while ($cursor != 0);

        return $expiring;
    }

    public function removeSession(string $sessionId, string $nodeAddress): void
    {
        Redis::del(self::REDIS_SESSION_PREFIX . $sessionId);
        Redis::del(self::REDIS_SESSION_PREFIX . $sessionId . ':base');
        Redis::hincrby(self::REDIS_NODES_KEY, $nodeAddress, -1);
    }
}

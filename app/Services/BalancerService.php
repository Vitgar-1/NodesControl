<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

class BalancerService
{
    private const int SESSION_TTL_SECONDS = 600;
    private const string REDIS_NODES_KEY = 'balancer:nodes';
    private const string REDIS_SESSION_PREFIX = 'balancer:session:';

    public function __construct(
        private readonly NodeClientService $nodeClient,
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
            return $response;
        } catch (Throwable $e) {
            Log::error("Нода {$nodeAddress} недоступна: {$e->getMessage()}");
            $this->handleNodeFailure($nodeAddress);
            return $this->createSessionAndPress($sessionId);
        }
    }

    private function createSessionAndPress(string $sessionId): string
    {
        $nodeAddress = $this->findNodeWithMinSessions();
        $this->assignSession($sessionId, $nodeAddress);

        try {
            return $this->nodeClient->press($nodeAddress, $sessionId);
        } catch (Throwable $e) {
            Log::error("Нода $nodeAddress недоступна при создании сессии: {$e->getMessage()}");
            $this->handleNodeFailure($nodeAddress);
            return $this->createSessionAndPress($sessionId);
        }
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

        do {
            $result = Redis::scan($cursor, ['match' => $pattern, 'count' => 100]);

            if ($result === false) {
                break;
            }

            [$cursor, $keys] = $result;

            foreach ($keys as $key) {
                $keyWithoutPrefix = str_replace($prefix, '', $key);
                $value = Redis::get($keyWithoutPrefix);

                if ($value === $address) {
                    Redis::del($keyWithoutPrefix);
                }
            }
        } while ($cursor != 0);
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
        Redis::hincrby(self::REDIS_NODES_KEY, $nodeAddress, -1);
    }
}

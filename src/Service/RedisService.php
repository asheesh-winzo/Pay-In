<?php

declare(strict_types=1);

namespace App\Service;

use Redis;

final class RedisService implements RedisServiceInterface
{
    private ?Redis $client = null;

    public function __construct(private readonly string $redisUrl) {}

    public function getClient(): Redis
    {
        if ($this->client === null) {
            $this->client = $this->createClient();
        }

        return $this->client;
    }

    public function get(string $key): ?string
    {
        $value = $this->getClient()->get($key);
        return $value === false ? null : $value;
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): void
    {
        if ($ttlSeconds > 0) {
            $this->getClient()->setex($key, $ttlSeconds, $value);
            return;
        }

        $this->getClient()->set($key, $value);
    }

    public function increment(string $key, int $ttlSeconds = 0): int
    {
        $client = $this->getClient();
        $count = $client->incr($key);

        if ($count === 1 && $ttlSeconds > 0) {
            $client->expire($key, $ttlSeconds);
        }

        return $count;
    }

    private function createClient(): Redis
    {
        $parsed = parse_url($this->redisUrl);
        $host = $parsed['host'] ?? 'redis';
        $port = $parsed['port'] ?? 6379;

        $client = new Redis();
        $client->connect($host, (int) $port, 2.0);

        return $client;
    }
}

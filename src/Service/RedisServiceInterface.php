<?php

declare(strict_types=1);

namespace App\Service;

interface RedisServiceInterface
{
    public function getClient(): \Redis;

    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttlSeconds = 0): void;

    public function increment(string $key, int $ttlSeconds = 0): int;
}

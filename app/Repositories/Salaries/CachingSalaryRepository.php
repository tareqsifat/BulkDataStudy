<?php

namespace App\Repositories\Salaries;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Decorator that adds cross-request caching through a Laravel cache store (database, file, redis, ...).
 */
final class CachingSalaryRepository extends SalaryRepositoryWrapper
{
    public function __construct(
        private readonly SalaryRepository $inner,
        private readonly Cache $cache,
        private readonly string $prefix,
        private readonly int $ttlSeconds = 300,
    ) {}

    protected function handle(string $method, array $args): array
    {
        return $this->cache->remember(
            "{$this->prefix}:{$method}:".implode(':', $args),
            $this->ttlSeconds,
            fn () => $this->inner->{$method}(...$args),
        );
    }
}

<?php

namespace App\Repositories\Salaries;

/**
 * Caching proxy scoped to the object's lifetime (one request): identical calls hit a PHP array, never the DB.
 */
final class MemoizingSalaryRepositoryProxy extends SalaryRepositoryWrapper
{
    /** @var array<string, array> */
    private array $memo = [];

    public function __construct(private readonly SalaryRepository $subject) {}

    protected function handle(string $method, array $args): array
    {
        return $this->memo[$method.':'.implode(':', $args)] ??= $this->subject->{$method}(...$args);
    }
}

<?php

namespace App\Repositories\Salaries;

/**
 * Base for proxies and decorators: every SalaryRepository method is funnelled through handle(), so a wrapper
 * only overrides that one hook instead of re-implementing the whole interface.
 */
abstract class SalaryRepositoryWrapper implements SalaryRepository
{
    /**
     * @param  array<int, mixed>  $args
     */
    abstract protected function handle(string $method, array $args): array;

    public function find(int $id): array
    {
        return $this->handle(__FUNCTION__, func_get_args());
    }

    public function forUser(int $userId): array
    {
        return $this->handle(__FUNCTION__, func_get_args());
    }

    public function offsetPage(int $departmentId, string $payPeriod, int $page, int $perPage): array
    {
        return $this->handle(__FUNCTION__, func_get_args());
    }

    public function keysetPage(int $departmentId, string $payPeriod, int $afterId, int $perPage): array
    {
        return $this->handle(__FUNCTION__, func_get_args());
    }

    public function summary(string $payPeriod, int $fromUserId, int $toUserId): array
    {
        return $this->handle(__FUNCTION__, func_get_args());
    }
}

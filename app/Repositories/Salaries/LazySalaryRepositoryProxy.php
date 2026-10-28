<?php

namespace App\Repositories\Salaries;

use Closure;

/**
 * Virtual proxy: stands in for the real repository and only builds it on first use.
 */
final class LazySalaryRepositoryProxy extends SalaryRepositoryWrapper
{
    private ?SalaryRepository $subject = null;

    /**
     * @param  Closure(): SalaryRepository  $resolver
     */
    public function __construct(private readonly Closure $resolver) {}

    protected function handle(string $method, array $args): array
    {
        $this->subject ??= ($this->resolver)();

        return $this->subject->{$method}(...$args);
    }
}

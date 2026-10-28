<?php

namespace App\Repositories\Salaries;

use Psr\Log\LoggerInterface;

/**
 * Decorator that writes one log line per call (method, arguments, duration, row count).
 */
final class LoggingSalaryRepository extends SalaryRepositoryWrapper
{
    public function __construct(
        private readonly SalaryRepository $inner,
        private readonly LoggerInterface $logger,
    ) {}

    protected function handle(string $method, array $args): array
    {
        $start = hrtime(true);
        $rows = $this->inner->{$method}(...$args);

        $this->logger->debug("salaries.{$method}", [
            'args' => $args,
            'ms' => round((hrtime(true) - $start) / 1e6, 3),
            'rows' => count($rows),
        ]);

        return $rows;
    }
}

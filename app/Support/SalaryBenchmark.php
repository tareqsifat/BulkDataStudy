<?php

namespace App\Support;

use App\Repositories\Salaries\SalaryRepository;
use App\Repositories\Salaries\SalaryRepositoryFactory;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Runs the same SalaryRepository call through every data-access pattern and records, per run:
 *   wall_ms  total time inside the repository call (SQL + PDO fetch + hydration + mapping)
 *   db_ms    time Laravel's query log attributes to the database (execute + fetch)
 *   php_ms   wall_ms - db_ms, i.e. the framework/ORM overhead
 */
final class SalaryBenchmark
{
    public const PATTERNS = [
        'raw' => 'Raw SQL (DB::select)',
        'builder' => 'Query Builder (DB::table)',
        'eloquent_lazy' => 'Eloquent ORM, lazy relation (N+1)',
        'eloquent_eager' => 'Eloquent ORM, eager loaded',
    ];

    public const SCENARIOS = [
        'find' => 'Find one salary by id',
        'user' => "One user's payroll history",
        'offset' => 'Department page, LIMIT/OFFSET',
        'keyset' => 'Department page, keyset (id > after_id)',
        'summary' => 'Department totals over a user range',
    ];

    /**
     * Structural/creational patterns compared on /salaries/patterns: label + layers (innermost first)
     * that SalaryRepositoryFactory wraps around the chosen base driver.
     */
    public const DESIGN_PATTERNS = [
        'factory' => ['Factory: plain driver (baseline)', []],
        'proxy_lazy' => ['Proxy: lazy / virtual proxy', ['lazy']],
        'proxy_memo' => ['Proxy: memoizing (per request)', ['memo']],
        'decorator_log' => ['Decorator: logging', ['log']],
        'decorator_cache' => ['Decorator: cache store', ['cache']],
        'decorator_stack' => ['Decorator stack: log(cache(driver))', ['cache', 'log']],
    ];

    /**
     * @param  array<string, mixed>  $p  validated request parameters
     */
    public static function call(SalaryRepository $repo, string $scenario, array $p): array
    {
        return match ($scenario) {
            'find' => $repo->find($p['id']),
            'user' => $repo->forUser($p['user_id']),
            'offset' => $repo->offsetPage($p['department_id'], $p['pay_period'], $p['page'], $p['per_page']),
            'keyset' => $repo->keysetPage($p['department_id'], $p['pay_period'], $p['after_id'], $p['per_page']),
            'summary' => $repo->summary($p['pay_period'], $p['from_user'], $p['from_user'] + $p['users'] - 1),
            default => throw new InvalidArgumentException("Unknown scenario [{$scenario}]."),
        };
    }

    /**
     * One warm-up call per pattern (reported as first_ms but excluded from the stats), then $runs timed
     * rounds. Patterns are interleaved within each round so buffer-pool warmth and background noise are
     * shared evenly rather than favouring whoever runs last. The warm-up is also what primes cache/memo
     * layers, so first_ms is their miss cost and the median is their hit cost.
     *
     * @param  array<string, Closure(): SalaryRepository>  $factories  keyed by pattern
     * @param  array<string, string>  $labels  keyed by pattern
     * @return array<string, array<string, mixed>> keyed by pattern, sorted fastest median first
     */
    public static function compare(array $factories, array $labels, string $scenario, array $params, int $runs): array
    {
        $repos = $first = [];
        foreach ($factories as $pattern => $factory) {
            $repos[$pattern] = $factory();
            $first[$pattern] = self::measure(fn () => self::call($repos[$pattern], $scenario, $params))['wall_ms'];
        }

        $samples = array_fill_keys(array_keys($repos), []);
        for ($r = 0; $r < $runs; $r++) {
            foreach ($repos as $pattern => $repo) {
                $samples[$pattern][] = self::measure(fn () => self::call($repo, $scenario, $params));
            }
        }

        $results = [];
        foreach ($samples as $pattern => $runSamples) {
            $results[$pattern] = ['first_ms' => $first[$pattern]] + self::summarise($pattern, $labels[$pattern], $runSamples);
        }

        uasort($results, fn (array $a, array $b) => $a['median_ms'] <=> $b['median_ms']);

        return $results;
    }

    /**
     * @return array{rows: array, wall_ms: float, db_ms: float, php_ms: float, memory_kb: float, queries: list<array{query: string, bindings: array, time: float}>}
     */
    public static function measure(callable $fn): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $memory = memory_get_usage();
        $start = hrtime(true);
        $rows = $fn();
        $wall = (hrtime(true) - $start) / 1e6;
        $memoryKb = (memory_get_usage() - $memory) / 1024;

        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();
        $connection->flushQueryLog();

        $db = array_sum(array_column($queries, 'time'));

        return [
            'rows' => $rows,
            'wall_ms' => round($wall, 3),
            'db_ms' => round($db, 3),
            'php_ms' => round(max($wall - $db, 0), 3),
            'memory_kb' => round($memoryKb, 1),
            'queries' => $queries,
        ];
    }

    private static function summarise(string $pattern, string $label, array $samples): array
    {
        $walls = array_column($samples, 'wall_ms');
        sort($walls);
        $count = count($walls);
        $median = $count % 2 ? $walls[intdiv($count, 2)] : ($walls[$count / 2 - 1] + $walls[$count / 2]) / 2;
        $last = end($samples);

        return [
            'pattern' => $pattern,
            'label' => $label,
            'runs' => $count,
            'min_ms' => $walls[0],
            'median_ms' => round($median, 3),
            'avg_ms' => round(array_sum($walls) / $count, 3),
            'max_ms' => $walls[$count - 1],
            'db_ms' => round(array_sum(array_column($samples, 'db_ms')) / $count, 3),
            'php_ms' => round(array_sum(array_column($samples, 'php_ms')) / $count, 3),
            'memory_kb' => $last['memory_kb'],
            'query_count' => count($last['queries']),
            'row_count' => count($last['rows']),
            'queries' => $last['queries'],
            'rows' => $last['rows'],
        ];
    }
}

<?php

namespace App\Repositories\Salaries;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Factory: builds a data-access driver and wraps it in proxies/decorators, so callers depend only on
 * SalaryRepository and never on which concrete classes were stacked together.
 *
 *   make('builder', ['cache', 'log'])  =>  Logging( Caching( QueryBuilder ) )
 *
 * Layers are listed innermost first.
 */
final class SalaryRepositoryFactory
{
    public const DRIVERS = ['raw', 'builder', 'eloquent_lazy', 'eloquent_eager'];

    public const LAYERS = ['lazy', 'memo', 'cache', 'log'];

    /**
     * @param  list<string>  $layers
     * @param  string|null  $cacheNamespace  extra cache-key segment, e.g. to isolate one benchmark run
     */
    public static function make(string $driver, array $layers = [], ?string $cacheStore = null, ?string $cacheNamespace = null): SalaryRepository
    {
        $build = fn (): SalaryRepository => self::driver($driver);
        $prefix = rtrim("salaries:{$driver}:{$cacheNamespace}", ':');

        foreach ($layers as $layer) {
            $build = self::wrap($layer, $build, $prefix, $cacheStore);
        }

        return $build();
    }

    public static function driver(string $driver): SalaryRepository
    {
        return match ($driver) {
            'raw' => new RawSqlSalaryRepository,
            'builder' => new QueryBuilderSalaryRepository,
            'eloquent_lazy' => new EloquentSalaryRepository(eager: false),
            'eloquent_eager' => new EloquentSalaryRepository(eager: true),
            default => throw new InvalidArgumentException("Unknown driver [{$driver}]."),
        };
    }

    /**
     * @param  Closure(): SalaryRepository  $inner
     * @return Closure(): SalaryRepository
     */
    private static function wrap(string $layer, Closure $inner, string $prefix, ?string $cacheStore): Closure
    {
        return match ($layer) {
            'lazy' => fn () => new LazySalaryRepositoryProxy($inner),
            'memo' => fn () => new MemoizingSalaryRepositoryProxy($inner()),
            'cache' => fn () => new CachingSalaryRepository($inner(), Cache::store($cacheStore), $prefix),
            'log' => fn () => new LoggingSalaryRepository($inner(), Log::channel()),
            default => throw new InvalidArgumentException("Unknown layer [{$layer}]."),
        };
    }
}

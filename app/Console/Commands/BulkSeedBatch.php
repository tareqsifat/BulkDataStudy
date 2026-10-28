<?php

namespace App\Console\Commands;

use App\Support\BulkPayroll;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Hidden;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use IzAhmad\TurboSeeder\Facades\TurboSeeder;

/**
 * Worker for BulkPayrollSeeder: loads one batch through TurboSeeder's CSV strategy and prints the result as JSON.
 */
#[Signature('bulk:seed-batch {table : users or salaries} {offset : 0-based ordinal of the first row} {count} {--per-user=10 : salary rows per user}')]
#[Description('Load one batch of the bulk payroll seed (used by BulkPayrollSeeder workers)')]
#[Hidden]
class BulkSeedBatch extends Command
{
    public function handle(): int
    {
        $table = $this->argument('table');
        $offset = (int) $this->argument('offset');
        $count = (int) $this->argument('count');

        config(['turbo-seeder.csv_strategy.temp_path' => env('BULK_CSV_DIR', storage_path('app/turbo-seeder'))]);

        [$columns, $generator] = match ($table) {
            'users' => [BulkPayroll::USER_COLUMNS, BulkPayroll::userGenerator($offset)],
            'salaries' => [BulkPayroll::SALARY_COLUMNS, BulkPayroll::salaryGenerator(
                $offset,
                (int) $this->option('per-user'),
                DB::table('departments')->orderBy('id')->pluck('id')->all(),
                DB::table('departments')->orderBy('id')->pluck('code')->all(),
            )],
        };

        $result = TurboSeeder::forTable($table)
            ->columns($columns)
            ->generate($generator)
            ->count($count)
            ->useCsvStrategy()
            ->disableForeignKeyChecks()
            ->withoutProgressTracking()
            ->run();

        $this->line(json_encode([
            'success' => $result->success && $result->recordsInserted === $count,
            'rows' => $result->recordsInserted,
            'seconds' => $result->durationSeconds,
            'peak_mem_mb' => round($result->getPeakMemoryInMB(), 1),
            'error' => $result->errorMessage,
        ]));

        return $result->success ? self::SUCCESS : self::FAILURE;
    }
}

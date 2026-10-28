<?php

namespace Database\Seeders;

use App\Support\BulkPayroll;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Bulk-loads users and a monthly payroll history for each of them through laravel-turbo-seeder, timing every phase.
 *
 * Load plan: secondary indexes and foreign keys are captured from the migrated schema and dropped, batches are
 * loaded by parallel `bulk:seed-batch` workers (TurboSeeder CSV strategy = LOAD DATA LOCAL INFILE), then the
 * indexes are rebuilt in one sorted pass, references are verified and the foreign keys re-attached.
 *
 * Tunable through the environment:
 *   BULK_USERS              number of users                    (default 10,000,000)
 *   BULK_SALARIES_PER_USER  monthly salary rows per user       (default 10)
 *   BULK_BATCH_SIZE         rows per worker batch / CSV file   (default 2,000,000)
 *   BULK_WORKERS            parallel worker processes          (default 3)
 *   BULK_CSV_DIR            where the temporary CSVs go        (default storage/app/turbo-seeder)
 *   BULK_SORT_DIR           innodb_tmpdir for index builds     (default MySQL's tmpdir)
 */
class BulkPayrollSeeder extends Seeder
{
    private const TABLES = ['users', 'salaries'];

    /** @var array<int, array<string, mixed>> */
    private array $phases = [];

    /** @var array<int, array<string, mixed>> */
    private array $batches = [];

    public function run(): void
    {
        $users = (int) env('BULK_USERS', 10_000_000);
        $perUser = (int) env('BULK_SALARIES_PER_USER', 10);
        $batchSize = (int) env('BULK_BATCH_SIZE', 2_000_000);
        $workers = (int) env('BULK_WORKERS', 3);
        $salaries = $users * $perUser;

        $this->command->info(sprintf(
            'Seeding %s users + %s salaries (%d per user), batches of %s, %d workers',
            number_format($users), number_format($salaries), $perUser, number_format($batchSize), $workers,
        ));

        $startedAt = now()->toDateTimeString();
        $started = hrtime(true);

        $this->guardSchemaIsComplete();
        $this->resetTables();
        $this->seedDepartments();

        $deferred = $this->phase('drop secondary indexes + FKs', 0, fn () => $this->dropSecondaryIndexes());

        try {
            $this->phase('load users', $users, fn () => $this->runWorkers('users', $users, $batchSize, $workers, $perUser));
            $this->phase('load salaries', $salaries, fn () => $this->runWorkers('salaries', $salaries, $batchSize, $workers, $perUser));
        } catch (Throwable $e) {
            $this->command->error('Load failed - emptying tables and restoring indexes and foreign keys.');
            $this->resetTables();
            foreach ($deferred as $table => $schema) {
                $this->restoreIndexes($table, $schema['indexes']);
                $this->restoreForeignKeys($table, $schema['foreign_keys']);
            }

            throw $e;
        }

        foreach (self::TABLES as $table) {
            $rows = $table === 'users' ? $users : $salaries;
            $this->phase("build {$table} indexes", $rows, fn () => $this->restoreIndexes($table, $deferred[$table]['indexes']));
        }

        $checks = $this->phase('verify counts + references', 0, fn () => $this->verifyIntegrity($users, $salaries));

        if (in_array(false, array_filter($checks, 'is_bool'), true)) {
            throw new RuntimeException('Integrity check failed, foreign keys not re-attached: '.json_encode($checks));
        }

        foreach (self::TABLES as $table) {
            $this->phase("attach {$table} foreign keys", 0, fn () => $this->restoreForeignKeys($table, $deferred[$table]['foreign_keys']));
        }

        $this->report($startedAt, (hrtime(true) - $started) / 1e9, $checks, $users, $perUser, $batchSize, $workers);
    }

    /**
     * A previous run killed mid-load leaves the tables without their secondary indexes; capturing that state
     * would lose them for good, so refuse and ask for a fresh migration instead.
     */
    private function guardSchemaIsComplete(): void
    {
        foreach (self::TABLES as $table) {
            if (count(Schema::getIndexes($table)) < 2) {
                throw new RuntimeException("{$table} is missing its secondary indexes (interrupted run?). Run `php artisan migrate:fresh` first.");
            }
        }
    }

    private function resetTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('salaries')->truncate();
        DB::table('users')->truncate();
        DB::table('departments')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function seedDepartments(): void
    {
        $now = now()->toDateTimeString();

        DB::table('departments')->insert(array_map(
            fn (array $dept) => ['code' => $dept[0], 'name' => $dept[1], 'created_at' => $now, 'updated_at' => $now],
            BulkPayroll::DEPARTMENTS,
        ));
    }

    /**
     * @return array<string, array{indexes: array<int, array<string, mixed>>, foreign_keys: array<int, array<string, mixed>>}>
     */
    private function dropSecondaryIndexes(): array
    {
        $deferred = [];

        foreach (self::TABLES as $table) {
            $foreignKeys = Schema::getForeignKeys($table);
            $indexes = array_values(array_filter(Schema::getIndexes($table), fn (array $index) => ! $index['primary']));

            if ($foreignKeys) {
                DB::statement("ALTER TABLE `{$table}` ".implode(', ', array_map(
                    fn (array $fk) => "DROP FOREIGN KEY `{$fk['name']}`", $foreignKeys)));
            }

            if ($indexes) {
                DB::statement("ALTER TABLE `{$table}` ".implode(', ', array_map(
                    fn (array $index) => "DROP INDEX `{$index['name']}`", $indexes)));
            }

            $deferred[$table] = ['indexes' => $indexes, 'foreign_keys' => $foreignKeys];
        }

        return $deferred;
    }

    /**
     * @param  array<int, array<string, mixed>>  $indexes
     */
    private function restoreIndexes(string $table, array $indexes): void
    {
        if (! $indexes) {
            return;
        }

        DB::statement('SET SESSION innodb_ddl_buffer_size = '.(512 * 1024 * 1024));

        // Index builds spill sort files the size of the index; keep them off the data drive when asked to.
        if ($sortDir = env('BULK_SORT_DIR')) {
            File::ensureDirectoryExists($sortDir);
            DB::statement('SET SESSION innodb_tmpdir = ?', [str_replace('\\', '/', $sortDir)]);
        }

        DB::statement("ALTER TABLE `{$table}` ".implode(', ', array_map(fn (array $index) => sprintf(
            'ADD %s `%s` (%s)', $index['unique'] ? 'UNIQUE INDEX' : 'INDEX', $index['name'], $this->columnList($index['columns']),
        ), $indexes)).', ALGORITHM=INPLACE, LOCK=NONE');
    }

    /**
     * References were verified by verifyIntegrity(), so the constraints are attached with checks off;
     * with checks on MySQL would rebuild the whole table with ALGORITHM=COPY.
     *
     * @param  array<int, array<string, mixed>>  $foreignKeys
     */
    private function restoreForeignKeys(string $table, array $foreignKeys): void
    {
        if (! $foreignKeys) {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::statement("ALTER TABLE `{$table}` ".implode(', ', array_map(fn (array $fk) => sprintf(
            'ADD CONSTRAINT `%s` FOREIGN KEY (%s) REFERENCES `%s` (%s) ON DELETE %s ON UPDATE %s',
            $fk['name'], $this->columnList($fk['columns']), $fk['foreign_table'], $this->columnList($fk['foreign_columns']),
            strtoupper($fk['on_delete']), strtoupper($fk['on_update']),
        ), $foreignKeys)));
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function columnList(array $columns): string
    {
        return implode(', ', array_map(fn (string $column) => "`{$column}`", $columns));
    }

    /**
     * Run `bulk:seed-batch` workers over the table's batches, at most $workers at a time.
     */
    private function runWorkers(string $table, int $total, int $batchSize, int $workers, int $perUser): void
    {
        $php = [PHP_BINARY, '-d', 'memory_limit=1G'];
        if (! extension_loaded('Zend OPcache')) {
            $php = [...$php, '-d', 'zend_extension=opcache'];
        }
        $php = [...$php, '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=tracing', '-d', 'opcache.jit_buffer_size=128M'];

        $queue = [];
        for ($offset = 0; $offset < $total; $offset += $batchSize) {
            $queue[] = [$offset, min($batchSize, $total - $offset)];
        }

        $batchCount = count($queue);
        $finished = 0;
        $rowsDone = 0;
        $started = hrtime(true);
        $running = [];

        while ($queue || $running) {
            while ($queue && count($running) < $workers) {
                [$offset, $count] = array_shift($queue);

                $running[] = [
                    'offset' => $offset,
                    'count' => $count,
                    'started' => hrtime(true),
                    'process' => Process::path(base_path())->forever()->start([
                        ...$php, base_path('artisan'), 'bulk:seed-batch', $table, (string) $offset, (string) $count, "--per-user={$perUser}",
                    ]),
                ];
            }

            foreach ($running as $key => $job) {
                if ($job['process']->running()) {
                    continue;
                }

                $result = $job['process']->wait();
                unset($running[$key]);

                $lines = preg_split('/\R/', trim($result->output()));
                $worker = json_decode((string) end($lines), true);

                if ($result->failed() || ! ($worker['success'] ?? false)) {
                    foreach ($running as $other) {
                        $other['process']->wait();
                    }

                    throw new RuntimeException(sprintf('%s batch at offset %d failed: %s', $table, $job['offset'],
                        $worker['error'] ?? trim($result->errorOutput()."\n".$result->output())));
                }

                $seconds = (hrtime(true) - $job['started']) / 1e9;
                $finished++;
                $rowsDone += $job['count'];
                $elapsed = (hrtime(true) - $started) / 1e9;
                $rate = $rowsDone / $elapsed;

                $this->batches[] = [
                    'table' => $table,
                    'first_id' => $job['offset'] + 1,
                    'rows' => $job['count'],
                    'seconds' => round($seconds, 2),
                    'rows_per_sec' => (int) round($job['count'] / $seconds),
                    'peak_mem_mb' => $worker['peak_mem_mb'],
                ];

                $this->command->line(sprintf(
                    '  %s %3d/%d  %s rows in %6.1fs (%s/s per worker) | %s/%s done, %s rows/s overall, ETA %s',
                    $table, $finished, $batchCount, number_format($job['count']), $seconds,
                    number_format($job['count'] / $seconds), number_format($rowsDone), number_format($total),
                    number_format($rate), $this->duration(($total - $rowsDone) / $rate),
                ));
            }

            usleep(250_000);
        }
    }

    /**
     * Ids are generated gap-free from 1, so a reference is valid exactly when it falls inside the parent's id range.
     *
     * @return array<string, mixed>
     */
    private function verifyIntegrity(int $users, int $salaries): array
    {
        $userRange = DB::selectOne('SELECT COUNT(*) AS n, MIN(id) AS lo, MAX(id) AS hi FROM users');
        $salaryRange = DB::selectOne('SELECT COUNT(*) AS n, MIN(id) AS lo, MAX(id) AS hi FROM salaries');
        $refs = DB::selectOne('SELECT
            (SELECT MIN(user_id) FROM salaries) AS u_lo, (SELECT MAX(user_id) FROM salaries) AS u_hi,
            (SELECT MIN(approved_by) FROM salaries) AS a_lo, (SELECT MAX(approved_by) FROM salaries) AS a_hi,
            (SELECT MIN(department_id) FROM salaries) AS d_lo, (SELECT MAX(department_id) FROM salaries) AS d_hi');
        $depts = DB::selectOne('SELECT COUNT(*) AS n, MIN(id) AS lo, MAX(id) AS hi FROM departments');

        return [
            'users_count_ok' => (int) $userRange->n === $users,
            'users_gap_free' => (int) $userRange->lo === 1 && (int) $userRange->hi === $users,
            'salaries_count_ok' => (int) $salaryRange->n === $salaries,
            'salaries_gap_free' => (int) $salaryRange->lo === 1 && (int) $salaryRange->hi === $salaries,
            'fk_user_id_ok' => $refs->u_lo >= 1 && $refs->u_hi <= $users,
            'fk_approved_by_ok' => $refs->a_lo >= 1 && $refs->a_hi <= $users,
            'fk_department_id_ok' => $refs->d_lo >= $depts->lo && $refs->d_hi <= $depts->hi
                && $depts->hi - $depts->lo + 1 === (int) $depts->n,
            'users_rows' => (int) $userRange->n,
            'salaries_rows' => (int) $salaryRange->n,
        ];
    }

    /**
     * @param  array<string, mixed>  $checks
     */
    private function report(string $startedAt, float $totalSeconds, array $checks, int $users, int $perUser, int $batchSize, int $workers): void
    {
        $datadir = rtrim(DB::selectOne('SELECT @@datadir AS d')->d, '/\\');
        $database = DB::connection()->getDatabaseName();

        $tables = [];
        foreach (self::TABLES as $table) {
            $rows = $table === 'users' ? $users : $users * $perUser;
            $load = collect($this->phases)->firstWhere('phase', "load {$table}")['seconds'];
            $index = collect($this->phases)->firstWhere('phase', "build {$table} indexes")['seconds'];
            $file = $datadir.DIRECTORY_SEPARATOR.$database.DIRECTORY_SEPARATOR.$table.'.ibd';
            $bytes = is_file($file) ? filesize($file) : 0;

            $tables[$table] = [
                'rows' => $rows,
                'load_seconds' => $load,
                'index_seconds' => $index,
                'rows_per_sec' => (int) round($rows / ($load + $index)),
                'us_per_row' => round(($load + $index) / $rows * 1e6, 2),
                'disk_gb' => round($bytes / 1024 ** 3, 2),
                'bytes_per_row' => (int) round($bytes / $rows),
            ];
        }

        $totalRows = $users + $users * $perUser;
        $mysql = DB::selectOne('SELECT @@version AS version, @@innodb_buffer_pool_size AS bp,
            @@innodb_flush_log_at_trx_commit AS flush, @@innodb_doublewrite AS dblwr, @@log_bin AS binlog');

        // Straight-line projection to the originally requested 1B users / 10B salaries (optimistic: index
        // builds grow faster than linearly once they spill to disk).
        $scale = 1_000_000_000 / $users;
        $projectedBytes = $tables['users']['bytes_per_row'] * 1e9 + $tables['salaries']['bytes_per_row'] * 1e10;

        $summary = [
            'started_at' => $startedAt,
            'finished_at' => now()->toDateTimeString(),
            'config' => [
                'users' => $users,
                'salaries_per_user' => $perUser,
                'batch_size' => $batchSize,
                'workers' => $workers,
                'strategy' => 'iz-ahmad/laravel-turbo-seeder CSV strategy (LOAD DATA LOCAL INFILE), PHP JIT workers',
                'mysql' => $mysql->version,
                'buffer_pool_gb' => round($mysql->bp / 1024 ** 3, 1),
                'flush_log_at_trx_commit' => (int) $mysql->flush,
                'doublewrite' => (bool) $mysql->dblwr,
                'binlog' => (bool) $mysql->binlog,
                'php' => PHP_VERSION,
            ],
            'total' => [
                'rows' => $totalRows,
                'seconds' => round($totalSeconds, 2),
                'human' => $this->duration($totalSeconds),
                'rows_per_sec' => (int) round($totalRows / $totalSeconds),
                'us_per_row' => round($totalSeconds / $totalRows * 1e6, 2),
            ],
            'tables' => $tables,
            'phases' => $this->phases,
            'integrity' => $checks,
            'projection_1b_users_10b_salaries' => [
                'hours' => round($totalSeconds * $scale / 3600, 1),
                'disk_tb' => round($projectedBytes / 1024 ** 4, 2),
            ],
            'batches' => $this->batches,
        ];

        $this->command->newLine();
        $this->command->table(['phase', 'rows', 'time', 'rows/sec'], array_map(fn (array $p) => [
            $p['phase'], $p['rows'] ? number_format($p['rows']) : '', $this->duration($p['seconds']),
            $p['rows_per_sec'] ? number_format($p['rows_per_sec']) : '',
        ], $this->phases));

        $this->command->table(['table', 'rows', 'load+index time', 'rows/sec', 'µs/row', 'disk GB', 'bytes/row'],
            array_map(fn (string $name, array $t) => [
                $name, number_format($t['rows']), $this->duration($t['load_seconds'] + $t['index_seconds']),
                number_format($t['rows_per_sec']), $t['us_per_row'], $t['disk_gb'], $t['bytes_per_row'],
            ], array_keys($tables), $tables));

        $this->command->info(sprintf('TOTAL %s rows in %s = %s rows/sec, %.2f µs/row',
            number_format($totalRows), $summary['total']['human'],
            number_format($summary['total']['rows_per_sec']), $summary['total']['us_per_row']));

        foreach ($checks as $name => $value) {
            if (is_bool($value)) {
                $this->command->line(sprintf('  %s %s', $value ? '<info>PASS</info>' : '<error>FAIL</error>', $name));
            }
        }

        $this->command->line(sprintf('Linear projection for 1B users + 10B salaries: ~%s hours, ~%s TB on disk',
            $summary['projection_1b_users_10b_salaries']['hours'], $summary['projection_1b_users_10b_salaries']['disk_tb']));

        $path = storage_path('logs/bulk-seed-report-'.now()->format('Ymd_His').'.json');
        File::put($path, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->command->info("Report written to {$path}");
    }

    /**
     * @return mixed
     */
    private function phase(string $name, int $rows, \Closure $work)
    {
        $this->command->line("<comment>▶ {$name}</comment>");
        $started = hrtime(true);

        $result = $work();

        $seconds = (hrtime(true) - $started) / 1e9;
        $this->phases[] = [
            'phase' => $name,
            'rows' => $rows,
            'seconds' => round($seconds, 2),
            'rows_per_sec' => $rows ? (int) round($rows / $seconds) : null,
        ];
        $this->command->info(sprintf('  %s: %s', $name, $this->duration($seconds)));

        return $result;
    }

    private function duration(float $seconds): string
    {
        $seconds = (int) round($seconds);

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}

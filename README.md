# BulkDataStudy: Laravel with 100M payroll rows

**Repository:** https://github.com/tareqsifat/BulkDataStudy

Seed **10 million users and 100 million salary rows (~32 GB)** into MySQL, then compare how different ways of
reading that data perform. Everything runs from one Blade page that shows the timings, the exact SQL that ran, and the returned rows.

It compares two things:

1. **Data-access styles.** Raw SQL vs Query Builder vs Eloquent ORM (with lazy loading / N+1, and with eager loading).
2. **Design patterns.** Factory, Proxy (lazy, memoizing) and Decorator (logging, cache), wrapped around any of those styles.

Every request reports **total time, DB time, PHP/ORM time, query count, memory** and the SQL that was issued.

> Small tables hide performance problems. At 100M rows you can see which choices actually cost time.

---

## What I found (100M rows, one Windows machine)

| Experiment | Result |
|---|---|
| 50-row page: Raw SQL vs Eloquent eager vs Eloquent lazy | **2.9 ms** vs 13 ms vs **104 ms** (101 queries, the N+1 problem) |
| `LIMIT 50 OFFSET 9950` (page 200) | **2.7 s**. Page 10,000 didn't finish in 5 min |
| Keyset `WHERE id > ? LIMIT 50` at ~50M rows deep | **55 ms** |
| Adding `JOIN users` to the page query | MySQL sorted **~840k rows** to return 50. Fixed with a deferred join |
| Department totals: join then group, vs group then join | **10+ min** vs **18 ms** |
| Cache decorator hit vs plain query | ~1 ms vs ~3–18 ms |
| Factory / lazy proxy overhead | Below measurement noise |

The big wins come from **how the SQL is shaped** (pagination, join order, N+1), not from which style or pattern you use.
Your numbers will differ: these are from one machine with data already in memory.

---

## Requirements

| | Version / note |
|---|---|
| PHP | **8.3+** with `pdo_mysql`. OPcache is recommended (the seeder enables JIT in its worker processes) |
| Composer | 2.x |
| MySQL | 8.x (tested on 8.4.3) with **`local_infile=ON`** |
| Disk | ~35 GB free for the full dataset. The temporary CSVs need extra space while seeding |
| RAM | 8 GB+. MySQL `innodb_buffer_pool_size` of 2–4 GB helps a lot |

On Windows, [Laragon](https://laragon.org) is the easiest setup. Make sure the PHP it uses is 8.3, not an older version that's also installed.

---

## 1. Install

```bash
git clone https://github.com/tareqsifat/BulkDataStudy.git
cd BulkDataStudy
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=bulk
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=database   # used by the cache decorator; file or redis also work
```

Create the database and run the migrations:

```bash
mysql -uroot -e "CREATE DATABASE bulk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php artisan migrate
```

---

## 2. Configure MySQL for bulk loading

Seeding uses `LOAD DATA LOCAL INFILE`, so the server must allow it. Add to `my.ini` / `my.cnf` under `[mysqld]`, then restart MySQL:

```ini
local_infile = 1
innodb_buffer_pool_size = 3G          # ~25-50% of your RAM
innodb_flush_log_at_trx_commit = 2    # faster loading; fine for a playground, not for production
skip-log-bin                          # no binary log needed locally
```

Check it:

```bash
mysql -uroot -e "SELECT @@local_infile, @@innodb_buffer_pool_size/1024/1024/1024 AS buffer_pool_gb"
```

The client side (`PDO::MYSQL_ATTR_LOCAL_INFILE`) is already enabled in `config/database.php`.

---

## 3. Seed the data

The seeder is controlled by environment variables. **Start small** to check that everything works, then go big.

| Variable | Default | Meaning |
|---|---|---|
| `BULK_USERS` | `10000000` | Number of users |
| `BULK_SALARIES_PER_USER` | `10` | Monthly salary rows per user (Jan 2025 onward). Keep it **≥ 6**: the benchmark's default `pay_period` is 2025-06-01 |
| `BULK_BATCH_SIZE` | `2000000` | Rows per worker batch (one temporary CSV each) |
| `BULK_WORKERS` | `3` | Parallel loader processes. About your CPU core count minus one |
| `BULK_CSV_DIR` | `storage/app/turbo-seeder` | Where the temporary CSVs go. Point it at a disk with free space |
| `BULK_SORT_DIR` | MySQL tmpdir | `innodb_tmpdir` used while rebuilding indexes |

Put them in `.env`, or set them for the current shell.

### Quick start: 1.1M rows, a few minutes

```bash
# bash
BULK_USERS=100000 php artisan db:seed --class=BulkPayrollSeeder

# PowerShell
$env:BULK_USERS=100000; php artisan db:seed --class=BulkPayrollSeeder
```

### Medium: 11M rows, ~20–30 min, ~3.5 GB

```bash
BULK_USERS=1000000 php artisan db:seed --class=BulkPayrollSeeder
```

N+1 and deep-OFFSET problems already show clearly at this size.

### Full: 110M rows, ~3.5 hours, ~32 GB

```bash
php artisan db:seed --class=BulkPayrollSeeder
```

Reference run (MySQL 8.4.3, 3 GB buffer pool, 3 workers):

| Phase | Time |
|---|---|
| Load 10M users | 6 min |
| Load 100M salaries | 2 h 22 min |
| Rebuild indexes (users + salaries) | 1 h 3 min |
| Verify counts and references | 1 min |
| **Total** | **3 h 32 min** (~8,650 rows/s) |

### What the seeder does

1. Truncates `users` and `salaries` and inserts 12 departments.
2. **Drops the secondary indexes and foreign keys.** Loading into an unindexed table is much faster.
3. Starts `BULK_WORKERS` parallel `php artisan bulk:seed-batch` processes. Each one generates a batch as CSV and loads it with
   `LOAD DATA LOCAL INFILE`, via [iz-ahmad/laravel-turbo-seeder](https://packagist.org/packages/iz-ahmad/laravel-turbo-seeder).
4. Rebuilds all indexes in a single sorted pass per table.
5. Verifies row counts and that every reference points to an existing row, then re-attaches the foreign keys.
6. Writes a timing report to `storage/logs/bulk-seed-report-<timestamp>.json`.

Data is **deterministic**: user `n` has id `n`, and salary ids are contiguous. You can re-seed and get the same rows.

> **Interrupted run?** The seeder refuses to start if indexes are missing. Reset with
> `php artisan migrate:fresh` and seed again.

---

## 4. Run it

```bash
php artisan serve
```

Open **http://127.0.0.1:8000/salaries/benchmark**. With Laragon you can also use `http://<folder-name>.test/salaries/benchmark`,
e.g. `http://bulkdatastudy.test/salaries/benchmark`.

Pick a scenario, adjust the parameters, and click **Run**. Results load in place with axios; the page doesn't reload.
The URL updates as you go, so you can bookmark or share a specific run.

### Pages

| URL | What it does |
|---|---|
| `/salaries/benchmark` | Runs one scenario through **Raw SQL, Query Builder, Eloquent lazy, Eloquent eager** and ranks them |
| `/salaries/patterns` | Runs one scenario through **Factory, lazy Proxy, memoizing Proxy, logging Decorator, cache Decorator, log+cache stack** around a base style you choose |
| `/salaries/{pattern}/{scenario}` | A single run of one style, e.g. `/salaries/eloquent_lazy/offset`, with its data and a "Next page" link |

Add `?format=json` to any URL to get the raw numbers.

### Scenarios

| `scenario` | Query | Parameters |
|---|---|---|
| `find` | One salary by primary key | `id` |
| `user` | One user's payroll history | `user_id` |
| `offset` | 50 rows of a department for a month, `LIMIT/OFFSET` | `department_id`, `pay_period`, `page`, `per_page` |
| `keyset` | Same page using `WHERE id > after_id` | `department_id`, `pay_period`, `after_id`, `per_page` |
| `summary` | Totals per department over a user range | `pay_period`, `from_user`, `users` |

Other parameters: `runs` (timed runs, default 5) and `patterns[]` (which ones to include). On `/salaries/patterns` there are also `base`
(`raw`, `builder`, `eloquent_lazy`, `eloquent_eager`) and `cache_store`.

### Reading the results

| Column | Meaning |
|---|---|
| **First ms** | The first call, not counted in the stats. For cache/memo patterns this is the *miss*; the median is the *hit* |
| **Median / Min / Avg / Max ms** | Total time inside the repository call over `runs` timed runs |
| **DB ms** | Time Laravel's query log attributes to MySQL (execute + fetch) |
| **PHP ms** | Everything else: building SQL, hydrating models, casts, relation loading, wrapper overhead |
| **Queries** | Shown in red above 2. That's usually N+1 |
| **vs fastest** | How many times slower than the winner |

Expand **SQL issued** to see every query with its time and bindings.

---

## 5. Experiments to try

1. **Watch N+1 happen.** `/salaries/benchmark?scenario=offset`: compare the *Queries* column for Eloquent lazy (101) vs eager (3).
2. **Break OFFSET.** On `/salaries/raw/offset`, raise `page` gradually: 1, 50, 200, 1000. Then use `keyset` and click
   "Next page" as many times as you like. ⚠ Very large pages (10,000+) can tie up MySQL for minutes.
3. **Measure the ORM cost.** Compare *PHP ms* for Query Builder vs Eloquent eager on the same 50 rows. The DB time is about the same; model hydration is the difference.
4. **Find the cost of a pattern.** `/salaries/patterns?scenario=offset&base=eloquent_eager`: Factory and lazy Proxy cost almost nothing,
   logging costs a file write, and caching skips the database entirely.
5. **Change the cache store.** Set `cache_store` to `file` vs `database` (or `redis` if you have it) and compare the cache hit times.
6. **Read the plans yourself.** Copy a query from *SQL issued* and run `EXPLAIN` / `EXPLAIN ANALYZE` on it in MySQL.
   [`RawSqlSalaryRepository`](app/Repositories/Salaries/RawSqlSalaryRepository.php) has comments explaining the two
   plan problems that were fixed (join order in `summary`, deferred join for pages).
7. **Add your own style.** Implement `SalaryRepository`, register it in `SalaryRepositoryFactory::driver()` and
   `SalaryBenchmark::PATTERNS`, and it appears on the page.

---

## Project map

```
app/
  Models/                      Salary, Department, User (salaries relation)
  Repositories/Salaries/
    SalaryRepository.php        interface every style implements
    RawSqlSalaryRepository      DB::select with hand-written SQL
    QueryBuilderSalaryRepository DB::table fluent builder
    EloquentSalaryRepository    Eloquent, lazy (N+1) or eager
    SalaryRepositoryFactory     Factory: builds a style and wraps it in layers
    SalaryRepositoryWrapper     base class for proxies/decorators
    LazySalaryRepositoryProxy   Proxy: creates the real repository on first use
    MemoizingSalaryRepositoryProxy  Proxy: in-request memoization
    CachingSalaryRepository     Decorator: Laravel cache store
    LoggingSalaryRepository     Decorator: one log line per call
  Support/
    SalaryBenchmark.php         warm-up, interleaved timed runs, stats
    BulkPayroll.php             deterministic row generators for the seeder
  Console/Commands/BulkSeedBatch.php   one loader worker (bulk:seed-batch)
  Http/Controllers/SalaryBenchmarkController.php
database/
  migrations/                  departments, salaries (+ Laravel defaults)
  seeders/BulkPayrollSeeder.php
resources/views/benchmark/     Blade pages and partials (axios swaps the partials)
```

---

## Notes and cleanup

- **Benchmarks are local and approximate.** One machine, data warm in the buffer pool, and patterns run interleaved to reduce bias.
  Run each scenario a few times before drawing conclusions.
- **The cache table grows.** Every `/salaries/patterns` run uses fresh cache keys so that each run starts uncached.
  Clear them with `php artisan cache:clear`.
- **The logging decorator** writes to `storage/logs/laravel.log` on every call.
- **Stuck query?** Find and stop it in MySQL:
  ```sql
  SHOW PROCESSLIST;
  KILL <id>;
  ```
- **Free the disk space** when you're done: `php artisan migrate:fresh` (or drop the `bulk` database).

## Troubleshooting

| Problem | Fix |
|---|---|
| `Composer detected issues in your platform ... PHP >= 8.3.0` | Your `php` on PATH is older. Use the PHP 8.3 binary, e.g. `C:\laragon\bin\php\php-8.3.x\php.exe artisan ...` |
| `Loading local data is disabled` | Set `local_infile=1` in MySQL and restart (see step 2) |
| Seeder says indexes are missing | A previous run was interrupted. Run `php artisan migrate:fresh` |
| Seeding runs out of disk | Point `BULK_CSV_DIR` / `BULK_SORT_DIR` at another drive, or lower `BULK_USERS` |
| Page shows no rows | `pay_period` is outside the seeded months. With the default 10 per user, valid values are `2025-01-01` to `2025-10-01` |

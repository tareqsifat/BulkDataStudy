<?php

namespace App\Repositories\Salaries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Fluent query builder (DB::table): SQL is compiled at runtime, rows come back as stdClass.
 */
final class QueryBuilderSalaryRepository implements SalaryRepository
{
    public function find(int $id): array
    {
        return $this->listing($this->salaries()->where('id', $id)->limit(1));
    }

    public function forUser(int $userId): array
    {
        return $this->listing($this->salaries()->where('user_id', $userId), 's.pay_period');
    }

    public function offsetPage(int $departmentId, string $payPeriod, int $page, int $perPage): array
    {
        return $this->listing(
            $this->salaries()
                ->where('department_id', $departmentId)
                ->where('pay_period', $payPeriod)
                ->orderBy('id')
                ->forPage($page, $perPage),
        );
    }

    public function keysetPage(int $departmentId, string $payPeriod, int $afterId, int $perPage): array
    {
        return $this->listing(
            $this->salaries()
                ->where('department_id', $departmentId)
                ->where('pay_period', $payPeriod)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($perPage),
        );
    }

    public function summary(string $payPeriod, int $fromUserId, int $toUserId): array
    {
        // Aggregate in a subquery before joining departments (see RawSqlSalaryRepository::summary).
        $totals = DB::table('salaries')
            ->selectRaw('department_id, COUNT(*) AS employees, SUM(gross_salary) AS gross_total, SUM(net_salary) AS net_total')
            ->whereBetween('user_id', [$fromUserId, $toUserId])
            ->where('pay_period', $payPeriod)
            ->groupBy('department_id');

        return $this->rows(
            DB::table('departments as d')
                ->joinSub($totals, 't', 't.department_id', '=', 'd.id')
                ->select(['d.name as department', 't.employees', 't.gross_total', 't.net_total'])
                ->orderBy('d.id'),
        );
    }

    private function salaries(): Builder
    {
        return DB::table('salaries')->select(SalaryRepository::LIST_COLUMNS);
    }

    /**
     * Deferred join onto the already-limited page (see RawSqlSalaryRepository::listing).
     */
    private function listing(Builder $page, string $orderBy = 's.id'): array
    {
        return $this->rows(
            DB::query()
                ->fromSub($page, 's')
                ->join('users as u', 'u.id', '=', 's.user_id')
                ->join('departments as d', 'd.id', '=', 's.department_id')
                ->select([
                    's.id', 's.user_id', 'u.name as user_name', 'd.name as department', 's.pay_period',
                    's.designation', 's.gross_salary', 's.net_salary', 's.status',
                ])
                ->orderBy($orderBy),
        );
    }

    private function rows(Builder $query): array
    {
        return $query->get()->map(static fn (object $row): array => (array) $row)->all();
    }
}

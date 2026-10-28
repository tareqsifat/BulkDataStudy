<?php

namespace App\Repositories\Salaries;

use Illuminate\Support\Facades\DB;

/**
 * Hand-written SQL through DB::select(): no query building, no hydration.
 */
final class RawSqlSalaryRepository implements SalaryRepository
{
    public function find(int $id): array
    {
        return $this->listing('WHERE id = ? LIMIT 1', [$id]);
    }

    public function forUser(int $userId): array
    {
        return $this->listing('WHERE user_id = ? ORDER BY pay_period', [$userId], 's.pay_period');
    }

    public function offsetPage(int $departmentId, string $payPeriod, int $page, int $perPage): array
    {
        return $this->listing(
            'WHERE department_id = ? AND pay_period = ? ORDER BY id LIMIT ? OFFSET ?',
            [$departmentId, $payPeriod, $perPage, ($page - 1) * $perPage],
        );
    }

    public function keysetPage(int $departmentId, string $payPeriod, int $afterId, int $perPage): array
    {
        return $this->listing(
            'WHERE department_id = ? AND pay_period = ? AND id > ? ORDER BY id LIMIT ?',
            [$departmentId, $payPeriod, $afterId, $perPage],
        );
    }

    public function summary(string $payPeriod, int $fromUserId, int $toUserId): array
    {
        return $this->rows(
            // Aggregate first, then join: joining departments directly lets MySQL drive from the 12-row table
            // and probe every salary of each department through (department_id, pay_period).
            'SELECT d.name AS department, t.employees, t.gross_total, t.net_total
             FROM (
                 SELECT department_id, COUNT(*) AS employees,
                        SUM(gross_salary) AS gross_total, SUM(net_salary) AS net_total
                 FROM salaries
                 WHERE user_id BETWEEN ? AND ? AND pay_period = ?
                 GROUP BY department_id
             ) t
             JOIN departments d ON d.id = t.department_id
             ORDER BY d.id',
            [$fromUserId, $toUserId, $payPeriod],
        );
    }

    /**
     * Deferred join: pick the page of salaries first, then join users/departments onto just those rows.
     * Joining users in the outer query directly makes MySQL drop the ordered index read and filesort the
     * whole department/period (~840k rows) before applying LIMIT.
     */
    private function listing(string $filter, array $bindings, string $orderBy = 's.id'): array
    {
        $columns = implode(', ', SalaryRepository::LIST_COLUMNS);

        return $this->rows(
            "SELECT s.id, s.user_id, u.name AS user_name, d.name AS department, s.pay_period,
                    s.designation, s.gross_salary, s.net_salary, s.status
             FROM (SELECT {$columns} FROM salaries {$filter}) s
             JOIN users u ON u.id = s.user_id
             JOIN departments d ON d.id = s.department_id
             ORDER BY {$orderBy}",
            $bindings,
        );
    }

    private function rows(string $sql, array $bindings): array
    {
        return array_map(static fn (object $row): array => (array) $row, DB::select($sql, $bindings));
    }
}

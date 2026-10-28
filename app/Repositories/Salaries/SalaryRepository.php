<?php

namespace App\Repositories\Salaries;

/**
 * One read API, several data-access patterns behind it. Every implementation returns the same plain-array
 * shape so the benchmark compares only the cost of fetching (and, for Eloquent, hydrating) the rows.
 *
 * List rows:    id, user_id, user_name, department, pay_period, designation, gross_salary, net_salary, status
 * Summary rows: department, employees, gross_total, net_total
 */
interface SalaryRepository
{
    public const LIST_COLUMNS = [
        'id', 'user_id', 'department_id', 'pay_period', 'designation', 'gross_salary', 'net_salary', 'status',
    ];

    /** Single row by primary key. */
    public function find(int $id): array;

    /** A user's full payroll history (unique index user_id, pay_period). */
    public function forUser(int $userId): array;

    /** Department listing for a pay period using LIMIT/OFFSET pagination. */
    public function offsetPage(int $departmentId, string $payPeriod, int $page, int $perPage): array;

    /** Same listing using keyset (seek) pagination: WHERE id > :afterId. */
    public function keysetPage(int $departmentId, string $payPeriod, int $afterId, int $perPage): array;

    /** Per-department totals for a pay period across a user id range. */
    public function summary(string $payPeriod, int $fromUserId, int $toUserId): array;
}

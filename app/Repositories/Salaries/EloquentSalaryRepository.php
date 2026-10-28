<?php

namespace App\Repositories\Salaries;

use App\Models\Salary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Active Record via Eloquent. With $eager = false the user and department are lazy-loaded per model, which
 * is the classic N+1 (2 extra queries per row); with $eager = true each is fetched once through with().
 */
final class EloquentSalaryRepository implements SalaryRepository
{
    public function __construct(private readonly bool $eager) {}

    public function find(int $id): array
    {
        return $this->rows($this->listing()->whereKey($id)->limit(1)->get());
    }

    public function forUser(int $userId): array
    {
        return $this->rows($this->listing()->where('user_id', $userId)->orderBy('pay_period')->get());
    }

    public function offsetPage(int $departmentId, string $payPeriod, int $page, int $perPage): array
    {
        return $this->rows(
            $this->listing()
                ->where('department_id', $departmentId)
                ->where('pay_period', $payPeriod)
                ->orderBy('id')
                ->forPage($page, $perPage)
                ->get(),
        );
    }

    public function keysetPage(int $departmentId, string $payPeriod, int $afterId, int $perPage): array
    {
        return $this->rows(
            $this->listing()
                ->where('department_id', $departmentId)
                ->where('pay_period', $payPeriod)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($perPage)
                ->get(),
        );
    }

    public function summary(string $payPeriod, int $fromUserId, int $toUserId): array
    {
        $totals = Salary::query()
            ->selectRaw('department_id, COUNT(*) AS employees, SUM(gross_salary) AS gross_total, SUM(net_salary) AS net_total')
            ->whereBetween('user_id', [$fromUserId, $toUserId])
            ->where('pay_period', $payPeriod)
            ->groupBy('department_id')
            ->orderBy('department_id')
            ->when($this->eager, fn (Builder $query) => $query->with('department:id,name'))
            ->get();

        return $totals->map(static fn (Salary $row): array => [
            'department' => $row->department->name,
            'employees' => $row->employees,
            'gross_total' => $row->gross_total,
            'net_total' => $row->net_total,
        ])->all();
    }

    /**
     * @return Builder<Salary>
     */
    private function listing(): Builder
    {
        return Salary::query()
            ->select(SalaryRepository::LIST_COLUMNS)
            ->when($this->eager, fn (Builder $query) => $query->with(['user:id,name', 'department:id,name']));
    }

    /**
     * @param  Collection<int, Salary>  $salaries
     */
    private function rows(Collection $salaries): array
    {
        return $salaries->map(static fn (Salary $salary): array => [
            'id' => $salary->id,
            'user_id' => $salary->user_id,
            'user_name' => $salary->user->name,
            'department' => $salary->department->name,
            'pay_period' => $salary->pay_period->toDateString(),
            'designation' => $salary->designation,
            'gross_salary' => $salary->gross_salary,
            'net_salary' => $salary->net_salary,
            'status' => $salary->status,
        ])->all();
    }
}

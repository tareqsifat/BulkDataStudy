<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use IzAhmad\TurboSeeder\Helpers\TurboData;

/**
 * Row generators for the bulk payroll seed. Ids are derived from the row ordinal so that batches can be
 * loaded by parallel workers and still produce a gap-free, deterministic users / salaries id range.
 */
final class BulkPayroll
{
    public const DEPARTMENTS = [
        ['ENG', 'Engineering'],
        ['SALES', 'Sales'],
        ['MKT', 'Marketing'],
        ['FIN', 'Finance'],
        ['HR', 'Human Resources'],
        ['OPS', 'Operations'],
        ['CS', 'Customer Support'],
        ['PROC', 'Procurement'],
        ['LEGAL', 'Legal'],
        ['IT', 'IT Infrastructure'],
        ['RND', 'Research & Development'],
        ['QA', 'Quality Assurance'],
    ];

    public const USER_COLUMNS = [
        'id', 'name', 'email', 'email_verified_at', 'password', 'remember_token', 'created_at', 'updated_at',
    ];

    public const SALARY_COLUMNS = [
        'id', 'user_id', 'department_id', 'approved_by',
        'pay_period', 'period_start', 'period_end', 'pay_date',
        'employment_type', 'grade', 'designation', 'cost_center', 'currency',
        'working_days', 'present_days', 'leave_days', 'absent_days',
        'basic_salary', 'house_rent', 'medical_allowance', 'transport_allowance', 'other_allowance',
        'overtime_hours', 'overtime_rate', 'overtime_amount', 'bonus', 'commission', 'gross_salary',
        'income_tax', 'provident_fund', 'insurance', 'loan_deduction', 'absence_deduction',
        'total_deduction', 'net_salary',
        'payment_method', 'bank_account', 'status', 'approved_at', 'paid_at', 'adjustments',
        'created_at', 'updated_at',
    ];

    private const FIRST_NAMES = [
        'Aarav', 'Amina', 'Arif', 'Ayesha', 'Daniel', 'Elena', 'Farhan', 'Fatima', 'Hasan', 'Imran',
        'Jannat', 'Karim', 'Laila', 'Liam', 'Maya', 'Mehedi', 'Nadia', 'Noah', 'Omar', 'Priya',
        'Rafi', 'Rahim', 'Sadia', 'Sakib', 'Sara', 'Tanvir', 'Tasnim', 'Yusuf', 'Zara', 'Zayan',
    ];

    private const LAST_NAMES = [
        'Ahmed', 'Akter', 'Begum', 'Chowdhury', 'Das', 'Hossain', 'Islam', 'Khan', 'Mahmud', 'Miah',
        'Rahman', 'Roy', 'Sarkar', 'Siddique', 'Talukder', 'Uddin', 'Smith', 'Garcia', 'Kim', 'Patel',
    ];

    /**
     * Users get id = ordinal + 1.
     */
    public static function userGenerator(int $offset): \Closure
    {
        $password = TurboData::hashedPassword();
        $now = now()->toDateTimeString();
        $first = self::FIRST_NAMES;
        $last = self::LAST_NAMES;
        $firstCount = count($first);
        $lastCount = count($last);

        return static function (int $i) use ($offset, $password, $now, $first, $last, $firstCount, $lastCount): array {
            $n = $offset + $i + 1;

            return [
                'id' => $n,
                'name' => $first[$n % $firstCount].' '.$last[intdiv($n, $firstCount) % $lastCount],
                'email' => "user{$n}@bulk.test",
                'email_verified_at' => $now,
                'password' => $password,
                'remember_token' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        };
    }

    /**
     * Salary rows are laid out user by user: row $g belongs to user ($g / $perUser) + 1 and month ($g % $perUser),
     * so per-user attributes (grade, department, payment method) stay consistent across that user's history.
     *
     * @param  array<int, int>  $deptIds
     * @param  array<int, string>  $deptCodes
     */
    public static function salaryGenerator(int $offset, int $perUser, array $deptIds, array $deptCodes): \Closure
    {
        $periods = self::payPeriods($perUser);
        $lastMonth = $perUser - 1;
        $deptCount = count($deptIds);
        $salesIdx = array_search('SALES', $deptCodes, true);

        $gradeBasic = [];
        $designations = [];
        for ($grade = 1; $grade <= 20; $grade++) {
            $gradeBasic[$grade] = round(12000 * 1.18 ** ($grade - 1), -2);
            $designations[$grade] = match (true) {
                $grade <= 4 => 'Junior Associate',
                $grade <= 8 => 'Associate',
                $grade <= 12 => 'Senior Associate',
                $grade <= 15 => 'Assistant Manager',
                $grade <= 17 => 'Manager',
                $grade <= 19 => 'Senior Manager',
                default => 'Director',
            };
        }

        $employmentTypes = array_merge(['intern', 'part_time', 'contract', 'contract', 'probation'], array_fill(0, 15, 'permanent'));
        $paymentMethods = array_merge(array_fill(0, 7, 'bank_transfer'), ['mobile_banking', 'mobile_banking', 'cheque', 'cash']);
        $methodCount = count($paymentMethods);
        $taxSlabs = [[10000, 0.05], [30000, 0.10], [40000, 0.15], [PHP_INT_MAX, 0.20]];

        return static function (int $i) use (
            $offset, $perUser, $periods, $lastMonth, $deptIds, $deptCodes, $deptCount, $salesIdx,
            $gradeBasic, $designations, $employmentTypes, $paymentMethods, $methodCount, $taxSlabs,
        ): array {
            $g = $offset + $i;
            $u = intdiv($g, $perUser);
            $m = $g - $u * $perUser;
            $userId = $u + 1;
            [$payPeriod, $periodEnd, $payDate, $workDays] = $periods[$m];

            $deptIdx = $u % $deptCount;
            $grade = ($u * 7) % 20 + 1;

            // Earnings
            $basic = $gradeBasic[$grade] + ($u * 7919) % 50 * 100;
            $houseRent = round($basic * 0.5, 2);
            $medical = min(round($basic * 0.1, 2), 10000.0);
            $transport = 1500.0 + $grade * 250;
            $other = mt_rand(1, 100) <= 25 ? mt_rand(0, 300000) / 100 : 0.0;

            $r = mt_rand(1, 100);
            $absent = $r <= 80 ? 0 : ($r <= 95 ? 1 : mt_rand(2, 4));
            $leave = mt_rand(1, 100) <= 70 ? 0 : mt_rand(1, 3);

            $otHours = mt_rand(1, 100) <= 35 ? mt_rand(100, 4000) / 100 : 0.0;
            $otRate = round($basic / ($workDays * 8) * 1.5, 2);
            $otAmount = round($otHours * $otRate, 2);
            $bonus = $m % 6 === 5 ? $basic : 0.0; // two festival bonuses a year
            $commission = $deptIdx === $salesIdx ? mt_rand(0, 2500000) / 100 : 0.0;

            $gross = round($basic + $houseRent + $medical + $transport + $other + $otAmount + $bonus + $commission, 2);

            // Deductions: progressive tax above a 30k monthly threshold
            $tax = 0.0;
            $rest = $gross - 30000;
            foreach ($taxSlabs as [$width, $rate]) {
                if ($rest <= 0) {
                    break;
                }
                $tax += min($rest, $width) * $rate;
                $rest -= $width;
            }
            $tax = round($tax, 2);
            $pf = round($basic * 0.10, 2);
            $insurance = 300.0 + $grade * 25;
            $loan = $u % 5 === 0 ? 2500.0 : 0.0;
            $absenceDeduction = round($basic / $workDays * $absent, 2);
            $totalDeduction = round($tax + $pf + $insurance + $loan + $absenceDeduction, 2);

            // Workflow: older months are settled, the latest month is still in review
            $s = mt_rand(1, 100);
            $status = $m === $lastMonth
                ? ($s <= 60 ? 'approved' : ($s <= 90 ? 'pending' : 'draft'))
                : ($s <= 96 ? 'paid' : ($s <= 98 ? 'on_hold' : 'cancelled'));
            $approved = $status === 'approved' || $status === 'paid';
            $approvedAt = $approved ? $payDate.' 10:00:00' : null;
            $paidAt = $status === 'paid' ? $payDate.' 14:30:00' : null;
            $createdAt = $periodEnd.' 18:00:00';

            $method = $paymentMethods[$u % $methodCount];

            return [
                'id' => $g + 1,
                'user_id' => $userId,
                'department_id' => $deptIds[$deptIdx],
                'approved_by' => $approved ? ($u * 31) % 1000 + 1 : null, // users 1-1,000 act as payroll managers
                'pay_period' => $payPeriod,
                'period_start' => $payPeriod,
                'period_end' => $periodEnd,
                'pay_date' => $payDate,
                'employment_type' => $employmentTypes[$u % 20],
                'grade' => $grade,
                'designation' => $designations[$grade],
                'cost_center' => $deptCodes[$deptIdx].'-'.($u % 40 + 1),
                'currency' => 'BDT',
                'working_days' => $workDays,
                'present_days' => $workDays - $absent - $leave,
                'leave_days' => $leave,
                'absent_days' => $absent,
                'basic_salary' => $basic,
                'house_rent' => $houseRent,
                'medical_allowance' => $medical,
                'transport_allowance' => $transport,
                'other_allowance' => $other,
                'overtime_hours' => $otHours,
                'overtime_rate' => $otRate,
                'overtime_amount' => $otAmount,
                'bonus' => $bonus,
                'commission' => $commission,
                'gross_salary' => $gross,
                'income_tax' => $tax,
                'provident_fund' => $pf,
                'insurance' => $insurance,
                'loan_deduction' => $loan,
                'absence_deduction' => $absenceDeduction,
                'total_deduction' => $totalDeduction,
                'net_salary' => round($gross - $totalDeduction, 2),
                'payment_method' => $method,
                'bank_account' => match ($method) {
                    'bank_transfer' => 'AC'.str_pad((string) $userId, 14, '0', STR_PAD_LEFT),
                    'mobile_banking' => '01'.(3 + $u % 7).str_pad((string) ($userId % 100_000_000), 8, '0', STR_PAD_LEFT),
                    default => null,
                },
                'status' => $status,
                'approved_at' => $approvedAt,
                'paid_at' => $paidAt,
                'adjustments' => mt_rand(1, 100) <= 3
                    ? '{"type":"arrear","amount":'.mt_rand(500, 15000).',"reason":"Salary revision arrear"}'
                    : null,
                'created_at' => $createdAt,
                'updated_at' => $paidAt ?? $approvedAt ?? $createdAt,
            ];
        };
    }

    /**
     * Monthly pay periods starting January 2025: [first day, last day, pay date, working days].
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function payPeriods(int $months): array
    {
        $periods = [];
        $first = CarbonImmutable::parse('2025-01-01');

        for ($m = 0; $m < $months; $m++) {
            $start = $first->addMonthsNoOverflow($m);
            $end = $start->endOfMonth()->startOfDay();

            $workDays = 0;
            for ($day = $start; $day <= $end; $day = $day->addDay()) {
                $workDays += $day->isWeekend() ? 0 : 1;
            }

            $periods[] = [$start->toDateString(), $end->toDateString(), $end->addDays(5)->toDateString(), $workDays];
        }

        return $periods;
    }
}

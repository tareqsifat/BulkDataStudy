<?php

namespace App\Http\Controllers;

use App\Repositories\Salaries\SalaryRepositoryFactory;
use App\Support\SalaryBenchmark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SalaryBenchmarkController extends Controller
{
    /**
     * GET /salaries/benchmark — run one scenario through every pattern and rank them.
     */
    public function index(Request $request): View|JsonResponse
    {
        $params = $this->params($request);
        $selected = $this->selected($request, SalaryBenchmark::PATTERNS);

        $factories = [];
        foreach ($selected as $pattern) {
            $factories[$pattern] = fn () => SalaryRepositoryFactory::make($pattern);
        }

        return $this->compare($request, $params, $factories, SalaryBenchmark::PATTERNS, $selected, [
            'title' => 'Salary read benchmark: data-access styles',
            'action' => route('salaries.benchmark'),
            'linkable' => true,
        ]);
    }

    /**
     * GET /salaries/patterns — Factory / Proxy / Decorator wrappers around one base driver, ranked.
     */
    public function patterns(Request $request): View|JsonResponse
    {
        $params = $this->params($request);
        $extra = $request->validate([
            'base' => ['sometimes', Rule::in(SalaryRepositoryFactory::DRIVERS)],
            'cache_store' => ['sometimes', Rule::in(array_keys(config('cache.stores')))],
        ]) + ['base' => 'builder', 'cache_store' => config('cache.default')];

        $labels = array_map(fn (array $definition) => $definition[0], SalaryBenchmark::DESIGN_PATTERNS);
        $selected = $this->selected($request, $labels);

        // Unique per request and pattern so each cache decorator starts cold: their warm-up call is a miss, the timed runs are hits.
        $run = uniqid();
        $factories = [];
        foreach ($selected as $pattern) {
            $layers = SalaryBenchmark::DESIGN_PATTERNS[$pattern][1];
            $factories[$pattern] = fn () => SalaryRepositoryFactory::make($extra['base'], $layers, $extra['cache_store'], "{$run}:{$pattern}");
        }

        return $this->compare($request, $params + $extra, $factories, $labels, $selected, [
            'title' => 'Salary read benchmark: design patterns',
            'action' => route('salaries.patterns'),
            'linkable' => false,
            'bases' => SalaryRepositoryFactory::DRIVERS,
            'stores' => array_keys(config('cache.stores')),
        ]);
    }

    /**
     * GET /salaries/{pattern}/{scenario} — one pattern, one call, with its rows and timings.
     */
    public function show(Request $request, string $pattern, string $scenario): View|JsonResponse
    {
        abort_unless(isset(SalaryBenchmark::PATTERNS[$pattern], SalaryBenchmark::SCENARIOS[$scenario]), 404);

        $params = $this->params($request->merge(['scenario' => $scenario]));
        $repo = SalaryRepositoryFactory::make($pattern);
        $result = SalaryBenchmark::measure(fn () => SalaryBenchmark::call($repo, $scenario, $params));

        $payload = [
            'pattern' => $pattern,
            'scenario' => $scenario,
            'params' => $params,
            'timing' => array_diff_key($result, ['rows' => 0]),
            'data' => $result['rows'],
        ];

        $data = $payload + [
            'patterns' => SalaryBenchmark::PATTERNS,
            'scenarios' => SalaryBenchmark::SCENARIOS,
        ];

        if ($request->hasHeader('X-Partial')) {
            return view('benchmark._show_results', $data);
        }

        if ($request->wantsJson() || $request->query('format') === 'json') {
            return response()->json($payload);
        }

        return view('benchmark.show', $data);
    }

    /**
     * @param  array<string, \Closure>  $factories
     * @param  array<string, string>  $labels
     * @param  list<string>  $selected
     * @param  array<string, mixed>  $view
     */
    private function compare(Request $request, array $params, array $factories, array $labels, array $selected, array $view): View|JsonResponse
    {
        set_time_limit(0);
        $results = SalaryBenchmark::compare($factories, $labels, $params['scenario'], $params, $params['runs']);

        $payload = [
            'scenario' => $params['scenario'],
            'params' => $params,
            'fastest' => array_key_first($results),
            'results' => array_map(fn (array $r) => array_diff_key($r, ['rows' => 0]), $results),
        ];

        $data = $payload + $view + [
            'patterns' => $labels,
            'scenarios' => SalaryBenchmark::SCENARIOS,
            'selected' => $selected,
            'sample' => reset($results)['rows'] ?? [],
        ];

        if ($request->hasHeader('X-Partial')) {
            return view('benchmark._results', $data);
        }

        if ($request->wantsJson() || $request->query('format') === 'json') {
            return response()->json($payload);
        }

        return view('benchmark.index', $data);
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<string>
     */
    private function selected(Request $request, array $labels): array
    {
        $selected = array_values(array_intersect(array_keys($labels), (array) $request->input('patterns', array_keys($labels))));

        return $selected ?: [array_key_first($labels)];
    }

    /**
     * @return array<string, mixed>
     */
    private function params(Request $request): array
    {
        $validated = $request->validate([
            'scenario' => ['sometimes', Rule::in(array_keys(SalaryBenchmark::SCENARIOS))],
            'runs' => ['sometimes', 'integer', 'between:1,50'],
            'id' => ['sometimes', 'integer', 'min:1'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'department_id' => ['sometimes', 'integer', 'min:1'],
            'pay_period' => ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'after_id' => ['sometimes', 'integer', 'min:0'],
            'per_page' => ['sometimes', 'integer', 'between:1,1000'],
            'from_user' => ['sometimes', 'integer', 'min:1'],
            'users' => ['sometimes', 'integer', 'between:1,1000000'],
        ]);

        $params = $validated + [
            'scenario' => 'find',
            'runs' => 5,
            'id' => 1,
            'user_id' => 1,
            'department_id' => 1,
            'pay_period' => '2025-06-01',
            'page' => 1,
            'after_id' => 0,
            'per_page' => 50,
            'from_user' => 1,
            'users' => 10000,
        ];

        foreach ($params as $key => $value) {
            if (! in_array($key, ['scenario', 'pay_period'], true)) {
                $params[$key] = (int) $value;
            }
        }

        return $params;
    }
}

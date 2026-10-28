<p class="muted">
    {{ $scenarios[$scenario] }}
    @isset($bases) &middot; base driver <code>{{ $params['base'] }}</code>, cache store <code>{{ $params['cache_store'] }}</code> @endisset
    &middot; {{ $params['runs'] }} timed run(s) per pattern after one warm-up, patterns interleaved.
    <a href="{{ request()->fullUrlWithQuery(['format' => 'json']) }}">JSON</a>
</p>

@php($slowest = max(array_column($results, 'median_ms')) ?: 1)

<h2>Results (sorted by median)</h2>
<div class="scroll">
    <table>
        <thead>
        <tr>
            <th>Pattern</th>
            <th style="width:22%"></th>
            <th class="n">First ms</th>
            <th class="n">Median ms</th>
            <th class="n">Min ms</th>
            <th class="n">Avg ms</th>
            <th class="n">Max ms</th>
            <th class="n">DB ms</th>
            <th class="n">PHP ms</th>
            <th class="n">Queries</th>
            <th class="n">Rows</th>
            <th class="n">Mem KB</th>
            <th class="n">vs fastest</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($results as $key => $r)
            <tr>
                <td class="{{ $key === $fastest ? 'fast' : '' }}">{{ $r['label'] }}</td>
                <td><div class="bar" style="width: {{ $r['median_ms'] / $slowest * 100 }}%"></div></td>
                <td class="n muted">{{ number_format($r['first_ms'], 3) }}</td>
                <td class="n">{{ number_format($r['median_ms'], 3) }}</td>
                <td class="n">{{ number_format($r['min_ms'], 3) }}</td>
                <td class="n">{{ number_format($r['avg_ms'], 3) }}</td>
                <td class="n">{{ number_format($r['max_ms'], 3) }}</td>
                <td class="n">{{ number_format($r['db_ms'], 3) }}</td>
                <td class="n">{{ number_format($r['php_ms'], 3) }}</td>
                <td class="n {{ $r['query_count'] > 2 ? 'slow' : '' }}">{{ $r['query_count'] }}</td>
                <td class="n">{{ number_format($r['row_count']) }}</td>
                <td class="n">{{ number_format($r['memory_kb'], 1) }}</td>
                <td class="n">
                    @if ($key === $fastest) fastest
                    @else &times;{{ number_format($r['median_ms'] / max($results[$fastest]['median_ms'], 0.001), 2) }}
                    @endif
                </td>
                <td>
                    @if ($linkable)
                        <a href="{{ route('salaries.show', [$key, $scenario]) . '?' . http_build_query(\Illuminate\Support\Arr::except($params, ['scenario', 'runs'])) }}">open</a>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
<p class="muted">
    DB ms = time Laravel's query log measured for execute + fetch. PHP ms = everything else in the call
    (query compiling, model hydration, casts, relation loading, mapping to arrays, wrapper overhead).
    First ms = the untimed warm-up call; for cache/memo layers that is the miss, the median is the hit.
</p>

<h2>SQL issued (last run)</h2>
@foreach ($results as $r)
    <details>
        <summary>{{ $r['label'] }} &mdash; {{ $r['query_count'] }} quer{{ $r['query_count'] === 1 ? 'y' : 'ies' }}</summary>
        @foreach ($r['queries'] as $q)
            <pre>[{{ number_format($q['time'], 3) }} ms] {{ $q['query'] }}@if ($q['bindings'])  &larr; {{ json_encode($q['bindings']) }}@endif</pre>
        @endforeach
    </details>
@endforeach

<h2>Returned data ({{ $results[$fastest]['label'] }}, first 20 rows)</h2>
@include('benchmark._rows', ['rows' => array_slice($sample, 0, 20)])

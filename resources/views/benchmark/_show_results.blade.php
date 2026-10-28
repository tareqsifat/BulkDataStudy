<p class="muted">
    {{ $scenarios[$scenario] }} &middot; single cold-ish call &middot;
    <a href="{{ request()->fullUrlWithQuery(['format' => 'json']) }}">JSON</a>
</p>

<div class="stats">
    <div class="stat"><span class="muted">Total</span><b>{{ number_format($timing['wall_ms'], 3) }} ms</b></div>
    <div class="stat"><span class="muted">DB</span><b>{{ number_format($timing['db_ms'], 3) }} ms</b></div>
    <div class="stat"><span class="muted">PHP / ORM</span><b>{{ number_format($timing['php_ms'], 3) }} ms</b></div>
    <div class="stat"><span class="muted">Queries</span><b>{{ count($timing['queries']) }}</b></div>
    <div class="stat"><span class="muted">Rows</span><b>{{ number_format(count($data)) }}</b></div>
    <div class="stat"><span class="muted">Memory</span><b>{{ number_format($timing['memory_kb'], 1) }} KB</b></div>
</div>

<h2>Other patterns, same scenario</h2>
<p>
    @foreach ($patterns as $key => $label)
        @if ($key !== $pattern)
            <a href="{{ route('salaries.show', [$key, $scenario]) . '?' . http_build_query($params) }}">{{ $label }}</a>@if (! $loop->last) &middot; @endif
        @endif
    @endforeach
</p>

@if ($scenario === 'keyset' && $data)
    <p><a href="#" data-ajax-set='@json(['after_id' => end($data)['id']])'>Next page &rarr; (after_id={{ end($data)['id'] }})</a></p>
@elseif ($scenario === 'offset' && $data)
    <p><a href="#" data-ajax-set='@json(['page' => $params['page'] + 1])'>Next page &rarr; (page={{ $params['page'] + 1 }})</a></p>
@endif

<h2>SQL issued</h2>
@foreach ($timing['queries'] as $q)
    <pre>[{{ number_format($q['time'], 3) }} ms] {{ $q['query'] }}@if ($q['bindings'])  &larr; {{ json_encode($q['bindings']) }}@endif</pre>
@endforeach

<h2>Data</h2>
@include('benchmark._rows', ['rows' => $data])

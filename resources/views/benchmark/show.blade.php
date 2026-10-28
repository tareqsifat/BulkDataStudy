@extends('benchmark.layout')

@section('title', $patterns[$pattern])

@section('content')
    <p><a href="{{ route('salaries.benchmark') . '?' . http_build_query($params) }}">&larr; Compare all patterns</a></p>
    <h1>{{ $patterns[$pattern] }}</h1>

    @include('benchmark._form', [
        'action' => route('salaries.show', [$pattern, $scenario]),
        'showScenario' => false,
        'showRuns' => false,
    ])

    <div id="results">
        @include('benchmark._show_results')
    </div>
@endsection

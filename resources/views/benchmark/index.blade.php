@extends('benchmark.layout')

@section('title', $title)

@section('content')
    <p>
        <a href="{{ route('salaries.benchmark') }}">Data-access styles</a> &middot;
        <a href="{{ route('salaries.patterns') }}">Design patterns (Factory / Proxy / Decorator)</a>
    </p>
    <h1>{{ $title }}</h1>

    @include('benchmark._form')

    <div id="results">
        @include('benchmark._results')
    </div>
@endsection

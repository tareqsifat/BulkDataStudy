<?php

use App\Http\Controllers\SalaryBenchmarkController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/salaries/benchmark', [SalaryBenchmarkController::class, 'index'])->name('salaries.benchmark');
Route::get('/salaries/patterns', [SalaryBenchmarkController::class, 'patterns'])->name('salaries.patterns');
Route::get('/salaries/{pattern}/{scenario}', [SalaryBenchmarkController::class, 'show'])->name('salaries.show');

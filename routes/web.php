<?php

use App\Http\Controllers\LocalDirectoryBrowserController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LocalProjectSourceController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectScanController;
use App\Http\Controllers\Research\DatasetController;
use App\Http\Controllers\Research\EvidenceController;
use App\Http\Controllers\Research\ExperimentController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::patch('locale', [LocaleController::class, 'update'])
    ->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::resource('projects', ProjectController::class)
        ->only(['index', 'create', 'store', 'show']);

    Route::post('projects/{project}/scan', ProjectScanController::class)
        ->name('projects.scan');

    Route::prefix('projects/{project}/research/datasets')->name('projects.research.datasets.')->group(function () {
        Route::get('/', [DatasetController::class, 'index'])->name('index');
        Route::post('/', [DatasetController::class, 'store'])->name('store');
        Route::get('{dataset}', [DatasetController::class, 'show'])->name('show');
        Route::post('{dataset}/questions', [DatasetController::class, 'addQuestion'])->name('addQuestion');
        Route::patch('{dataset}/questions/{question}', [DatasetController::class, 'updateQuestion'])->whereNumber('question')->name('updateQuestion');
        Route::post('{dataset}/questions/{question}/verify', [DatasetController::class, 'verify'])->whereNumber('question')->name('verify');
        Route::post('{dataset}/validate', [DatasetController::class, 'validateDataset'])->name('validate');
        Route::post('{dataset}/freeze', [DatasetController::class, 'freeze'])->name('freeze');
        Route::get('{dataset}/questions/{question}/evidence/{evidence}', EvidenceController::class)
            ->whereNumber(['question', 'evidence'])->name('evidence');
    });

    Route::prefix('projects/{project}/research/experiments')->name('projects.research.experiments.')->group(function () {
        Route::get('/', [ExperimentController::class, 'index'])->name('index');
        Route::post('/', [ExperimentController::class, 'store'])->name('store');
        Route::get('{experiment}', [ExperimentController::class, 'show'])->name('show');
        Route::post('{experiment}/start', [ExperimentController::class, 'start'])->name('start');
        Route::post('{experiment}/resume', [ExperimentController::class, 'resume'])->name('resume');
    });

    Route::get('projects/{project}/sources/local/directories', LocalDirectoryBrowserController::class)
        ->name('projects.sources.local.directories');
    Route::put('projects/{project}/sources/local', [LocalProjectSourceController::class, 'update'])
        ->name('projects.sources.local.update');
    Route::delete('projects/{project}/sources/local', [LocalProjectSourceController::class, 'destroy'])
        ->name('projects.sources.local.destroy');
});

require __DIR__.'/settings.php';

<?php

use App\Http\Controllers\LocalDirectoryBrowserController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LocalProjectSourceController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectScanController;
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

    Route::get('projects/{project}/sources/local/directories', LocalDirectoryBrowserController::class)
        ->name('projects.sources.local.directories');
    Route::put('projects/{project}/sources/local', [LocalProjectSourceController::class, 'update'])
        ->name('projects.sources.local.update');
    Route::delete('projects/{project}/sources/local', [LocalProjectSourceController::class, 'destroy'])
        ->name('projects.sources.local.destroy');
});

require __DIR__.'/settings.php';

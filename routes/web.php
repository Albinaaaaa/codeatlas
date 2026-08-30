<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::patch('locale', [LocaleController::class, 'update'])
    ->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::resource('projects', ProjectController::class)
        ->only(['index', 'create', 'store', 'show']);
});

require __DIR__.'/settings.php';

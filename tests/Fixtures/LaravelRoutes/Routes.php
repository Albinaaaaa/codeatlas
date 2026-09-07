<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/users', [UserController::class, 'index'])
    ->name('users.index')
    ->middleware(['auth', 'verified']);
Route::post('/users', [UserController::class, 'store']);
Route::put('/users/{user}', [UserController::class, 'update']);
Route::patch('/users/{user}', [UserController::class, 'update']);
Route::delete('/users/{user}', [UserController::class, 'destroy']);
Route::match(['get', 'head'], '/health', function (): void {});

Route::prefix('admin')->name('admin.')->middleware('auth')->group(function (): void {
    Route::get('/users', [AdminController::class, 'index'])->name('users');
});

Route::controller(UserController::class)->prefix('account')->middleware(['web'])->group(function (): void {
    Route::post('/profile', 'update')->name('profile');
});

Route::group([
    'prefix' => 'api',
    'as' => 'api.',
    'middleware' => ['api'],
    'controller' => ApiController::class,
], function (): void {
    Route::get('/items', 'index')->name('items');
});

$dynamicUri = '/dynamic';
Route::get($dynamicUri, [UserController::class, 'index']);

$dynamicPrefix = 'dynamic';
Route::prefix($dynamicPrefix)->group(function (): void {
    Route::get('/skipped', function (): void {});
});

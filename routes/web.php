<?php

use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteInstallController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Placeholder — real controller lands with the webhook task.
Route::post('webhook/deploy/{site}/{token}', fn () => abort(404))->name('webhook.deploy');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::resource('sites', SiteController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::post('sites/{site}/install', SiteInstallController::class)->name('sites.install');
});

require __DIR__.'/settings.php';

<?php

use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DeployScriptController;
use App\Http\Controllers\EnvFileController;
use App\Http\Controllers\SchedulerController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteInstallController;
use App\Http\Controllers\SslController;
use App\Http\Controllers\WebhookDeployController;
use App\Http\Controllers\WorkerController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::post('webhook/deploy/{site}/{token}', WebhookDeployController::class)
    ->middleware('throttle:60,1')
    ->name('webhook.deploy');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::resource('sites', SiteController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::post('sites/{site}/install', SiteInstallController::class)->name('sites.install');
    Route::post('sites/{site}/deployments', [DeploymentController::class, 'store'])->name('sites.deployments.store');
    Route::get('sites/{site}/deployments/{deployment}', [DeploymentController::class, 'show'])->name('sites.deployments.show')->scopeBindings();
    Route::put('sites/{site}/deploy-script', DeployScriptController::class)->name('sites.deploy-script.update');
    Route::put('sites/{site}/env', EnvFileController::class)->name('sites.env.update');
    Route::post('sites/{site}/ssl', SslController::class)->name('sites.ssl.store');
    Route::post('sites/{site}/workers', [WorkerController::class, 'store'])->name('sites.workers.store');
    Route::post('sites/{site}/workers/{worker}/restart', [WorkerController::class, 'restart'])->name('sites.workers.restart')->scopeBindings();
    Route::delete('sites/{site}/workers/{worker}', [WorkerController::class, 'destroy'])->name('sites.workers.destroy')->scopeBindings();
    Route::put('sites/{site}/scheduler', SchedulerController::class)->name('sites.scheduler.update');
});

require __DIR__.'/settings.php';

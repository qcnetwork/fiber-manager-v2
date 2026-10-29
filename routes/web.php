<?php

use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CableController;
use App\Http\Controllers\CoverageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataTransferController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\OdcController;
use App\Http\Controllers\OdpController;
use App\Http\Controllers\OdpPortController;
use App\Http\Controllers\PhotoController;
use App\Http\Middleware\EnsureNotSales;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/map')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Open to every role, including sales.
    Route::get('coverage', [CoverageController::class, 'index'])->name('coverage');
    Route::get('coverage/check', [CoverageController::class, 'check'])->name('coverage.check');
    Route::get('map/features', [MapController::class, 'features'])->name('map.features');
    Route::get('map/search', [MapController::class, 'search'])->name('map.search');
});

Route::middleware(['auth', 'verified', EnsureNotSales::class])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('map', [MapController::class, 'index'])->name('map');
    Route::get('map/nearest', [MapController::class, 'nearest'])->name('map.nearest');

    Route::get('odcs/options', [OdcController::class, 'options'])->name('odcs.options');
    Route::apiResource('odcs', OdcController::class)->except('index');
    Route::apiResource('odps', OdpController::class)->except('index');
    Route::patch('ports/{port}', [OdpPortController::class, 'update'])->name('ports.update');
    Route::apiResource('cables', CableController::class)->except('index');
    Route::post('photos', [PhotoController::class, 'store'])->name('photos.store');
    Route::delete('photos/{photo}', [PhotoController::class, 'destroy'])->name('photos.destroy');

    Route::get('data', [DataTransferController::class, 'index'])->name('data.index');
    Route::get('data/export/{format}', [DataTransferController::class, 'export'])->name('data.export');
    Route::get('data/template', [DataTransferController::class, 'template'])->name('data.template');
    Route::post('data/import', [DataTransferController::class, 'import'])->name('data.import');

    Route::middleware(EnsureSuperAdmin::class)->prefix('admin')->name('admin.')->group(function () {
        Route::resource('areas', AreaController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

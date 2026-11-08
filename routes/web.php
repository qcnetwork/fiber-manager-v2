<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CableController;
use App\Http\Controllers\CoverageController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataTransferController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\OdcController;
use App\Http\Controllers\OdpController;
use App\Http\Controllers\OdpPortController;
use App\Http\Controllers\OltController;
use App\Http\Controllers\OltPortController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\PopController;
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
    Route::get('map/resolve-link', [MapController::class, 'resolveLink'])->middleware('throttle:30,1')->name('map.resolve-link');
});

Route::middleware(['auth', 'verified', EnsureNotSales::class])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('map', [MapController::class, 'index'])->name('map');
    Route::get('map/nearest', [MapController::class, 'nearest'])->name('map.nearest');

    Route::get('pops/{pop}', [PopController::class, 'show'])->name('pops.show');
    Route::patch('pops/{pop}', [PopController::class, 'update'])->name('pops.update');
    Route::get('olts/options', [OltController::class, 'options'])->name('olts.options');
    Route::post('olts', [OltController::class, 'store'])->name('olts.store');
    Route::put('olts/{olt}', [OltController::class, 'update'])->name('olts.update');
    Route::delete('olts/{olt}', [OltController::class, 'destroy'])->name('olts.destroy');
    Route::patch('olt-ports/{port}', [OltPortController::class, 'update'])->name('olt-ports.update');
    Route::post('olt-ports/{port}/odcs', [OltPortController::class, 'connect'])->name('olt-ports.connect');
    Route::delete('olt-ports/{port}/odcs/{odc}', [OltPortController::class, 'disconnect'])->name('olt-ports.disconnect');

    Route::get('odcs/options', [OdcController::class, 'options'])->name('odcs.options');
    Route::apiResource('odcs', OdcController::class)->except('index');
    Route::apiResource('odps', OdpController::class)->except('index');
    Route::patch('ports/{port}', [OdpPortController::class, 'update'])->name('ports.update');
    Route::get('odps/{odp}/free-ports', [CustomerController::class, 'freePorts'])->name('odps.free-ports');
    Route::get('map/customers', [CustomerController::class, 'markers'])->name('map.customers');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    Route::post('customers/{customer}/move', [CustomerController::class, 'move'])->name('customers.move');
    Route::get('cables/nearby', [CableController::class, 'nearby'])->name('cables.nearby');
    Route::apiResource('cables', CableController::class)->except('index');
    Route::post('photos', [PhotoController::class, 'store'])->name('photos.store');
    Route::delete('photos/{photo}', [PhotoController::class, 'destroy'])->name('photos.destroy');

    Route::get('data', [DataTransferController::class, 'index'])->name('data.index');
    Route::get('data/export/{format}', [DataTransferController::class, 'export'])->name('data.export');
    Route::get('data/template', [DataTransferController::class, 'template'])->name('data.template');
    Route::post('data/import', [DataTransferController::class, 'import'])->name('data.import');

    Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');

    Route::middleware(EnsureSuperAdmin::class)->prefix('admin')->name('admin.')->group(function () {
        Route::resource('areas', AreaController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

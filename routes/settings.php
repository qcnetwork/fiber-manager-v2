<?php

use App\Http\Controllers\Settings\MapSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Middleware\EnsureNotSales;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');

    // Sales accounts never open the network map.
    Route::middleware(EnsureNotSales::class)->group(function () {
        Route::get('settings/map', [MapSettingsController::class, 'edit'])->name('map-settings.edit');
        Route::put('settings/map', [MapSettingsController::class, 'update'])->name('map-settings.update');
    });
});

<?php

use hexa_package_wordpress_seo\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'locked', 'system_lock', 'two_factor', 'role'])
    ->prefix('wordpress-seo')
    ->name('wordpress-seo.')
    ->group(function () {
        Route::get('/settings', [SettingsController::class, 'show'])->name('settings');
        Route::post('/settings', [SettingsController::class, 'save'])->name('settings.save');
        Route::post('/settings/test-folder', [SettingsController::class, 'testFolder'])->name('settings.test-folder');
    });

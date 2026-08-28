<?php

use hkyss\Extras\Http\Controllers\PackagesController;
use hkyss\Extras\Http\Middlewares\ManagerMiddleware;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => 'api/v1/extras/admin',
    'middleware' => ManagerMiddleware::class,
], function () {
    Route::get('/installed', [PackagesController::class, 'index'])->name('extras.admin.installed');
    Route::get('/catalog', [PackagesController::class, 'catalog'])->name('extras.admin.catalog');

    Route::get('/extras/{vendor}/{package}/plan', [PackagesController::class, 'plan'])
        ->where(['vendor' => '[A-Za-z0-9._-]+', 'package' => '[A-Za-z0-9._-]+'])
        ->name('extras.admin.plan');

    Route::post('/extras/{vendor}/{package}', [PackagesController::class, 'store'])
        ->where(['vendor' => '[A-Za-z0-9._-]+', 'package' => '[A-Za-z0-9._-]+'])
        ->name('extras.admin.install');

    Route::delete('/extras/{vendor}/{package}', [PackagesController::class, 'destroy'])
        ->where(['vendor' => '[A-Za-z0-9._-]+', 'package' => '[A-Za-z0-9._-]+'])
        ->name('extras.admin.remove');
});

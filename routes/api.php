<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Admin portal: super admins, landlords and caretakers (users table).
Route::middleware(['auth:sanctum', 'account'])->prefix('admin')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
});

// Tenant portal: renters (tenants table).
Route::middleware(['auth:tenant', 'account'])->prefix('portal')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user('tenant'));
});

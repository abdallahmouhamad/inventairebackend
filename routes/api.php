<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// === Auth routes start ===
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth:api');
Route::get('auth/me', [AuthController::class, 'me'])->middleware('auth:api');
// === Auth routes end ===

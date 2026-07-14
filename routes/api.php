<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SessionInventaireController;
use App\Http\Controllers\UtilisateurController;
use Illuminate\Support\Facades\Route;

// === Auth routes start ===
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth:api');
Route::get('auth/me', [AuthController::class, 'me'])->middleware('auth:api');
// === Auth routes end ===

// === Utilisateurs routes start ===
Route::middleware(['auth:api', 'role.web'])->group(function () {
    Route::post('utilisateurs', [UtilisateurController::class, 'store']);
    Route::put('utilisateurs/{id}', [UtilisateurController::class, 'update']);
    Route::delete('utilisateurs/{id}', [UtilisateurController::class, 'desactiver']);
    Route::put('utilisateurs/{id}/reactiver', [UtilisateurController::class, 'reactiver']);
});
// === Utilisateurs routes end ===

// === Sessions routes start ===
Route::middleware(['auth:api', 'role.web'])->group(function () {
    Route::get('sessions', [SessionInventaireController::class, 'index']);
    Route::post('sessions/synchroniser-x3', [SessionInventaireController::class, 'synchroniserX3']);
    Route::get('sessions/{id}', [SessionInventaireController::class, 'show']);
    Route::put('sessions/{id}/open', [SessionInventaireController::class, 'open']);
    Route::get('sessions/{id}/history', [SessionInventaireController::class, 'history']);
});
// === Sessions routes end ===

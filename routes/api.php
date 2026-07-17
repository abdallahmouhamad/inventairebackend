<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PerimetreController;
use App\Http\Controllers\PerimetreMobileController;
use App\Http\Controllers\ReferentielController;
use App\Http\Controllers\SessionInventaireController;
use App\Http\Controllers\SessionMobileController;
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
    Route::post('sessions/{id}/agents', [SessionInventaireController::class, 'ajouterAgent']);
    Route::delete('sessions/{id}/agents/{utilisateurId}', [SessionInventaireController::class, 'retirerAgent']);
});
// === Sessions routes end ===

// === Sessions mobile routes start ===
Route::middleware(['auth:api', 'role.mobile'])->group(function () {
    Route::get('mobile/sessions', [SessionMobileController::class, 'index']);
    Route::get('mobile/sessions/{id}', [SessionMobileController::class, 'show']);
});
// === Sessions mobile routes end ===

// === Perimetres routes start ===
Route::middleware(['auth:api', 'role.web'])->group(function () {
    Route::get('perimeters', [PerimetreController::class, 'index']);
    Route::get('perimeters/{id}', [PerimetreController::class, 'show']);
    Route::get('sessions/{id}/perimeters', [PerimetreController::class, 'indexParSession']);
    Route::put('perimeters/{id}/force-release', [PerimetreController::class, 'forceRelease']);
    Route::put('perimeters/{perimetreId}/attempts/{tentativeId}/resolve', [PerimetreController::class, 'resoudreTentative']);
});
// === Perimetres routes end ===

// === Perimetres mobile routes start ===
Route::middleware(['auth:api', 'role.mobile'])->group(function () {
    Route::get('mobile/perimeters', [PerimetreMobileController::class, 'mesPerimetres']);
    Route::get('sessions/{id}/available-aisles', [PerimetreMobileController::class, 'rayonsDisponibles']);
    Route::post('perimeters', [PerimetreMobileController::class, 'declarer']);
    Route::put('perimeters/{id}/release', [PerimetreMobileController::class, 'liberer']);
    Route::post('perimeters/{id}/access-attempt', [PerimetreMobileController::class, 'enregistrerTentativeAcces']);
});
// === Perimetres mobile routes end ===

// === Referentiel routes start ===
// Lecture seule, non sensible : accessible aux deux familles de roles (web ET mobile).
Route::middleware(['auth:api'])->group(function () {
    Route::get('reference/sites', [ReferentielController::class, 'sites']);
    Route::get('reference/depots', [ReferentielController::class, 'depots']);
});
// === Referentiel routes end ===

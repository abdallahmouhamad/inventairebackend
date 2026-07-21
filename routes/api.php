<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EntreeAuditController;
use App\Http\Controllers\FicheComptageController;
use App\Http\Controllers\FicheComptageMobileController;
use App\Http\Controllers\PerimetreController;
use App\Http\Controllers\PerimetreMobileController;
use App\Http\Controllers\ReferentielController;
use App\Http\Controllers\SessionInventaireController;
use App\Http\Controllers\SessionMobileController;
use App\Http\Controllers\UtilisateurController;
use App\Http\Controllers\VerrouEmplacementController;
use App\Http\Controllers\VerrouEmplacementMobileController;
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
    Route::put('perimeters/{id}/request-recount', [PerimetreController::class, 'requestRecount']);
    Route::put('perimeters/{id}/cancel-recount', [PerimetreController::class, 'cancelRecount']);
    Route::put('perimeters/{id}/assign-recount-agent', [PerimetreController::class, 'assignRecountAgent']);
    Route::get('perimeters/{id}/arbitration', [PerimetreController::class, 'arbitrationOverview']);
    Route::put('perimeters/{id}/arbitration/lines/{ligneId}', [PerimetreController::class, 'arbitrateLine']);
    Route::put('perimeters/{id}/arbitration/complete', [PerimetreController::class, 'completeArbitration']);
    Route::put('perimeters/{id}/relaunch', [PerimetreController::class, 'relaunch']);
});
// === Perimetres routes end ===

// === Perimetres mobile routes start ===
Route::middleware(['auth:api', 'role.mobile'])->group(function () {
    Route::get('mobile/perimeters', [PerimetreMobileController::class, 'mesPerimetres']);
    Route::get('sessions/{id}/available-aisles', [PerimetreMobileController::class, 'rayonsDisponibles']);
    Route::get('sessions/{id}/expected-articles', [PerimetreMobileController::class, 'articlesAttendus']);
    Route::post('perimeters', [PerimetreMobileController::class, 'declarer']);
    Route::put('perimeters/{id}/release', [PerimetreMobileController::class, 'liberer']);
    Route::post('perimeters/{id}/access-attempt', [PerimetreMobileController::class, 'enregistrerTentativeAcces']);
    Route::get('perimeters/{id}/recount-locations', [PerimetreMobileController::class, 'recountLocations']);
    Route::post('perimeters/{id}/recount-submission', [FicheComptageMobileController::class, 'recountSubmission']);
});
// === Perimetres mobile routes end ===

// === Referentiel routes start ===
// Lecture seule, non sensible : accessible aux deux familles de roles (web ET mobile).
Route::middleware(['auth:api'])->group(function () {
    Route::get('reference/sites', [ReferentielController::class, 'sites']);
    Route::get('reference/depots', [ReferentielController::class, 'depots']);
});
// === Referentiel routes end ===

// === Verrous routes start ===
Route::middleware(['auth:api', 'role.web'])->group(function () {
    Route::get('sessions/{id}/locks', [VerrouEmplacementController::class, 'indexParSession']);
    Route::put('locks/{id}/force-release', [VerrouEmplacementController::class, 'forceRelease']);
});
// === Verrous routes end ===

// === Verrous mobile routes start ===
Route::middleware(['auth:api', 'role.mobile'])->group(function () {
    Route::post('locks', [VerrouEmplacementMobileController::class, 'creer']);
    Route::put('locks/{id}/activity', [VerrouEmplacementMobileController::class, 'activite']);
    Route::delete('locks/{id}', [VerrouEmplacementMobileController::class, 'liberer']);
});
// === Verrous mobile routes end ===

// === Fiches de comptage routes start ===
Route::middleware(['auth:api', 'role.web'])->group(function () {
    Route::get('submissions', [FicheComptageController::class, 'index']);
    Route::get('submissions/{id}', [FicheComptageController::class, 'show']);
    Route::get('sessions/{id}/submissions', [FicheComptageController::class, 'indexParSession']);
    Route::put('submissions/{id}/start-review', [FicheComptageController::class, 'startReview']);
    Route::put('submissions/{id}/lines/{lineId}/approve', [FicheComptageController::class, 'approveLine']);
    Route::put('submissions/{id}/lines/{lineId}/reject', [FicheComptageController::class, 'rejectLine']);
    Route::put('submissions/{id}/lines/{lineId}/reset', [FicheComptageController::class, 'resetLine']);
    Route::put('submissions/{id}/validate', [FicheComptageController::class, 'validate']);
    Route::put('submissions/{id}/revision', [FicheComptageController::class, 'revision']);
});
// === Fiches de comptage routes end ===

// === Fiches de comptage mobile routes start ===
Route::middleware(['auth:api', 'role.mobile'])->group(function () {
    Route::post('submissions', [FicheComptageMobileController::class, 'creer']);
    Route::put('submissions/{id}/resoumettre', [FicheComptageMobileController::class, 'resoumettre']);
});
// === Fiches de comptage mobile routes end ===

// === Audit routes start ===
Route::middleware(['auth:api', 'role.web'])->group(function () {
    Route::get('audit/export', [EntreeAuditController::class, 'export']);
    Route::get('audit', [EntreeAuditController::class, 'index']);
});
// === Audit routes end ===

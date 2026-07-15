<?php

namespace App\Http\Controllers;

use App\Models\Perimetre;
use App\Models\QueryModel;
use App\Models\SessionInventaire;
use App\Models\TentativeAccesPerimetre;
use App\Support\Outils;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Supervision Web Admin des perimetres declares par les agents mobiles (doc
 * fonctionnel §6.3). La declaration/liberation volontaire vivent cote mobile
 * (PerimetreMobileController) -- ce controleur couvre la lecture et les
 * actions reservees au responsable (force-release, resolution d'alerte).
 */
class PerimetreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Perimetre::class);

        $perimetres = QueryModel::getQueryPerimetre($request->all())
            ->paginate($request->integer('count', 15), ['*'], 'page', $request->integer('page', 1));

        return response()->json(['data' => $perimetres]);
    }

    public function show(string $id): JsonResponse
    {
        try {
            $perimetre = Perimetre::with('agentDeclarant', 'liberePar', 'tentativesAcces')->findOrFail($id);

            $this->authorize('view', $perimetre);

            return response()->json([
                'data' => [
                    ...$perimetre->toArray(),
                    'codes_rayons' => $perimetre->codesRayons(),
                ],
            ]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }

    public function indexParSession(Request $request, string $id): JsonResponse
    {
        try {
            $session = SessionInventaire::findOrFail($id);

            $this->authorize('view', $session);

            $perimetres = QueryModel::getQueryPerimetre(['session_id' => $session->id])->get();

            return response()->json(['data' => $perimetres]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }

    /**
     * Liberation forcee par le responsable, motif obligatoire (doc
     * fonctionnel §6.3). Libere le perimetre quel que soit son statut actif
     * -- utile si un agent a quitte le terrain sans liberer volontairement.
     */
    public function forceRelease(Request $request, string $id): JsonResponse
    {
        try {
            $perimetre = Perimetre::findOrFail($id);

            $this->authorize('forceRelease', $perimetre);

            $request->validate([
                'motif' => 'required|string|min:1',
            ]);

            if (!in_array($perimetre->statut, Perimetre::STATUTS_ACTIFS, true)) {
                throw new Exception("Ce perimetre n'est pas dans un statut actif (statut actuel : {$perimetre->statut}).");
            }

            $perimetre->update([
                'statut' => Perimetre::STATUT_FORCE_RELEASED,
                'libere_le' => now(),
                'libere_par_id' => $request->user()->id,
                'motif_liberation_forcee' => $request->string('motif'),
            ]);

            return response()->json(['data' => $perimetre->fresh('liberePar')]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    /**
     * Marque une tentative d'acces refusee comme traitee.
     */
    public function resoudreTentative(Request $request, string $perimetreId, string $tentativeId): JsonResponse
    {
        try {
            $perimetre = Perimetre::findOrFail($perimetreId);

            $this->authorize('resoudreTentative', $perimetre);

            $tentative = TentativeAccesPerimetre::where('perimetre_conflit_id', $perimetreId)->findOrFail($tentativeId);

            $tentative->update([
                'resolue_le' => now(),
                'resolue_par_id' => $request->user()->id,
            ]);

            return response()->json(['data' => $tentative->fresh()]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }
}

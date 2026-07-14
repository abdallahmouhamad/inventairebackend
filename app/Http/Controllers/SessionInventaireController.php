<?php

namespace App\Http\Controllers;

use App\Models\QueryModel;
use App\Models\SessionInventaire;
use App\Services\X3\ImportateurSessions;
use App\Services\X3\X3ConnecteurInterface;
use App\Support\Outils;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sessions d'inventaire (module metier Web Admin) -- a ne pas confondre avec
 * les sessions natives X3 exposees en lecture par RererentielX3
 * (FRONTEND_CONTEXT.md §2.4 : ce sont deux entites distinctes). Celle-ci vit
 * en Postgres, machine a etats propre (IMPORTED_FROM_X3 -> OPEN -> ...).
 */
class SessionInventaireController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SessionInventaire::class);

        $sessions = QueryModel::getQuerySessionInventaire($request->all())
            ->paginate($request->integer('count', 15), ['*'], 'page', $request->integer('page', 1));

        return response()->json(['data' => $sessions]);
    }

    public function show(string $id): JsonResponse
    {
        try {
            $session = SessionInventaire::with('ouvertePar', 'utilisateursAutorises')->findOrFail($id);

            $this->authorize('view', $session);

            return response()->json(['data' => $session]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }

    /**
     * IMPORTED_FROM_X3 -> OPEN. Seule transition pilotee par le Web Admin
     * (les suivantes sont pilotees par l'app mobile ou le module Sync).
     */
    public function open(string $id): JsonResponse
    {
        try {
            $session = SessionInventaire::findOrFail($id);

            $this->authorize('open', $session);

            if ($session->statut !== SessionInventaire::STATUT_IMPORTED_FROM_X3) {
                throw new Exception("La session ne peut etre ouverte que depuis le statut IMPORTED_FROM_X3 (statut actuel : {$session->statut}).");
            }

            $session->update([
                'statut' => SessionInventaire::STATUT_OPEN,
                'ouverte_aux_agents_le' => now(),
                'ouverte_par' => auth('api')->id(),
            ]);

            return response()->json(['data' => $session->fresh('ouvertePar')]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    /**
     * PULL des sessions natives X3 (via RererentielX3) vers sessions_inventaire.
     * N'ecrase jamais une session deja presente localement -- une fois
     * importee, son cycle de vie web (OPEN, IN_PROGRESS...) nous appartient
     * (doc fonctionnel §7.4 : "sans ecraser les sessions deja en cours de
     * traitement local"). Idempotent : rejouable sans creer de doublons
     * (match sur x3_session_id).
     */
    public function synchroniserX3(Request $request, X3ConnecteurInterface $connecteur, ImportateurSessions $importateur): JsonResponse
    {
        try {
            $this->authorize('synchroniser', SessionInventaire::class);

            $acteur = $request->user();
            $codesSites = $acteur->codesSites();

            $sessionsX3 = empty($codesSites)
                ? $connecteur->recupererSessions()
                : collect($codesSites)
                    ->flatMap(fn (string $codeSite) => $connecteur->recupererSessions($codeSite))
                    ->all();

            $resultat = $importateur->importer($sessionsX3);

            return response()->json([
                'data' => [
                    'total_x3' => count($sessionsX3),
                    'creees' => $resultat['creees'],
                    'deja_presentes' => $resultat['deja_presentes'],
                ],
            ]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 502);
        }
    }

    /**
     * Historique d'audit de la session. Retourne une liste vide pour
     * l'instant : le systeme d'audit transverse (§3.8 FRONTEND_CONTEXT.md,
     * "toute mutation doit ecrire une entree d'audit") n'est pas encore
     * construit -- prochaine etape logique, a ne pas laisser trainer.
     */
    public function history(string $id): JsonResponse
    {
        try {
            $session = SessionInventaire::findOrFail($id);

            $this->authorize('view', $session);

            return response()->json([
                'data' => [],
                'note' => "Journal d'audit pas encore implemente cote backend.",
            ]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }
}

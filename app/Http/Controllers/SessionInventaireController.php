<?php

namespace App\Http\Controllers;

use App\Models\EntreeAudit;
use App\Models\FicheComptage;
use App\Models\Perimetre;
use App\Models\QueryModel;
use App\Models\SessionInventaire;
use App\Models\Utilisateur;
use App\Models\VerrouEmplacement;
use App\Services\AuditService;
use App\Services\X3\ImportateurSessions;
use App\Services\X3\X3ConnecteurInterface;
use App\Support\Outils;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Sessions d'inventaire (module metier Web Admin) -- a ne pas confondre avec
 * les sessions natives X3 exposees en lecture par RererentielX3
 * (FRONTEND_CONTEXT.md §2.4 : ce sont deux entites distinctes). Celle-ci vit
 * en Postgres, machine a etats propre (IMPORTED_FROM_X3 -> OPEN -> ...).
 */
#[OA\Tag(name: 'Sessions', description: 'Sessions d\'inventaire -- Web Admin')]
class SessionInventaireController extends Controller
{
    #[OA\Get(
        path: '/api/sessions',
        summary: 'Liste des sessions, paginee et filtrable',
        description: "Un INVENTORY_MANAGER ne voit que les sessions de ses sites (filtrage automatique cote serveur).",
        security: [['bearerAuth' => []]],
        tags: ['Sessions'],
        parameters: [
            new OA\Parameter(name: 'recherche', in: 'query', description: 'Recherche sur le code ou le nom', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'code_site', in: 'query', schema: new OA\Schema(type: 'string', example: 'MC01')),
            new OA\Parameter(name: 'statut', in: 'query', schema: new OA\Schema(type: 'string', example: 'OPEN')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'count', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [new OA\Response(response: 200, description: 'Liste paginee.')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SessionInventaire::class);

        $sessions = QueryModel::getQuerySessionInventaire($request->all())
            ->paginate($request->integer('count', 15), ['*'], 'page', $request->integer('page', 1));

        return response()->json(['data' => $sessions]);
    }

    #[OA\Get(
        path: '/api/sessions/{id}',
        summary: 'Detail complet d\'une session, avec compteurs de progression',
        security: [['bearerAuth' => []]],
        tags: ['Sessions'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Detail de la session.'),
            new OA\Response(response: 403, description: 'Site hors du perimetre de l\'acteur.'),
            new OA\Response(response: 404, description: 'Session introuvable.'),
        ],
    )]
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
    #[OA\Put(
        path: '/api/sessions/{id}/open',
        summary: 'Ouvrir la session aux agents mobiles (IMPORTED_FROM_X3 -> OPEN)',
        security: [['bearerAuth' => []]],
        tags: ['Sessions'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Session ouverte.'),
            new OA\Response(response: 400, description: 'La session n\'est pas dans le statut IMPORTED_FROM_X3.'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER, site hors perimetre.'),
        ],
    )]
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

            AuditService::log(AuditService::SESSION_OUVERTURE, $session, ['code_site' => $session->code_site]);

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
    #[OA\Post(
        path: '/api/sessions/synchroniser-x3',
        summary: 'Declenche un import (PULL) manuel des sessions depuis Sage X3',
        description: 'Scope aux sites de l\'acteur ; n\'ecrase jamais une session deja presente localement.',
        security: [['bearerAuth' => []]],
        tags: ['Sessions'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Import termine.',
                content: new OA\JsonContent(example: ['data' => ['total_x3' => 5, 'creees' => 2, 'deja_presentes' => 3]]),
            ),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
            new OA\Response(response: 502, description: 'RererentielX3 injoignable ou en erreur.'),
        ],
    )]
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

            AuditService::log(AuditService::SESSION_SYNCHRONISATION_X3, null, [
                'total_x3' => count($sessionsX3),
                'creees' => $resultat['creees'],
                'deja_presentes' => $resultat['deja_presentes'],
            ]);

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
     * Autoriser un agent mobile sur la session (l'ajoute a
     * utilisateursAutorises). Seul un compte OPERATOR/MOBILE_MANAGER peut
     * etre autorise -- un compte web n'a rien a faire dans cette liste.
     */
    #[OA\Post(
        path: '/api/sessions/{id}/agents',
        summary: 'Autoriser un agent mobile sur la session',
        security: [['bearerAuth' => []]],
        tags: ['Sessions'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['utilisateur_id'],
                properties: [new OA\Property(property: 'utilisateur_id', type: 'string', format: 'uuid', description: 'Doit etre un compte OPERATOR ou MOBILE_MANAGER')],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Agent autorise.'),
            new OA\Response(response: 400, description: 'Le compte cible n\'est pas un role mobile.'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
        ],
    )]
    public function ajouterAgent(Request $request, string $id): JsonResponse
    {
        try {
            $session = SessionInventaire::findOrFail($id);

            $this->authorize('gererAgents', $session);

            $request->validate([
                'utilisateur_id' => 'required|uuid|exists:utilisateurs,id',
            ]);

            $agent = Utilisateur::findOrFail($request->utilisateur_id);

            if (!$agent->isMobileRole()) {
                throw new Exception("Seul un compte mobile (OPERATOR ou MOBILE_MANAGER) peut etre autorise sur une session.");
            }

            $session->utilisateursAutorises()->syncWithoutDetaching([$agent->id]);

            return response()->json(['data' => $session->fresh('utilisateursAutorises')]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    /**
     * Retire un agent de la liste des autorises.
     */
    #[OA\Delete(
        path: '/api/sessions/{id}/agents/{utilisateurId}',
        summary: 'Retirer un agent de la liste des autorises',
        security: [['bearerAuth' => []]],
        tags: ['Sessions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'utilisateurId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Agent retire.', content: new OA\JsonContent(example: ['data' => true])),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
        ],
    )]
    public function retirerAgent(string $id, string $utilisateurId): JsonResponse
    {
        try {
            $session = SessionInventaire::findOrFail($id);

            $this->authorize('gererAgents', $session);

            $session->utilisateursAutorises()->detach($utilisateurId);

            return response()->json(['data' => true]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    /**
     * Historique d'audit de la session : toutes les entrees dont la cible est
     * la session elle-meme OU l'un de ses perimetres/fiches/verrous
     * (FRONTEND_CONTEXT.md §3.8, "toute mutation doit ecrire une entree
     * d'audit"). Desormais branche sur le vrai journal (App\Models\EntreeAudit)
     * -- voir aussi GET /api/audit pour une vue transverse non filtree par session.
     */
    #[OA\Get(
        path: '/api/sessions/{id}/history',
        summary: 'Historique d\'audit de la session',
        description: 'Entrees dont la cible est la session ou l\'un de ses perimetres/fiches/verrous, triees du plus recent au plus ancien.',
        security: [['bearerAuth' => []]],
        tags: ['Sessions'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Liste des entrees d\'audit.'),
            new OA\Response(response: 403, description: 'Site hors du perimetre de l\'acteur.'),
            new OA\Response(response: 404, description: 'Session introuvable.'),
        ],
    )]
    public function history(string $id): JsonResponse
    {
        try {
            $session = SessionInventaire::findOrFail($id);

            $this->authorize('view', $session);

            $idsPerimetres = Perimetre::where('session_id', $session->id)->pluck('id');
            $idsFiches = FicheComptage::where('session_id', $session->id)->pluck('id');
            $idsVerrous = VerrouEmplacement::where('session_id', $session->id)->pluck('id');

            $entrees = EntreeAudit::with('acteur')
                ->where(function ($q) use ($session, $idsPerimetres, $idsFiches, $idsVerrous) {
                    $q->where(fn ($q2) => $q2->where('cible_type', (new SessionInventaire())->getMorphClass())->where('cible_id', $session->id))
                        ->orWhere(fn ($q2) => $q2->where('cible_type', (new Perimetre())->getMorphClass())->whereIn('cible_id', $idsPerimetres))
                        ->orWhere(fn ($q2) => $q2->where('cible_type', (new FicheComptage())->getMorphClass())->whereIn('cible_id', $idsFiches))
                        ->orWhere(fn ($q2) => $q2->where('cible_type', (new VerrouEmplacement())->getMorphClass())->whereIn('cible_id', $idsVerrous));
                })
                ->orderByDesc('created_at')
                ->get();

            return response()->json(['data' => $entrees]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }
}

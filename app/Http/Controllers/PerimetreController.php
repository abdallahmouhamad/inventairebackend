<?php

namespace App\Http\Controllers;

use App\Models\FicheComptage;
use App\Models\LigneComptage;
use App\Models\Perimetre;
use App\Models\QueryModel;
use App\Models\SessionInventaire;
use App\Models\TentativeAccesPerimetre;
use App\Models\Utilisateur;
use App\Services\ArbitrageService;
use App\Services\AuditService;
use App\Support\Outils;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Supervision Web Admin des perimetres declares par les agents mobiles (doc
 * fonctionnel §6.3). La declaration/liberation volontaire vivent cote mobile
 * (PerimetreMobileController) -- ce controleur couvre la lecture et les
 * actions reservees au responsable (force-release, resolution d'alerte).
 */
#[OA\Tag(name: 'Perimetres', description: 'Supervision Web Admin des zones declarees par les agents')]
class PerimetreController extends Controller
{
    #[OA\Get(
        path: '/api/perimeters',
        summary: 'Liste des perimetres, paginee et filtrable',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres'],
        parameters: [
            new OA\Parameter(name: 'session_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'statut', in: 'query', schema: new OA\Schema(type: 'string', example: 'DECLARED')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'count', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [new OA\Response(response: 200, description: 'Liste paginee.')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Perimetre::class);

        $perimetres = QueryModel::getQueryPerimetre($request->all())
            ->paginate($request->integer('count', 15), ['*'], 'page', $request->integer('page', 1));

        return response()->json(['data' => $perimetres]);
    }

    #[OA\Get(
        path: '/api/perimeters/{id}',
        summary: 'Detail complet d\'un perimetre (rayons couverts + tentatives d\'acces)',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Detail du perimetre.',
                content: new OA\JsonContent(example: [
                    'data' => [
                        'id' => '019f...',
                        'session_id' => '019f...',
                        'code_depot' => 'MC01',
                        'statut' => 'DECLARED',
                        'declare_le' => '2026-07-15T16:13:08.000000Z',
                        'codes_rayons' => ['01A', '01B'],
                        'tentatives_acces' => [],
                    ],
                ]),
            ),
            new OA\Response(response: 403, description: 'Site hors du perimetre de l\'acteur.'),
            new OA\Response(response: 404, description: 'Perimetre introuvable.'),
        ],
    )]
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

    #[OA\Get(
        path: '/api/sessions/{id}/perimeters',
        summary: 'Tous les perimetres d\'une session donnee (non pagine)',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Liste des perimetres.'),
            new OA\Response(response: 403, description: 'Site hors du perimetre de l\'acteur.'),
            new OA\Response(response: 404, description: 'Session introuvable.'),
        ],
    )]
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
    #[OA\Put(
        path: '/api/perimeters/{id}/force-release',
        summary: 'Liberation forcee d\'un perimetre actif (motif obligatoire)',
        description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER (memes site). Utile quand un agent est injoignable et bloque un rayon.',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['motif'],
                properties: [new OA\Property(property: 'motif', type: 'string', example: 'Agent injoignable, fin de journee')],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Perimetre libere (statut FORCE_RELEASED).'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER, ou site hors perimetre (READONLY toujours refuse).'),
            new OA\Response(response: 422, description: 'motif manquant ou vide.'),
            new OA\Response(response: 400, description: 'Le perimetre n\'est pas dans un statut actif.'),
        ],
    )]
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

            AuditService::log(AuditService::PERIMETRE_LIBERATION_FORCEE, $perimetre, ['motif' => $request->string('motif')->toString()]);

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
    #[OA\Put(
        path: '/api/perimeters/{perimetreId}/attempts/{tentativeId}/resolve',
        summary: 'Marque une tentative d\'acces refusee comme traitee',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres'],
        parameters: [
            new OA\Parameter(name: 'perimetreId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'tentativeId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Tentative marquee resolue.'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
            new OA\Response(response: 404, description: 'Perimetre ou tentative introuvable.'),
        ],
    )]
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

    /**
     * Demande un recomptage independant par un autre agent (doc fonctionnel,
     * FRONTEND_CONTEXT.md §3.5). Possible uniquement depuis IN_REVIEW -- si le
     * responsable n'a pas encore commence l'examen (AWAITING_REVIEW), il doit
     * d'abord appeler start-review.
     */
    #[OA\Put(
        path: '/api/perimeters/{id}/request-recount',
        summary: 'Demande un recomptage independant (motif obligatoire)',
        description: 'Fait passer le perimetre IN_REVIEW -> RECOUNT_REQUESTED.',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['motif'], properties: [new OA\Property(property: 'motif', type: 'string', example: 'Ecart suspect, ne correspond pas au stock connu')]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Recomptage demande.'),
            new OA\Response(response: 400, description: 'Le perimetre n\'est pas au statut IN_REVIEW.'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
            new OA\Response(response: 422, description: 'motif manquant ou vide.'),
        ],
    )]
    public function requestRecount(Request $request, string $id): JsonResponse
    {
        try {
            $perimetre = Perimetre::findOrFail($id);

            $this->authorize('requestRecount', $perimetre);

            $request->validate(['motif' => 'required|string|min:1']);

            if ($perimetre->statut !== Perimetre::STATUT_IN_REVIEW) {
                throw new Exception("Le perimetre n'est pas au statut IN_REVIEW (statut actuel : {$perimetre->statut}).");
            }

            $perimetre->update([
                'statut' => Perimetre::STATUT_RECOUNT_REQUESTED,
                'motif_recomptage' => $request->string('motif')->toString(),
                'recount_requested_at' => now(),
                'recount_requested_by_id' => $request->user()->id,
            ]);

            AuditService::log(AuditService::PERIMETRE_RECOMPTAGE_DEMANDE, $perimetre, ['motif' => $request->string('motif')->toString()]);

            return response()->json(['data' => $perimetre->fresh()]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    #[OA\Put(
        path: '/api/perimeters/{id}/cancel-recount',
        summary: 'Annule une demande de recomptage non encore prise en charge',
        description: 'Uniquement possible avant qu\'un agent de recomptage soit assigne (statut RECOUNT_REQUESTED). Revient a IN_REVIEW.',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Demande de recomptage annulee.'),
            new OA\Response(response: 400, description: 'Le perimetre n\'est pas au statut RECOUNT_REQUESTED.'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
        ],
    )]
    public function cancelRecount(string $id): JsonResponse
    {
        try {
            $perimetre = Perimetre::findOrFail($id);

            $this->authorize('cancelRecount', $perimetre);

            if ($perimetre->statut !== Perimetre::STATUT_RECOUNT_REQUESTED) {
                throw new Exception("Le perimetre n'est pas au statut RECOUNT_REQUESTED (statut actuel : {$perimetre->statut}).");
            }

            $perimetre->update([
                'statut' => Perimetre::STATUT_IN_REVIEW,
                'motif_recomptage' => null,
                'recount_requested_at' => null,
                'recount_requested_by_id' => null,
            ]);

            AuditService::log(AuditService::PERIMETRE_RECOMPTAGE_ANNULE, $perimetre);

            return response()->json(['data' => $perimetre->fresh()]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    /**
     * Assigne l'agent charge du recomptage -- doit etre different de l'agent
     * ayant soumis la fiche initiale (comptage independant, doc fonctionnel)
     * et doit deja etre autorise sur la session (meme regle que les autres
     * agents mobiles).
     */
    #[OA\Put(
        path: '/api/perimeters/{id}/assign-recount-agent',
        summary: 'Assigne l\'agent charge du recomptage',
        description: 'L\'agent doit etre different de l\'agent ayant soumis la fiche initiale, et deja autorise sur la session.',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['agent_id'], properties: [new OA\Property(property: 'agent_id', type: 'string', format: 'uuid')]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Agent de recomptage assigne (statut RECOUNTING).'),
            new OA\Response(response: 400, description: 'Statut incompatible, agent identique a l\'agent initial, ou agent non autorise sur la session.'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
            new OA\Response(response: 422, description: 'agent_id manquant ou invalide.'),
        ],
    )]
    public function assignRecountAgent(Request $request, string $id): JsonResponse
    {
        try {
            $perimetre = Perimetre::with('session.utilisateursAutorises')->findOrFail($id);

            $this->authorize('assignRecountAgent', $perimetre);

            $request->validate(['agent_id' => 'required|uuid|exists:utilisateurs,id']);

            if ($perimetre->statut !== Perimetre::STATUT_RECOUNT_REQUESTED) {
                throw new Exception("Le perimetre n'est pas au statut RECOUNT_REQUESTED (statut actuel : {$perimetre->statut}).");
            }

            $ficheInitiale = $perimetre->ficheInitiale();

            if (!$ficheInitiale) {
                throw new Exception('Fiche initiale introuvable pour ce perimetre.');
            }

            $agentId = $request->string('agent_id')->toString();

            if ($agentId === $ficheInitiale->agent_id) {
                throw new Exception("L'agent de recomptage doit etre different de l'agent ayant soumis la fiche initiale.");
            }

            $agent = Utilisateur::findOrFail($agentId);

            if (!$agent->isMobileRole()) {
                throw new Exception('Seul un compte mobile (OPERATOR ou MOBILE_MANAGER) peut etre assigne au recomptage.');
            }

            if (!$perimetre->session->utilisateursAutorises->contains('id', $agentId)) {
                throw new Exception("Cet agent n'est pas autorise sur la session de ce perimetre.");
            }

            $perimetre->update([
                'statut' => Perimetre::STATUT_RECOUNTING,
                'recount_agent_id' => $agentId,
            ]);

            AuditService::log(AuditService::PERIMETRE_RECOMPTAGE_AGENT_ASSIGNE, $perimetre, ['agent_id' => $agentId]);

            return response()->json(['data' => $perimetre->fresh('recountAgent')]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    #[OA\Get(
        path: '/api/perimeters/{id}/arbitration',
        summary: 'Vue d\'arbitrage : paires de lignes initiale/recomptage avec ecart calcule',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Liste des paires (ligne_recomptage, ligne_initiale, divergente, ecart).'),
            new OA\Response(response: 403, description: 'Site hors du perimetre de l\'acteur.'),
            new OA\Response(response: 404, description: 'Perimetre, fiche initiale ou fiche de recomptage introuvable.'),
        ],
    )]
    public function arbitrationOverview(string $id): JsonResponse
    {
        try {
            $perimetre = Perimetre::findOrFail($id);

            $this->authorize('view', $perimetre);

            $ficheInitiale = $perimetre->ficheInitiale();
            $ficheRecomptage = $perimetre->ficheRecomptage();

            if (!$ficheInitiale || !$ficheRecomptage) {
                throw new Exception('Ce perimetre n\'a pas (encore) de cycle de recomptage complet.');
            }

            return response()->json(['data' => ArbitrageService::paires($ficheInitiale, $ficheRecomptage)->values()]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }

    #[OA\Put(
        path: '/api/perimeters/{id}/arbitration/lines/{ligneId}',
        summary: 'Tranche l\'arbitrage d\'une paire de lignes divergente',
        description: '{ligneId} = id de la ligne de la fiche de RECOMPTAGE (voir GET .../arbitration).',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'ligneId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['choix'], properties: [new OA\Property(property: 'choix', type: 'string', enum: ['INITIALE', 'RECOMPTAGE'])]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Choix enregistre.'),
            new OA\Response(response: 400, description: 'Statut incompatible, ou ligne sans correspondance appariee.'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
            new OA\Response(response: 422, description: 'choix manquant ou invalide.'),
        ],
    )]
    public function arbitrateLine(Request $request, string $id, string $ligneId): JsonResponse
    {
        try {
            $perimetre = Perimetre::findOrFail($id);

            $this->authorize('arbitrate', $perimetre);

            $request->validate(['choix' => 'required|in:' . LigneComptage::RESULTAT_INITIALE . ',' . LigneComptage::RESULTAT_RECOMPTAGE]);

            if ($perimetre->statut !== Perimetre::STATUT_AWAITING_ARBITRATION) {
                throw new Exception("Le perimetre n'est pas au statut AWAITING_ARBITRATION (statut actuel : {$perimetre->statut}).");
            }

            $ficheRecomptage = $perimetre->ficheRecomptage();
            $ligneRecomptage = $ficheRecomptage?->lignes()->find($ligneId);

            if (!$ligneRecomptage || $ligneRecomptage->ligne_appariee_id === null) {
                throw new Exception('Cette ligne ne fait pas partie d\'une paire appariee de ce perimetre.');
            }

            ArbitrageService::arbitrer($ligneRecomptage, $request->string('choix')->toString());

            AuditService::log(AuditService::PERIMETRE_ARBITRAGE_LIGNE, $perimetre, [
                'ligne_recomptage_id' => $ligneRecomptage->id,
                'choix' => $request->string('choix')->toString(),
            ]);

            return response()->json(['data' => $ligneRecomptage->fresh()]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    #[OA\Put(
        path: '/api/perimeters/{id}/arbitration/complete',
        summary: 'Cloture l\'arbitrage (toutes les paires divergentes doivent avoir un choix)',
        description: 'Fiche initiale -> ARCHIVED, fiche de recomptage -> VALIDATED, perimetre -> VALIDATED.',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Arbitrage cloture, perimetre valide.'),
            new OA\Response(response: 400, description: 'Statut incompatible, ou des lignes restent en attente d\'arbitrage.'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
        ],
    )]
    public function completeArbitration(Request $request, string $id): JsonResponse
    {
        try {
            $perimetre = Perimetre::findOrFail($id);

            $this->authorize('arbitrate', $perimetre);

            if ($perimetre->statut !== Perimetre::STATUT_AWAITING_ARBITRATION) {
                throw new Exception("Le perimetre n'est pas au statut AWAITING_ARBITRATION (statut actuel : {$perimetre->statut}).");
            }

            $ficheInitiale = $perimetre->ficheInitiale();
            $ficheRecomptage = $perimetre->ficheRecomptage();

            if (!$ficheInitiale || !$ficheRecomptage) {
                throw new Exception('Fiche initiale ou fiche de recomptage introuvable pour ce perimetre.');
            }

            if (!ArbitrageService::estComplet($ficheInitiale, $ficheRecomptage)) {
                throw new Exception('Toutes les lignes divergentes doivent avoir un choix d\'arbitrage avant de cloturer.');
            }

            $ficheInitiale->update(['statut' => FicheComptage::STATUT_ARCHIVED]);
            $ficheRecomptage->update(['statut' => FicheComptage::STATUT_VALIDATED]);
            $perimetre->update([
                'statut' => Perimetre::STATUT_VALIDATED,
                'arbitrated_at' => now(),
                'arbitrated_by_id' => $request->user()->id,
            ]);

            AuditService::log(AuditService::PERIMETRE_ARBITRAGE_COMPLETE, $perimetre);

            return response()->json(['data' => $perimetre->fresh()]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    /**
     * Choix manuel du responsable, a la place de complete-arbitration, quand
     * les deux comptages sont juges trop peu fiables pour etre arbitres :
     * abandonne le cycle entier, libere les rayons (RELAUNCHED n'est pas dans
     * STATUTS_ACTIFS) pour permettre une nouvelle declaration.
     */
    #[OA\Put(
        path: '/api/perimeters/{id}/relaunch',
        summary: 'Relance completement le comptage du perimetre (au lieu de cloturer l\'arbitrage)',
        description: 'Fiche initiale et fiche de recomptage -> ARCHIVED, perimetre -> RELAUNCHED (rayons liberes).',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Perimetre relance.'),
            new OA\Response(response: 400, description: 'Le perimetre n\'est pas au statut AWAITING_ARBITRATION.'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
        ],
    )]
    public function relaunch(string $id): JsonResponse
    {
        try {
            $perimetre = Perimetre::findOrFail($id);

            $this->authorize('relaunch', $perimetre);

            if ($perimetre->statut !== Perimetre::STATUT_AWAITING_ARBITRATION) {
                throw new Exception("Le perimetre n'est pas au statut AWAITING_ARBITRATION (statut actuel : {$perimetre->statut}).");
            }

            $perimetre->ficheInitiale()?->update(['statut' => FicheComptage::STATUT_ARCHIVED]);
            $perimetre->ficheRecomptage()?->update(['statut' => FicheComptage::STATUT_ARCHIVED]);
            $perimetre->update(['statut' => Perimetre::STATUT_RELAUNCHED]);

            AuditService::log(AuditService::PERIMETRE_RELANCE, $perimetre);

            return response()->json(['data' => $perimetre->fresh()]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }
}

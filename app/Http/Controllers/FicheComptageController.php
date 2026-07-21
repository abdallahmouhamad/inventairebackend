<?php

namespace App\Http\Controllers;

use App\Models\FicheComptage;
use App\Models\LigneComptage;
use App\Models\Perimetre;
use App\Models\QueryModel;
use App\Models\SessionInventaire;
use App\Services\AuditService;
use App\Services\CalculEcart;
use App\Support\Outils;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Supervision Web Admin des fiches de comptage (doc fonctionnel §6.4).
 * Chemin "normal" uniquement pour cette premiere passe : SUBMITTED ->
 * IN_REVIEW -> VALIDATED/REVISION. La creation vit cote mobile
 * (FicheComptageMobileController).
 */
#[OA\Tag(name: 'Fiches de comptage', description: 'Examen et validation des fiches -- Web Admin')]
class FicheComptageController extends Controller
{
    #[OA\Get(
        path: '/api/submissions',
        summary: 'Liste des fiches de comptage, paginee et filtrable',
        security: [['bearerAuth' => []]],
        tags: ['Fiches de comptage'],
        parameters: [
            new OA\Parameter(name: 'session_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'statut', in: 'query', schema: new OA\Schema(type: 'string', example: 'SUBMITTED')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'count', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [new OA\Response(response: 200, description: 'Liste paginee.')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FicheComptage::class);

        $fiches = QueryModel::getQueryFicheComptage($request->all())
            ->paginate($request->integer('count', 15), ['*'], 'page', $request->integer('page', 1));

        return response()->json(['data' => $fiches]);
    }

    #[OA\Get(
        path: '/api/submissions/{id}',
        summary: 'Detail complet d\'une fiche, avec toutes les lignes et leur ecart calcule',
        security: [['bearerAuth' => []]],
        tags: ['Fiches de comptage'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Detail de la fiche.'),
            new OA\Response(response: 403, description: 'Site hors du perimetre de l\'acteur.'),
            new OA\Response(response: 404, description: 'Fiche introuvable.'),
        ],
    )]
    public function show(string $id): JsonResponse
    {
        try {
            $fiche = FicheComptage::with('agent', 'lignes')->findOrFail($id);

            $this->authorize('view', $fiche);

            return response()->json(['data' => $this->serialiser($fiche)]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }

    #[OA\Get(
        path: '/api/sessions/{id}/submissions',
        summary: 'Fiches de comptage d\'une session donnee (non pagine)',
        security: [['bearerAuth' => []]],
        tags: ['Fiches de comptage'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Liste des fiches.'),
            new OA\Response(response: 403, description: 'Site hors du perimetre de l\'acteur.'),
            new OA\Response(response: 404, description: 'Session introuvable.'),
        ],
    )]
    public function indexParSession(string $id): JsonResponse
    {
        try {
            $session = SessionInventaire::findOrFail($id);

            $this->authorize('view', $session);

            $fiches = QueryModel::getQueryFicheComptage(['session_id' => $session->id])->get();

            return response()->json(['data' => $fiches]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }

    #[OA\Put(
        path: '/api/submissions/{id}/start-review',
        summary: 'Demarre l\'examen (SUBMITTED -> IN_REVIEW)',
        description: 'Idempotent : no-op si la fiche est deja IN_REVIEW ou au-dela, echoue seulement si introuvable.',
        security: [['bearerAuth' => []]],
        tags: ['Fiches de comptage'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Fiche en cours d\'examen.'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
            new OA\Response(response: 404, description: 'Fiche introuvable.'),
        ],
    )]
    public function startReview(string $id): JsonResponse
    {
        try {
            $fiche = FicheComptage::with('perimetre')->findOrFail($id);

            $this->authorize('review', $fiche);

            if ($fiche->statut === FicheComptage::STATUT_SUBMITTED) {
                $fiche->update(['statut' => FicheComptage::STATUT_IN_REVIEW]);

                if ($fiche->perimetre->statut === Perimetre::STATUT_AWAITING_REVIEW) {
                    $fiche->perimetre->update(['statut' => Perimetre::STATUT_IN_REVIEW]);
                }
            }

            return response()->json(['data' => $fiche->fresh()]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }

    #[OA\Put(
        path: '/api/submissions/{id}/lines/{lineId}/approve',
        summary: 'Approuve une ligne de comptage',
        security: [['bearerAuth' => []]],
        tags: ['Fiches de comptage'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'lineId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ligne approuvee.'),
            new OA\Response(response: 400, description: 'La fiche n\'est pas en cours d\'examen (IN_REVIEW).'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
        ],
    )]
    public function approveLine(string $id, string $lineId): JsonResponse
    {
        return $this->mettreAJourStatutLigne($id, $lineId, LigneComptage::REVIEW_APPROVED);
    }

    #[OA\Put(
        path: '/api/submissions/{id}/lines/{lineId}/reject',
        summary: 'Rejette une ligne de comptage (motif obligatoire)',
        security: [['bearerAuth' => []]],
        tags: ['Fiches de comptage'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'lineId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['motif'], properties: [new OA\Property(property: 'motif', type: 'string', example: 'Ecart trop important, a recompter')]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Ligne rejetee.'),
            new OA\Response(response: 400, description: 'La fiche n\'est pas en cours d\'examen (IN_REVIEW).'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
            new OA\Response(response: 422, description: 'motif manquant ou vide.'),
        ],
    )]
    public function rejectLine(Request $request, string $id, string $lineId): JsonResponse
    {
        return $this->mettreAJourStatutLigne($id, $lineId, LigneComptage::REVIEW_REJECTED, $request);
    }

    #[OA\Put(
        path: '/api/submissions/{id}/lines/{lineId}/reset',
        summary: 'Reinitialise une ligne a l\'etat "en attente"',
        security: [['bearerAuth' => []]],
        tags: ['Fiches de comptage'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'lineId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ligne reinitialisee.'),
            new OA\Response(response: 400, description: 'La fiche n\'est pas en cours d\'examen (IN_REVIEW).'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
        ],
    )]
    public function resetLine(string $id, string $lineId): JsonResponse
    {
        return $this->mettreAJourStatutLigne($id, $lineId, LigneComptage::REVIEW_PENDING);
    }

    private function mettreAJourStatutLigne(string $id, string $lineId, string $statut, ?Request $request = null): JsonResponse
    {
        try {
            $fiche = FicheComptage::findOrFail($id);

            $this->authorize('review', $fiche);

            if ($fiche->statut !== FicheComptage::STATUT_IN_REVIEW) {
                throw new Exception("La fiche n'est pas en cours d'examen (statut actuel : {$fiche->statut}).");
            }

            $motif = null;

            if ($statut === LigneComptage::REVIEW_REJECTED) {
                $request?->validate(['motif' => 'required|string|min:1']);
                $motif = $request?->string('motif')->toString();
            }

            $ligne = LigneComptage::where('fiche_comptage_id', $fiche->id)->findOrFail($lineId);

            $ligne->update([
                'statut_review' => $statut,
                'commentaire_rejet' => $motif,
            ]);

            $actionAudit = match ($statut) {
                LigneComptage::REVIEW_APPROVED => AuditService::LIGNE_APPROBATION,
                LigneComptage::REVIEW_REJECTED => AuditService::LIGNE_REJET,
                default => AuditService::LIGNE_REINITIALISATION,
            };
            AuditService::log($actionAudit, $ligne, array_filter(['motif' => $motif]));

            return response()->json(['data' => [
                ...$ligne->fresh()->toArray(),
                'ecart' => CalculEcart::pour($ligne->fresh()),
            ]]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    #[OA\Put(
        path: '/api/submissions/{id}/validate',
        summary: 'Valide la fiche (toutes les lignes doivent etre approuvees)',
        description: 'Fait passer le perimetre associe a VALIDATED (rayons liberes).',
        security: [['bearerAuth' => []]],
        tags: ['Fiches de comptage'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Fiche validee.'),
            new OA\Response(response: 400, description: 'Au moins une ligne n\'est pas APPROVED.'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
        ],
    )]
    public function validate(string $id): JsonResponse
    {
        try {
            $fiche = FicheComptage::with('lignes', 'perimetre')->findOrFail($id);

            $this->authorize('validate', $fiche);

            $nonApprouvees = $fiche->lignes->where('statut_review', '!=', LigneComptage::REVIEW_APPROVED)->count();

            if ($nonApprouvees > 0) {
                throw new Exception("Impossible de valider : {$nonApprouvees} ligne(s) ne sont pas encore approuvees.");
            }

            $fiche->update(['statut' => FicheComptage::STATUT_VALIDATED]);
            $fiche->perimetre->update(['statut' => Perimetre::STATUT_VALIDATED]);

            AuditService::log(AuditService::FICHE_VALIDATION, $fiche);

            return response()->json(['data' => $fiche->fresh()]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    #[OA\Put(
        path: '/api/submissions/{id}/revision',
        summary: 'Renvoie la fiche en revision (au moins une ligne rejetee, aucune en attente)',
        security: [['bearerAuth' => []]],
        tags: ['Fiches de comptage'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [new OA\Property(property: 'commentaire', type: 'string', example: 'Plusieurs ecarts a verifier, merci de recompter les lignes rejetees')]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Fiche renvoyee en revision.'),
            new OA\Response(response: 400, description: 'Aucune ligne rejetee, ou des lignes restent en attente.'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
        ],
    )]
    public function revision(Request $request, string $id): JsonResponse
    {
        try {
            $fiche = FicheComptage::with('lignes')->findOrFail($id);

            $this->authorize('sendRevision', $fiche);

            $rejetees = $fiche->lignes->where('statut_review', LigneComptage::REVIEW_REJECTED)->count();
            $enAttente = $fiche->lignes->where('statut_review', LigneComptage::REVIEW_PENDING)->count();

            if ($rejetees === 0) {
                throw new Exception('Impossible de renvoyer en revision : aucune ligne rejetee.');
            }

            if ($enAttente > 0) {
                throw new Exception("Impossible de renvoyer en revision : {$enAttente} ligne(s) encore en attente d'examen.");
            }

            $fiche->update([
                'statut' => FicheComptage::STATUT_REVISION,
                'commentaire_revision' => $request->input('commentaire'),
            ]);

            AuditService::log(AuditService::FICHE_RENVOI_REVISION, $fiche, array_filter(['commentaire' => $request->input('commentaire')]));

            return response()->json(['data' => $fiche->fresh()]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiser(FicheComptage $fiche): array
    {
        return [
            ...$fiche->toArray(),
            'has_out_of_list_items' => $fiche->lignes->contains('est_hors_liste', true),
            'has_lot_corrections' => $fiche->lignes->contains('est_correction_lot', true),
            'lignes' => $fiche->lignes->map(fn (LigneComptage $ligne) => [
                ...$ligne->toArray(),
                'ecart' => CalculEcart::pour($ligne),
            ]),
        ];
    }
}

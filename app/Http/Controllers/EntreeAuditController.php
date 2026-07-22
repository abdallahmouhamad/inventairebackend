<?php

namespace App\Http\Controllers;

use App\Models\EntreeAudit;
use App\Models\QueryModel;
use App\Support\Outils;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

/**
 * Journal d'audit transverse (doc fonctionnel §6.8) : consultation et export
 * des entrees ecrites par App\Services\AuditService::log(). Consultable par
 * les trois roles web (audit.view), export reserve a SUPER_ADMIN/READONLY
 * (audit.export -- voir App\Policies\EntreeAuditPolicy pour la justification
 * de cette asymetrie).
 */
#[OA\Tag(name: 'Audit', description: 'Journal d\'audit transverse -- Web Admin')]
class EntreeAuditController extends Controller
{
    #[OA\Get(
        path: '/api/audit',
        summary: 'Journal d\'audit, pagine et filtrable',
        security: [['bearerAuth' => []]],
        tags: ['Audit'],
        parameters: [
            new OA\Parameter(name: 'acteur_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'action', in: 'query', description: 'Voir App\Services\AuditService pour la liste des actions possibles', schema: new OA\Schema(type: 'string', example: 'perimetre.declaration')),
            new OA\Parameter(name: 'date_debut', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_fin', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'recherche', in: 'query', description: 'Recherche sur action ou cible_type', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'count', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [new OA\Response(response: 200, description: 'Liste paginee.')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EntreeAudit::class);

        $entrees = QueryModel::getQueryEntreeAudit($request->all())
            ->paginate($request->integer('count', 15), ['*'], 'page', $request->integer('page', 1));

        return response()->json(['data' => Outils::avecHasMore($entrees)]);
    }

    #[OA\Get(
        path: '/api/audit/export',
        summary: 'Export CSV du journal d\'audit (memes filtres que la liste)',
        description: 'Reserve SUPER_ADMIN/READONLY -- un INVENTORY_MANAGER peut consulter mais pas exporter (doc fonctionnel §8.2).',
        security: [['bearerAuth' => []]],
        tags: ['Audit'],
        parameters: [
            new OA\Parameter(name: 'acteur_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'action', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_debut', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_fin', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'recherche', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Fichier CSV en telechargement.', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/READONLY.'),
        ],
    )]
    public function export(Request $request): Response
    {
        $this->authorize('export', EntreeAudit::class);

        $entrees = QueryModel::getQueryEntreeAudit($request->all())->with('acteur')->get();

        $lignes = "id,horodatage,acteur,action,cible_type,cible_id,metadonnees\n";

        foreach ($entrees as $entree) {
            $acteur = $entree->acteur ? trim("{$entree->acteur->prenom} {$entree->acteur->nom}") : '';
            $lignes .= implode(',', [
                $entree->id,
                $entree->created_at?->toIso8601String(),
                '"' . str_replace('"', '""', $acteur) . '"',
                $entree->action,
                $entree->cible_type ?? '',
                $entree->cible_id ?? '',
                '"' . str_replace('"', '""', json_encode($entree->metadonnees ?? [])) . '"',
            ]) . "\n";
        }

        return response($lignes, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="audit-' . now()->format('Y-m-d-His') . '.csv"',
        ]);
    }
}

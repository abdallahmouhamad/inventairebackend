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
}

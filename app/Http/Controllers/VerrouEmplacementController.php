<?php

namespace App\Http\Controllers;

use App\Models\QueryModel;
use App\Models\SessionInventaire;
use App\Models\VerrouEmplacement;
use App\Support\Outils;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Supervision Web Admin des verrous d'emplacement poses par les agents
 * mobiles (doc fonctionnel §6.5). La creation/heartbeat/liberation
 * volontaire vivent cote mobile (VerrouEmplacementMobileController) -- ce
 * controleur couvre la lecture et la liberation forcee.
 */
#[OA\Tag(name: 'Verrous', description: 'Supervision Web Admin des verrous d\'emplacement')]
class VerrouEmplacementController extends Controller
{
    #[OA\Get(
        path: '/api/sessions/{id}/locks',
        summary: 'Verrous actifs d\'une session',
        security: [['bearerAuth' => []]],
        tags: ['Verrous'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des verrous actifs, avec obsolete calcule a la volee.',
                content: new OA\JsonContent(example: [
                    'data' => [
                        [
                            'id' => '019f...', 'session_id' => '019f...', 'perimetre_id' => '019f...',
                            'code_depot' => 'MC01', 'code_rayon' => '01A', 'code_emplacement' => 'MC01-01A-05',
                            'verrouille_le' => '2026-07-16T10:00:00.000000Z',
                            'derniere_activite_le' => '2026-07-16T10:12:00.000000Z',
                            'libere_le' => null, 'obsolete' => false,
                        ],
                    ],
                ]),
            ),
            new OA\Response(response: 403, description: 'Site hors du perimetre de l\'acteur.'),
            new OA\Response(response: 404, description: 'Session introuvable.'),
        ],
    )]
    public function indexParSession(string $id): JsonResponse
    {
        try {
            $session = SessionInventaire::findOrFail($id);

            $this->authorize('view', $session);

            $verrous = QueryModel::getQueryVerrouEmplacement(['session_id' => $session->id, 'actifs_seulement' => true])->get();

            return response()->json(['data' => $verrous->map(fn (VerrouEmplacement $v) => [
                ...$v->toArray(),
                'obsolete' => $v->estObsolete(),
            ])]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }

    #[OA\Put(
        path: '/api/locks/{id}/force-release',
        summary: 'Liberation forcee d\'un verrou (motif obligatoire)',
        description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER (meme site).',
        security: [['bearerAuth' => []]],
        tags: ['Verrous'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['motif'], properties: [new OA\Property(property: 'motif', type: 'string', example: 'Agent injoignable, fin de journee')]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Verrou libere.'),
            new OA\Response(response: 403, description: 'Reserve SUPER_ADMIN/INVENTORY_MANAGER.'),
            new OA\Response(response: 422, description: 'motif manquant ou vide.'),
            new OA\Response(response: 400, description: 'Le verrou est deja libere.'),
        ],
    )]
    public function forceRelease(Request $request, string $id): JsonResponse
    {
        try {
            $verrou = VerrouEmplacement::findOrFail($id);

            $this->authorize('forceRelease', $verrou);

            $request->validate([
                'motif' => 'required|string|min:1',
            ]);

            if ($verrou->libere_le !== null) {
                throw new Exception('Ce verrou est deja libere.');
            }

            $verrou->update([
                'libere_le' => now(),
                'libere_par_id' => $request->user()->id,
                'force_libere' => true,
                'motif_liberation_forcee' => $request->string('motif'),
            ]);

            return response()->json(['data' => $verrou->fresh('liberePar')]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }
}

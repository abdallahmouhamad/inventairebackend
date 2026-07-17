<?php

namespace App\Http\Controllers;

use App\Models\Perimetre;
use App\Models\VerrouEmplacement;
use App\Support\Outils;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Verrouillage d'emplacement cote agent mobile (doc fonctionnel §6.5/§9.2).
 * Routes protegees par le middleware role.mobile.
 */
#[OA\Tag(name: 'Verrous (mobile)', description: 'Verrouillage d\'un emplacement pendant la saisie active')]
class VerrouEmplacementMobileController extends Controller
{
    /**
     * Cree un verrou. Rattachement obligatoire a un perimetre actif declare
     * par l'agent (doc fonctionnel §6.5 : "doit verifier que l'emplacement
     * demande appartient a un rayon inclus dans un perimetre actif declare
     * par cet agent"). Verification atomique qu'aucun verrou actif
     * n'existe deja sur cet emplacement (verrou pessimiste sur le
     * perimetre, meme pattern que la declaration de perimetre).
     */
    #[OA\Post(
        path: '/api/locks',
        summary: 'Verrouiller un emplacement',
        description: 'L\'emplacement doit appartenir a un rayon couvert par un perimetre actif declare par l\'agent connecte.',
        security: [['bearerAuth' => []]],
        tags: ['Verrous (mobile)'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['perimetre_id', 'code_rayon', 'code_emplacement'],
                properties: [
                    new OA\Property(property: 'perimetre_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'code_rayon', type: 'string', example: '01A'),
                    new OA\Property(property: 'code_emplacement', type: 'string', example: 'MC01-01A-05'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Verrou cree.'),
            new OA\Response(response: 403, description: 'Perimetre inexistant, pas actif, ou n\'appartenant pas a l\'agent.'),
            new OA\Response(response: 422, description: 'code_rayon hors du perimetre indique.'),
            new OA\Response(response: 409, description: 'Emplacement deja verrouille (par cet agent ou un autre).'),
        ],
    )]
    public function creer(Request $request): JsonResponse
    {
        $request->validate([
            'perimetre_id' => 'required|uuid',
            'code_rayon' => 'required|string',
            'code_emplacement' => 'required|string',
        ]);

        $agent = $request->user();

        try {
            $perimetre = Perimetre::findOrFail($request->input('perimetre_id'));
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }

        if ($perimetre->agent_declarant_id !== $agent->id) {
            return Outils::reponseErreur(new Exception("Ce perimetre n'appartient pas a l'agent connecte."), 403);
        }

        if (!in_array($perimetre->statut, Perimetre::STATUTS_ACTIFS, true)) {
            return Outils::reponseErreur(new Exception("Ce perimetre n'est pas dans un statut actif (statut actuel : {$perimetre->statut})."), 403);
        }

        $codeRayon = $request->string('code_rayon')->toString();
        $codeEmplacement = $request->string('code_emplacement')->toString();

        if (!in_array($codeRayon, $perimetre->codesRayons(), true)) {
            return Outils::reponseErreur(new Exception("Le rayon {$codeRayon} n'appartient pas au perimetre indique. Declarer d'abord ce rayon."), 422);
        }

        try {
            $verrou = DB::transaction(function () use ($perimetre, $agent, $codeRayon, $codeEmplacement) {
                // Verrou pessimiste sur le perimetre : serialise les creations concurrentes sur ses emplacements.
                Perimetre::where('id', $perimetre->id)->lockForUpdate()->first();

                $dejaVerrouille = VerrouEmplacement::where('session_id', $perimetre->session_id)
                    ->where('code_depot', $perimetre->code_depot)
                    ->where('code_rayon', $codeRayon)
                    ->where('code_emplacement', $codeEmplacement)
                    ->whereNull('libere_le')
                    ->exists();

                if ($dejaVerrouille) {
                    return null;
                }

                return VerrouEmplacement::create([
                    'session_id' => $perimetre->session_id,
                    'perimetre_id' => $perimetre->id,
                    'code_depot' => $perimetre->code_depot,
                    'code_rayon' => $codeRayon,
                    'code_emplacement' => $codeEmplacement,
                    'agent_id' => $agent->id,
                    'verrouille_le' => now(),
                    'derniere_activite_le' => now(),
                ]);
            });

            if ($verrou === null) {
                return Outils::reponseErreur(new Exception("Emplacement {$codeEmplacement} deja verrouille."), 409);
            }

            return response()->json(['data' => $verrou], 201);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    #[OA\Put(
        path: '/api/locks/{id}/activity',
        summary: 'Met a jour l\'horodatage de derniere activite (heartbeat)',
        description: 'A appeler regulierement pendant la saisie pour eviter que le verrou ne devienne obsolete.',
        security: [['bearerAuth' => []]],
        tags: ['Verrous (mobile)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Horodatage mis a jour.'),
            new OA\Response(response: 400, description: 'Pas le proprietaire, ou verrou deja libere.'),
        ],
    )]
    public function activite(Request $request, string $id): JsonResponse
    {
        try {
            $verrou = VerrouEmplacement::findOrFail($id);

            if ($verrou->agent_id !== $request->user()->id) {
                throw new Exception("Seul l'agent ayant cree ce verrou peut le mettre a jour.");
            }

            if ($verrou->libere_le !== null) {
                throw new Exception('Ce verrou est deja libere.');
            }

            $verrou->update(['derniere_activite_le' => now()]);

            return response()->json(['data' => $verrou->fresh()]);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    #[OA\Delete(
        path: '/api/locks/{id}',
        summary: 'Libere un verrou (fin de saisie sur cet emplacement)',
        security: [['bearerAuth' => []]],
        tags: ['Verrous (mobile)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Verrou libere.'),
            new OA\Response(response: 400, description: 'Pas le proprietaire, ou verrou deja libere.'),
        ],
    )]
    public function liberer(Request $request, string $id): JsonResponse
    {
        try {
            $verrou = VerrouEmplacement::findOrFail($id);

            if ($verrou->agent_id !== $request->user()->id) {
                throw new Exception("Seul l'agent ayant cree ce verrou peut le liberer.");
            }

            if ($verrou->libere_le !== null) {
                throw new Exception('Ce verrou est deja libere.');
            }

            $verrou->update(['libere_le' => now()]);

            return response()->json(['data' => $verrou->fresh()]);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }
}

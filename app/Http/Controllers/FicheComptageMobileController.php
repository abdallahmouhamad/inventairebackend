<?php

namespace App\Http\Controllers;

use App\Models\FicheComptage;
use App\Models\LigneComptage;
use App\Models\Perimetre;
use App\Support\Outils;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Soumission de fiche de comptage cote agent mobile (doc fonctionnel
 * §6.4). Routes protegees par le middleware role.mobile. Chemin "normal"
 * uniquement : le recomptage (isRecount) n'est pas encore supporte.
 */
#[OA\Tag(name: 'Fiches de comptage (mobile)', description: 'Soumission d\'une fiche de comptage')]
class FicheComptageMobileController extends Controller
{
    /**
     * Cree la fiche et ses lignes. Fait passer le perimetre de DECLARED a
     * AWAITING_REVIEW (doc fonctionnel §5.3) -- un seul envoi possible par
     * perimetre dans ce chemin "normal" (pas de re-soumission apres
     * REVISION dans cette premiere passe).
     */
    #[OA\Post(
        path: '/api/submissions',
        summary: 'Soumet une fiche de comptage',
        description: 'Le perimetre indique doit appartenir a l\'agent connecte et etre au statut DECLARED.',
        security: [['bearerAuth' => []]],
        tags: ['Fiches de comptage (mobile)'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['perimetre_id', 'lignes'],
                properties: [
                    new OA\Property(property: 'perimetre_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'lignes', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'code_article', type: 'string', example: 'ART-001'),
                            new OA\Property(property: 'nom_article', type: 'string', example: 'Paracetamol 500mg'),
                            new OA\Property(property: 'code_emplacement', type: 'string', example: 'MC01-01A-05'),
                            new OA\Property(property: 'numero_lot', type: 'string', example: 'NL2012503'),
                            new OA\Property(property: 'numero_lot_parent', type: 'string', nullable: true, example: null),
                            new OA\Property(property: 'date_peremption', type: 'string', format: 'date', nullable: true, example: '2027-06-30'),
                            new OA\Property(property: 'est_correction_lot', type: 'boolean', example: false),
                            new OA\Property(property: 'est_hors_liste', type: 'boolean', example: false),
                            new OA\Property(property: 'qte_theorique_itu', type: 'integer', nullable: true, example: 10),
                            new OA\Property(property: 'qte_theorique_stu', type: 'integer', nullable: true, example: 0),
                            new OA\Property(property: 'qte_comptee_itu', type: 'integer', example: 9),
                            new OA\Property(property: 'qte_comptee_stu', type: 'integer', example: 5),
                        ],
                    )),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Fiche soumise, statut SUBMITTED.'),
            new OA\Response(response: 403, description: 'Perimetre inexistant ou n\'appartenant pas a l\'agent.'),
            new OA\Response(response: 400, description: 'Le perimetre n\'est pas au statut DECLARED (deja soumis, ou pas encore declare).'),
            new OA\Response(response: 422, description: 'Champs manquants ou invalides.'),
        ],
    )]
    public function creer(Request $request): JsonResponse
    {
        $request->validate([
            'perimetre_id' => 'required|uuid',
            'lignes' => 'required|array|min:1',
            'lignes.*.code_article' => 'required|string',
            'lignes.*.code_emplacement' => 'required|string',
            'lignes.*.numero_lot' => 'required|string',
            'lignes.*.qte_comptee_itu' => 'required|integer|min:0',
            'lignes.*.qte_comptee_stu' => 'required|integer|min:0',
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

        if ($perimetre->statut !== Perimetre::STATUT_DECLARED) {
            return Outils::reponseErreur(
                new Exception("Ce perimetre n'est pas dans un statut permettant de soumettre une fiche (statut actuel : {$perimetre->statut})."),
                400,
            );
        }

        try {
            $fiche = DB::transaction(function () use ($perimetre, $agent, $request) {
                $fiche = FicheComptage::create([
                    'session_id' => $perimetre->session_id,
                    'perimetre_id' => $perimetre->id,
                    'agent_id' => $agent->id,
                    'statut' => FicheComptage::STATUT_SUBMITTED,
                    'soumise_le' => now(),
                ]);

                foreach ($request->input('lignes') as $ligne) {
                    $fiche->lignes()->create([
                        'code_article' => $ligne['code_article'],
                        'nom_article' => $ligne['nom_article'] ?? null,
                        'code_emplacement' => $ligne['code_emplacement'],
                        'numero_lot' => $ligne['numero_lot'],
                        'numero_lot_parent' => $ligne['numero_lot_parent'] ?? null,
                        'date_peremption' => $ligne['date_peremption'] ?? null,
                        'est_correction_lot' => $ligne['est_correction_lot'] ?? false,
                        'est_hors_liste' => $ligne['est_hors_liste'] ?? false,
                        'qte_theorique_itu' => $ligne['qte_theorique_itu'] ?? null,
                        'qte_theorique_stu' => $ligne['qte_theorique_stu'] ?? null,
                        'qte_comptee_itu' => $ligne['qte_comptee_itu'],
                        'qte_comptee_stu' => $ligne['qte_comptee_stu'],
                        'statut_review' => LigneComptage::REVIEW_PENDING,
                    ]);
                }

                $perimetre->update(['statut' => Perimetre::STATUT_AWAITING_REVIEW]);

                return $fiche;
            });

            return response()->json(['data' => $fiche->fresh('lignes')], 201);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }
}

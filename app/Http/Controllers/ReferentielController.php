<?php

namespace App\Http\Controllers;

use App\Services\X3\X3ConnecteurInterface;
use App\Support\Outils;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Referentiel X3 en lecture seule (sites, depots) -- doc fonctionnel §6.7,
 * GET /reference/sites et GET /reference/depots. Interroge RererentielX3 en
 * direct a chaque appel, jamais de copie locale (meme principe que le reste
 * de l'integration X3).
 *
 * Accessible aux deux familles de roles (auth:api seul, ni role.web ni
 * role.mobile) : contrairement aux autres routes /reference/* du document
 * fonctionnel (permission reference.view, reservee au Web Admin selon §8.2),
 * sites/depots sont de la pure donnee descriptive non sensible, et l'agent
 * mobile en a besoin pour choisir son depot avant de declarer un perimetre
 * (§3.1 : "Selection d'un depot puis d'un ou plusieurs rayons"). Deviation
 * assumee du document, a confirmer si besoin.
 */
#[OA\Tag(name: 'Referentiel', description: 'Sites et depots X3, lecture seule -- accessible Web et Mobile')]
class ReferentielController extends Controller
{
    #[OA\Get(
        path: '/api/reference/sites',
        summary: 'Sites (synchronises depuis X3, lecture seule)',
        security: [['bearerAuth' => []]],
        tags: ['Referentiel'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des sites.',
                content: new OA\JsonContent(example: [
                    'data' => [
                        ['code_site' => 'MC01', 'nom_site' => 'MAGASIN CENTRAL', 'abreviation' => 'MC01', 'code_pays' => 'SN'],
                        ['code_site' => 'AG01', 'nom_site' => 'AGENCE TOAMASINA', 'abreviation' => 'AG01', 'code_pays' => 'SN'],
                    ],
                ]),
            ),
            new OA\Response(response: 502, description: 'RererentielX3 injoignable ou en erreur.'),
        ],
    )]
    public function sites(X3ConnecteurInterface $connecteur): JsonResponse
    {
        try {
            return response()->json(['data' => $connecteur->recupererSites()]);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 502);
        }
    }

    #[OA\Get(
        path: '/api/reference/depots',
        summary: 'Depots, filtrable par site (synchronises depuis X3, lecture seule)',
        security: [['bearerAuth' => []]],
        tags: ['Referentiel'],
        parameters: [
            new OA\Parameter(name: 'site', in: 'query', description: 'Code site X3, optionnel (tous les depots si omis)', schema: new OA\Schema(type: 'string', example: 'MC01')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des depots.',
                content: new OA\JsonContent(example: [
                    'data' => [
                        ['code_depot' => 'MC01', 'nom_depot' => 'Magasin centrale MC01', 'abreviation' => 'MC01', 'code_site' => 'MC01', 'nom_site' => 'MAGASIN CENTRAL'],
                        ['code_depot' => 'CHFR', 'nom_depot' => 'CHAMBRE FROIDE', 'abreviation' => 'CHFR', 'code_site' => 'MC01', 'nom_site' => 'MAGASIN CENTRAL'],
                    ],
                ]),
            ),
            new OA\Response(response: 502, description: 'RererentielX3 injoignable ou en erreur.'),
        ],
    )]
    public function depots(Request $request, X3ConnecteurInterface $connecteur): JsonResponse
    {
        try {
            return response()->json(['data' => $connecteur->recupererDepots($request->string('site')->toString() ?: null)]);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 502);
        }
    }

    /**
     * Equivalent "hors session" de PerimetreMobileController::rayonsDisponibles() :
     * la liste brute des rayons d'un depot, sans la disponibilite temps reel
     * (qui n'a de sens que dans le contexte d'une session active). Permet au
     * front (web comme mobile) de parcourir le referentiel sans etre oblige
     * de passer par une session -- demande explicite pour que le front ne
     * contacte jamais RererentielX3 directement.
     */
    #[OA\Get(
        path: '/api/reference/rayons',
        summary: 'Rayons d\'un depot (synchronises depuis X3, lecture seule)',
        security: [['bearerAuth' => []]],
        tags: ['Referentiel'],
        parameters: [
            new OA\Parameter(name: 'site', in: 'query', required: true, description: 'Code site X3', schema: new OA\Schema(type: 'string', example: 'MC01')),
            new OA\Parameter(name: 'depot', in: 'query', required: true, description: 'Code depot X3', schema: new OA\Schema(type: 'string', example: 'MC01')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des rayons du depot.',
                content: new OA\JsonContent(example: [
                    'data' => [
                        ['code_rayon' => '01A', 'code_depot' => 'MC01', 'code_site' => 'MC01', 'nb_emplacements' => '30', 'nb_emplacements_verrouilles' => '0'],
                    ],
                ]),
            ),
            new OA\Response(response: 422, description: 'site ou depot manquant.'),
            new OA\Response(response: 502, description: 'RererentielX3 injoignable ou en erreur.'),
        ],
    )]
    public function rayons(Request $request, X3ConnecteurInterface $connecteur): JsonResponse
    {
        try {
            $request->validate(['site' => 'required|string', 'depot' => 'required|string']);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        }

        try {
            $rayons = $connecteur->recupererRayons(
                $request->string('site')->toString(),
                $request->string('depot')->toString(),
            );

            return response()->json(['data' => $rayons]);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 502);
        }
    }

    /**
     * Equivalent "hors session" de PerimetreMobileController::articlesAttendus()
     * et SessionInventaireController::stockRayon() : meme donnee (detail des
     * lots en stock d'un rayon), mais accessible sans session_id -- pour un
     * ecran de consultation pure du referentiel (parcourir le stock sans etre
     * dans le contexte d'un comptage en cours).
     */
    #[OA\Get(
        path: '/api/reference/rayons/{code}/stock',
        summary: 'Detail des lots en stock d\'un rayon (paginee, sans session)',
        security: [['bearerAuth' => []]],
        tags: ['Referentiel'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'path', required: true, description: 'Code rayon X3', schema: new OA\Schema(type: 'string', example: '01A')),
            new OA\Parameter(name: 'site', in: 'query', required: true, description: 'Code site X3', schema: new OA\Schema(type: 'string', example: 'MC01')),
            new OA\Parameter(name: 'depot', in: 'query', required: true, description: 'Code depot X3', schema: new OA\Schema(type: 'string', example: 'MC01')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste paginee des lots en stock sur ce rayon.',
                content: new OA\JsonContent(example: [
                    'data' => [
                        ['code_article' => 'ART-001', 'designation_article' => 'Paracetamol 500mg', 'code_emplacement' => 'MC01-01A-05', 'numero_lot' => 'NL2012503', 'qte_pcu' => 24, 'qte_stu' => 240, 'qte_disponible_stu' => 240, 'date_peremption' => '2027-06-30'],
                    ],
                    'pagination' => ['total' => 42, 'page' => 1, 'per_page' => 100, 'last_page' => 1, 'count' => 42, 'has_more' => false],
                ]),
            ),
            new OA\Response(response: 422, description: 'site ou depot manquant.'),
            new OA\Response(response: 502, description: 'RererentielX3 injoignable ou en erreur.'),
        ],
    )]
    public function rayonStock(Request $request, X3ConnecteurInterface $connecteur, string $code): JsonResponse
    {
        try {
            $request->validate([
                'site' => 'required|string',
                'depot' => 'required|string',
                'page' => 'integer|min:1',
                'per_page' => 'integer|min:1|max:500',
            ]);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        }

        try {
            $resultat = $connecteur->recupererDetailRayon(
                $request->string('site')->toString(),
                $request->string('depot')->toString(),
                $code,
                $request->integer('page', 1),
                $request->integer('per_page', 200),
            );

            return response()->json([
                'data' => $resultat['data'],
                'pagination' => $resultat['pagination'],
            ]);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 502);
        }
    }
}

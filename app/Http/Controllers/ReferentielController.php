<?php

namespace App\Http\Controllers;

use App\Services\X3\X3ConnecteurInterface;
use App\Support\Outils;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}

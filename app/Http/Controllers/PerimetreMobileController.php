<?php

namespace App\Http\Controllers;

use App\Models\Perimetre;
use App\Models\QueryModel;
use App\Models\SessionInventaire;
use App\Models\TentativeAccesPerimetre;
use App\Services\AuditService;
use App\Services\X3\X3ConnecteurInterface;
use App\Support\Outils;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Declaration/liberation de perimetre cote agent mobile (doc fonctionnel
 * §3.2/§6.3). Routes protegees par le middleware role.mobile.
 */
#[OA\Tag(name: 'Perimetres (mobile)', description: 'Declaration/liberation de zone de comptage')]
class PerimetreMobileController extends Controller
{
    /**
     * Perimetres qui concernent l'agent connecte, tous statuts confondus --
     * permet a l'app de retrouver son perimetre actif sans dependre d'un
     * cache local (ex: apres reinstallation de l'app), et c'est le seul
     * moyen pour un agent de decouvrir qu'un recomptage lui a ete assigne
     * (pas de notification push : l'app doit sonder cet endpoint). Le champ
     * mon_role permet de distinguer les deux cas cote UI.
     */
    #[OA\Get(
        path: '/api/mobile/perimeters',
        summary: 'Perimetres qui concernent l\'agent connecte (declares par lui, ou assignes pour recomptage)',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres (mobile)'],
        parameters: [
            new OA\Parameter(name: 'session_id', in: 'query', description: 'Filtrer sur une session', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'statut', in: 'query', description: 'Filtrer sur un statut (ex: DECLARED)', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des perimetres de l\'agent, du plus recent au plus ancien.',
                content: new OA\JsonContent(example: [
                    'data' => [
                        ['id' => '019f...', 'session_id' => '019f...', 'code_depot' => 'MC01', 'statut' => 'DECLARED', 'declare_le' => '2026-07-15T16:13:08.000000Z', 'codes_rayons' => ['01A', '01B'], 'mon_role' => 'declarant'],
                        ['id' => '019f...', 'session_id' => '019f...', 'code_depot' => 'MC01', 'statut' => 'RECOUNTING', 'declare_le' => '2026-07-14T09:00:00.000000Z', 'codes_rayons' => ['02A'], 'mon_role' => 'agent_recomptage'],
                    ],
                ]),
            ),
        ],
    )]
    public function mesPerimetres(Request $request): JsonResponse
    {
        $agent = $request->user();
        $perimetres = QueryModel::getQueryPerimetreMobile($agent, $request->all())->get();

        $donnees = $perimetres->map(fn (Perimetre $perimetre) => [
            ...$perimetre->toArray(),
            'codes_rayons' => $perimetre->codesRayons(),
            'mon_role' => $perimetre->recount_agent_id === $agent->id ? 'agent_recomptage' : 'declarant',
        ]);

        return response()->json(['data' => $donnees]);
    }

    /**
     * Rayons d'un depot avec leur disponibilite (doc fonctionnel §3.2 :
     * "L'agent voit les rayons disponibles et les rayons deja occupes par un
     * autre agent, avec son nom").
     */
    #[OA\Get(
        path: '/api/sessions/{id}/available-aisles',
        summary: 'Rayons d\'un depot avec leur disponibilite en temps reel',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres (mobile)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la session', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'depot', in: 'query', required: true, description: 'Code depot X3', schema: new OA\Schema(type: 'string', example: 'MC01')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des rayons du depot.',
                content: new OA\JsonContent(example: [
                    'data' => [
                        ['code_site' => 'MC01', 'code_depot' => 'MC01', 'code_rayon' => '01A', 'nb_emplacements' => '30', 'disponible' => true, 'occupe_par' => null, 'perimetre_id' => null],
                        ['code_site' => 'MC01', 'code_depot' => 'MC01', 'code_rayon' => '01B', 'nb_emplacements' => '30', 'disponible' => false, 'occupe_par' => 'Ibrahima Fall', 'perimetre_id' => '019f...'],
                    ],
                ]),
            ),
            new OA\Response(response: 404, description: 'Session introuvable ou agent non autorise sur cette session.'),
            new OA\Response(response: 422, description: 'Parametre depot manquant.'),
        ],
    )]
    public function rayonsDisponibles(Request $request, X3ConnecteurInterface $connecteur, string $id): JsonResponse
    {
        try {
            $session = QueryModel::getQuerySessionInventaireMobile($request->user())
                ->where('id', $id)
                ->firstOrFail();

            $request->validate([
                'depot' => 'required|string',
            ]);

            $codeDepot = $request->string('depot')->toString();

            $rayonsX3 = $connecteur->recupererRayons($session->code_site, $codeDepot);

            $perimetresActifs = Perimetre::with('agentDeclarant')
                ->where('session_id', $session->id)
                ->where('code_depot', $codeDepot)
                ->whereIn('statut', Perimetre::STATUTS_ACTIFS)
                ->get();

            $occupationParRayon = [];
            foreach ($perimetresActifs as $perimetre) {
                foreach ($perimetre->codesRayons() as $codeRayon) {
                    $occupationParRayon[$codeRayon] = $perimetre;
                }
            }

            $rayons = array_map(function (array $rayonX3) use ($occupationParRayon) {
                $codeRayon = $rayonX3['code_rayon'] ?? null;
                $occupant = $occupationParRayon[$codeRayon] ?? null;

                return [
                    ...$rayonX3,
                    'disponible' => $occupant === null,
                    'occupe_par' => $occupant ? trim("{$occupant->agentDeclarant->prenom} {$occupant->agentDeclarant->nom}") : null,
                    'perimetre_id' => $occupant?->id,
                ];
            }, $rayonsX3);

            return response()->json(['data' => $rayons]);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }

    /**
     * Articles/lots theoriquement attendus sur un rayon, avant comptage --
     * enveloppe GET /rayons/:code/detail sur RererentielX3 (FRONTEND_CONTEXT.md
     * §2.1), jusqu'ici jamais branche cote Laravel : l'agent saisissait tout a
     * l'aveugle, sans reference theorique (qte_theorique_itu/stu toujours
     * null en pratique). Pagine cote X3, passthrough page/per_page.
     */
    #[OA\Get(
        path: '/api/sessions/{id}/expected-articles',
        summary: 'Articles/lots theoriquement attendus sur un rayon (referentiel X3)',
        description: 'Permet de preremplir code_article/nom_article/qte_theorique avant un comptage, plutot que de tout saisir a l\'aveugle.',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres (mobile)'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la session', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'depot', in: 'query', required: true, description: 'Code depot X3', schema: new OA\Schema(type: 'string', example: 'MC01')),
            new OA\Parameter(name: 'rayon', in: 'query', required: true, description: 'Code rayon X3', schema: new OA\Schema(type: 'string', example: '01A')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 200)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste paginee des lots theoriquement en stock sur ce rayon.',
                content: new OA\JsonContent(example: [
                    'data' => [
                        ['code_site' => 'MC01', 'code_depot' => 'MC01', 'code_emplacement' => 'MC01-01A-05', 'code_rayon' => '01A', 'code_article' => 'ART-001', 'designation_article' => 'Paracetamol 500mg', 'numero_lot' => 'NL2012503', 'date_peremption' => '2027-06-30', 'qte_stu' => 240, 'qte_disponible_stu' => 240, 'unite' => 'COMPRIME'],
                    ],
                    'pagination' => ['total' => 42, 'page' => 1, 'per_page' => 200],
                ]),
            ),
            new OA\Response(response: 404, description: 'Session introuvable ou agent non autorise sur cette session.'),
            new OA\Response(response: 422, description: 'Parametre depot ou rayon manquant.'),
            new OA\Response(response: 502, description: 'RererentielX3 injoignable ou en erreur.'),
        ],
    )]
    public function articlesAttendus(Request $request, X3ConnecteurInterface $connecteur, string $id): JsonResponse
    {
        try {
            $session = QueryModel::getQuerySessionInventaireMobile($request->user())
                ->where('id', $id)
                ->firstOrFail();
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }

        try {
            $request->validate([
                'depot' => 'required|string',
                'rayon' => 'required|string',
                'page' => 'integer|min:1',
                'per_page' => 'integer|min:1|max:500',
            ]);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        }

        try {
            $resultat = $connecteur->recupererDetailRayon(
                $session->code_site,
                $request->string('depot')->toString(),
                $request->string('rayon')->toString(),
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

    /**
     * Declare un perimetre (depot + rayons). Verification atomique de
     * disponibilite des rayons (doc fonctionnel §6.3/§9.1) via verrou
     * pessimiste sur la session, pour eviter que deux agents declarent le
     * meme rayon en concurrence. En cas de conflit, une tentative d'acces
     * refusee est enregistree automatiquement pour chaque rayon en cause.
     */
    #[OA\Post(
        path: '/api/perimeters',
        summary: 'Declarer un perimetre (depot + rayons)',
        description: 'Verification atomique de disponibilite. En cas de conflit (409), une tentative d\'acces refusee est enregistree automatiquement.',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres (mobile)'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['session_id', 'code_depot', 'codes_rayons'],
                properties: [
                    new OA\Property(property: 'session_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'code_depot', type: 'string', example: 'MC01'),
                    new OA\Property(property: 'codes_rayons', type: 'array', items: new OA\Items(type: 'string'), example: ['01A', '01B']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Perimetre declare.',
                content: new OA\JsonContent(example: [
                    'data' => ['id' => '019f...', 'session_id' => '019f...', 'code_depot' => 'MC01', 'statut' => 'DECLARED', 'declare_le' => '2026-07-15T16:13:08.000000Z', 'codes_rayons' => ['01A', '01B']],
                ]),
            ),
            new OA\Response(response: 400, description: 'La session n\'est pas dans un statut permettant de declarer.'),
            new OA\Response(
                response: 409,
                description: 'Un ou plusieurs rayons demandes sont deja occupes par un autre agent.',
                content: new OA\JsonContent(example: ['errors' => ['Rayon(s) deja occupe(s) par un autre agent : 01B.']]),
            ),
            new OA\Response(response: 404, description: 'Session introuvable ou agent non autorise.'),
        ],
    )]
    public function declarer(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|uuid|exists:sessions_inventaire,id',
            'code_depot' => 'required|string',
            'codes_rayons' => 'required|array|min:1',
            'codes_rayons.*' => 'string',
        ]);

        $agent = $request->user();

        try {
            $session = QueryModel::getQuerySessionInventaireMobile($agent)
                ->where('id', $request->input('session_id'))
                ->firstOrFail();
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }

        if (!in_array($session->statut, [SessionInventaire::STATUT_OPEN, SessionInventaire::STATUT_IN_PROGRESS], true)) {
            return Outils::reponseErreur(
                new Exception("La session n'est pas dans un statut permettant de declarer un perimetre (statut actuel : {$session->statut})."),
                400,
            );
        }

        $codesDemandes = $request->input('codes_rayons');
        $codeDepot = $request->string('code_depot')->toString();

        try {
            // Le verrou + la verification de conflit se font dans une transaction qui
            // ne cree le perimetre QUE si tout est libre ; en cas de conflit elle se
            // termine sans rien ecrire (pas de rollback a declencher). Les tentatives
            // d'acces sont ecrites APRES, hors transaction : elles doivent survivre
            // meme quand la declaration elle-meme echoue (doc fonctionnel §6.3).
            [$perimetre, $conflitsParRayon] = DB::transaction(function () use ($session, $agent, $codeDepot, $codesDemandes) {
                // Verrou pessimiste : serialise les declarations concurrentes sur cette session.
                SessionInventaire::where('id', $session->id)->lockForUpdate()->first();

                $perimetresActifs = Perimetre::where('session_id', $session->id)
                    ->whereIn('statut', Perimetre::STATUTS_ACTIFS)
                    ->get();

                $conflitsParRayon = [];
                foreach ($perimetresActifs as $perimetreActif) {
                    foreach (array_intersect($codesDemandes, $perimetreActif->codesRayons()) as $codeRayonConflit) {
                        $conflitsParRayon[$codeRayonConflit] = $perimetreActif->id;
                    }
                }

                if (!empty($conflitsParRayon)) {
                    return [null, $conflitsParRayon];
                }

                $perimetre = Perimetre::create([
                    'session_id' => $session->id,
                    'code_depot' => $codeDepot,
                    'statut' => Perimetre::STATUT_DECLARED,
                    'agent_declarant_id' => $agent->id,
                    'declare_le' => now(),
                ]);

                $perimetre->attacherRayons($codesDemandes);

                if ($session->statut === SessionInventaire::STATUT_OPEN) {
                    $session->update(['statut' => SessionInventaire::STATUT_IN_PROGRESS]);
                }

                return [$perimetre, []];
            });

            if ($perimetre === null) {
                foreach ($conflitsParRayon as $codeRayonConflit => $perimetreConflitId) {
                    $tentative = TentativeAccesPerimetre::create([
                        'session_id' => $session->id,
                        'code_depot' => $codeDepot,
                        'code_rayon' => $codeRayonConflit,
                        'agent_id' => $agent->id,
                        'perimetre_conflit_id' => $perimetreConflitId,
                        'tentee_le' => now(),
                    ]);

                    AuditService::log(AuditService::PERIMETRE_TENTATIVE_ACCES_REFUSEE, $tentative, [
                        'code_depot' => $codeDepot,
                        'code_rayon' => $codeRayonConflit,
                        'perimetre_conflit_id' => $perimetreConflitId,
                    ]);
                }

                $listeConflits = implode(', ', array_keys($conflitsParRayon));

                return Outils::reponseErreur(new Exception("Rayon(s) deja occupe(s) par un autre agent : {$listeConflits}."), 409);
            }

            AuditService::log(AuditService::PERIMETRE_DECLARATION, $perimetre, [
                'code_depot' => $codeDepot,
                'codes_rayons' => $codesDemandes,
            ]);

            return response()->json([
                'data' => [
                    ...$perimetre->fresh()->toArray(),
                    'codes_rayons' => $perimetre->codesRayons(),
                ],
            ], 201);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    /**
     * Liberation volontaire par l'agent proprietaire, avant soumission de sa
     * fiche (doc fonctionnel §3.2).
     */
    #[OA\Put(
        path: '/api/perimeters/{id}/release',
        summary: 'Liberation volontaire, avant soumission de la fiche',
        description: 'Seul l\'agent qui a declare le perimetre peut le liberer.',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres (mobile)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Perimetre libere (statut RELEASED_BY_AGENT).'),
            new OA\Response(response: 400, description: 'Pas le proprietaire, ou perimetre pas dans un statut actif.'),
        ],
    )]
    public function liberer(Request $request, string $id): JsonResponse
    {
        try {
            $perimetre = Perimetre::findOrFail($id);

            if ($perimetre->agent_declarant_id !== $request->user()->id) {
                throw new Exception("Seul l'agent ayant declare ce perimetre peut le liberer.");
            }

            if (!in_array($perimetre->statut, Perimetre::STATUTS_ACTIFS, true)) {
                throw new Exception("Ce perimetre n'est pas dans un statut actif (statut actuel : {$perimetre->statut}).");
            }

            $perimetre->update([
                'statut' => Perimetre::STATUT_RELEASED_BY_AGENT,
                'libere_le' => now(),
            ]);

            AuditService::log(AuditService::PERIMETRE_LIBERATION, $perimetre);

            return response()->json(['data' => $perimetre->fresh()]);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    /**
     * Emplacements/articles/lots a recompter, sans aucune quantite (ni
     * theorique ni comptee) -- comptage aveugle (doc fonctionnel,
     * FRONTEND_CONTEXT.md §3.5 : "l'agent de recomptage ne doit jamais voir
     * les valeurs du comptage initial pendant sa saisie"). Reserve a l'agent
     * effectivement assigne au recomptage de ce perimetre.
     */
    #[OA\Get(
        path: '/api/perimeters/{id}/recount-locations',
        summary: 'Liste des emplacements/articles/lots a recompter (sans quantites)',
        description: 'Reserve a l\'agent assigne au recomptage (recount_agent_id). Comptage aveugle : aucune quantite renvoyee.',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres (mobile)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste sans quantites, a recompter integralement.',
                content: new OA\JsonContent(example: [
                    'data' => [
                        ['ligne_initiale_id' => '019f...', 'code_article' => 'ART-001', 'nom_article' => 'Paracetamol 500mg', 'code_emplacement' => 'MC01-01A-05', 'numero_lot' => 'NL2012503', 'numero_lot_parent' => null, 'date_peremption' => '2027-06-30'],
                    ],
                ]),
            ),
            new OA\Response(response: 403, description: 'Agent non assigne au recomptage de ce perimetre.'),
            new OA\Response(response: 400, description: 'Le perimetre n\'est pas au statut RECOUNTING.'),
            new OA\Response(response: 404, description: 'Perimetre introuvable.'),
        ],
    )]
    public function recountLocations(Request $request, string $id): JsonResponse
    {
        try {
            $perimetre = Perimetre::findOrFail($id);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }

        if ($perimetre->recount_agent_id !== $request->user()->id) {
            return Outils::reponseErreur(new Exception("Vous n'etes pas l'agent assigne au recomptage de ce perimetre."), 403);
        }

        try {
            if ($perimetre->statut !== Perimetre::STATUT_RECOUNTING) {
                throw new Exception("Ce perimetre n'est pas au statut RECOUNTING (statut actuel : {$perimetre->statut}).");
            }

            $ficheInitiale = $perimetre->ficheInitiale();

            $lignes = $ficheInitiale
                ? $ficheInitiale->lignes->map(fn ($ligne) => [
                    'ligne_initiale_id' => $ligne->id,
                    'code_article' => $ligne->code_article,
                    'nom_article' => $ligne->nom_article,
                    'code_emplacement' => $ligne->code_emplacement,
                    'numero_lot' => $ligne->numero_lot,
                    'numero_lot_parent' => $ligne->numero_lot_parent,
                    'date_peremption' => $ligne->date_peremption,
                ])
                : collect();

            return response()->json(['data' => $lignes->values()]);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    /**
     * Enregistrement explicite d'une tentative d'acces refusee sur un
     * perimetre deja connu (en plus de l'enregistrement automatique fait par
     * declarer() en cas de conflit direct -- doc fonctionnel §6.3).
     */
    #[OA\Post(
        path: '/api/perimeters/{id}/access-attempt',
        summary: 'Enregistrement manuel d\'une tentative d\'acces refusee',
        description: 'Complement du 409 automatique de POST /api/perimeters. {id} = le perimetre qui occupe deja le rayon.',
        security: [['bearerAuth' => []]],
        tags: ['Perimetres (mobile)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID du perimetre en conflit', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['code_rayon'], properties: [new OA\Property(property: 'code_rayon', type: 'string', example: '01B')]),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tentative enregistree.'),
            new OA\Response(response: 404, description: 'Perimetre introuvable.'),
            new OA\Response(response: 422, description: 'code_rayon manquant.'),
        ],
    )]
    public function enregistrerTentativeAcces(Request $request, string $id): JsonResponse
    {
        try {
            $perimetre = Perimetre::findOrFail($id);

            $request->validate([
                'code_rayon' => 'required|string',
            ]);

            $tentative = TentativeAccesPerimetre::create([
                'session_id' => $perimetre->session_id,
                'code_depot' => $perimetre->code_depot,
                'code_rayon' => $request->string('code_rayon'),
                'agent_id' => $request->user()->id,
                'perimetre_conflit_id' => $perimetre->id,
                'tentee_le' => now(),
            ]);

            return response()->json(['data' => $tentative], 201);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }
}

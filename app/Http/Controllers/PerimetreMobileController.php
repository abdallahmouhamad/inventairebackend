<?php

namespace App\Http\Controllers;

use App\Models\Perimetre;
use App\Models\QueryModel;
use App\Models\SessionInventaire;
use App\Models\TentativeAccesPerimetre;
use App\Services\X3\X3ConnecteurInterface;
use App\Support\Outils;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Declaration/liberation de perimetre cote agent mobile (doc fonctionnel
 * §3.2/§6.3). Routes protegees par le middleware role.mobile.
 */
class PerimetreMobileController extends Controller
{
    /**
     * Rayons d'un depot avec leur disponibilite (doc fonctionnel §3.2 :
     * "L'agent voit les rayons disponibles et les rayons deja occupes par un
     * autre agent, avec son nom").
     */
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
     * Declare un perimetre (depot + rayons). Verification atomique de
     * disponibilite des rayons (doc fonctionnel §6.3/§9.1) via verrou
     * pessimiste sur la session, pour eviter que deux agents declarent le
     * meme rayon en concurrence. En cas de conflit, une tentative d'acces
     * refusee est enregistree automatiquement pour chaque rayon en cause.
     */
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
                    TentativeAccesPerimetre::create([
                        'session_id' => $session->id,
                        'code_depot' => $codeDepot,
                        'code_rayon' => $codeRayonConflit,
                        'agent_id' => $agent->id,
                        'perimetre_conflit_id' => $perimetreConflitId,
                        'tentee_le' => now(),
                    ]);
                }

                $listeConflits = implode(', ', array_keys($conflitsParRayon));

                return Outils::reponseErreur(new Exception("Rayon(s) deja occupe(s) par un autre agent : {$listeConflits}."), 409);
            }

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

            return response()->json(['data' => $perimetre->fresh()]);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    /**
     * Enregistrement explicite d'une tentative d'acces refusee sur un
     * perimetre deja connu (en plus de l'enregistrement automatique fait par
     * declarer() en cas de conflit direct -- doc fonctionnel §6.3).
     */
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

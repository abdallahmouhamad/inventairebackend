<?php

namespace App\Http\Controllers;

use App\Models\Utilisateur;
use App\Services\AuditService;
use App\Support\Outils;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Tymon\JWTAuth\Facades\JWTAuth;

#[OA\Tag(name: 'Auth', description: 'Authentification, commune Web et Mobile')]
class AuthController extends Controller
{
    /** Duree de vie du token Web Admin (session courte, coherente avec le sessionStorage front). */
    private const TTL_WEB_MINUTES = 120;

    /** Duree de vie du token mobile (reconnexion automatique tant que le token est valide). */
    private const TTL_MOBILE_MINUTES = 60 * 24 * 30;

    #[OA\Post(
        path: '/api/auth/login',
        summary: 'Connexion (Web ou Mobile selon "plateforme")',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'mot_de_passe', 'plateforme'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'operateur1@inventaire.sn'),
                    new OA\Property(property: 'mot_de_passe', type: 'string', example: 'demo2026'),
                    new OA\Property(property: 'plateforme', type: 'string', enum: ['web', 'mobile'], example: 'mobile'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Connexion reussie. Token valable 2h (web) ou 30 jours (mobile).',
                content: new OA\JsonContent(example: [
                    'data' => [
                        'token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
                        'utilisateur' => [
                            'id' => '019f...',
                            'prenom' => 'Ibrahima',
                            'nom' => 'Fall',
                            'email' => 'operateur1@inventaire.sn',
                            'role' => ['code' => 'OPERATOR', 'libelle' => 'Operateur terrain'],
                        ],
                    ],
                ]),
            ),
            new OA\Response(
                response: 401,
                description: "Identifiants invalides, compte desactive, ou role incompatible avec la plateforme demandee.",
                content: new OA\JsonContent(example: ['errors' => ["Ce compte n'a pas acces a l'application mobile."]]),
            ),
            new OA\Response(response: 422, description: 'Champs manquants ou invalides.'),
        ],
    )]
    public function login(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'mot_de_passe' => 'required|string',
                'plateforme' => 'required|in:web,mobile',
            ]);

            $utilisateur = Utilisateur::where('email', $request->email)->first();

            if (!$utilisateur || !Hash::check($request->mot_de_passe, $utilisateur->mot_de_passe)) {
                throw new Exception('Identifiants invalides.');
            }

            if (!$utilisateur->est_actif) {
                throw new Exception('Ce compte est desactive.');
            }

            $estWeb = $request->plateforme === 'web';

            if ($estWeb && !$utilisateur->isWebRole()) {
                throw new Exception("Ce compte n'a pas acces au Web Admin.");
            }

            if (!$estWeb && !$utilisateur->isMobileRole()) {
                throw new Exception("Ce compte n'a pas acces a l'application mobile.");
            }

            JWTAuth::factory()->setTTL($estWeb ? self::TTL_WEB_MINUTES : self::TTL_MOBILE_MINUTES);
            $token = JWTAuth::fromUser($utilisateur);

            $utilisateur->update(['derniere_connexion_le' => now()]);

            // A cet instant Auth::guard('api')->user() n'est pas encore lie a la
            // requete (login precede l'authentification) -- acteur passe explicitement.
            AuditService::log(AuditService::CONNEXION, $utilisateur, ['plateforme' => $request->plateforme], $utilisateur);

            return response()->json([
                'data' => [
                    'token' => $token,
                    'utilisateur' => $utilisateur->load('role'),
                ],
            ]);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 401);
        }
    }

    #[OA\Get(
        path: '/api/auth/me',
        summary: 'Profil du compte connecte',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profil complet, avec codes_sites (liste vide = acces a tous les sites).',
                content: new OA\JsonContent(example: [
                    'data' => [
                        'id' => '019f...',
                        'prenom' => 'Ibrahima',
                        'nom' => 'Fall',
                        'email' => 'operateur1@inventaire.sn',
                        'role' => ['code' => 'OPERATOR', 'libelle' => 'Operateur terrain'],
                        'codes_sites' => [],
                    ],
                ]),
            ),
            new OA\Response(response: 401, description: 'Token manquant, expire ou invalide.'),
        ],
    )]
    public function me(Request $request): JsonResponse
    {
        $utilisateur = $request->user()->load('role');

        return response()->json([
            'data' => [
                ...$utilisateur->toArray(),
                'codes_sites' => $utilisateur->codesSites(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/auth/logout',
        summary: 'Deconnexion (invalide le token cote serveur)',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Deconnexion reussie.', content: new OA\JsonContent(example: ['data' => true])),
            new OA\Response(response: 401, description: 'Token manquant, expire ou invalide.'),
        ],
    )]
    public function logout(): JsonResponse
    {
        try {
            AuditService::log(AuditService::DECONNEXION);

            JWTAuth::parseToken()->invalidate();

            return response()->json(['data' => true]);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 500);
        }
    }
}

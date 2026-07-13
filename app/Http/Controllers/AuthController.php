<?php

namespace App\Http\Controllers;

use App\Models\Utilisateur;
use App\Support\Outils;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    /** Duree de vie du token Web Admin (session courte, coherente avec le sessionStorage front). */
    private const TTL_WEB_MINUTES = 120;

    /** Duree de vie du token mobile (reconnexion automatique tant que le token est valide). */
    private const TTL_MOBILE_MINUTES = 60 * 24 * 30;

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

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->load('role', 'sites'),
        ]);
    }

    public function logout(): JsonResponse
    {
        try {
            JWTAuth::parseToken()->invalidate();

            return response()->json(['data' => true]);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 500);
        }
    }
}

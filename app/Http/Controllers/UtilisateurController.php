<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Utilisateur;
use App\Support\Outils;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD des comptes Web Admin. L'API PHP RererentielX3 n'expose pas les
 * utilisateurs (FRONTEND_CONTEXT.md §2.3) : leur gestion est entierement a
 * la charge de Laravel. Aucune permission "users.*" n'etant definie dans la
 * matrice RBAC documentee, l'acces est reserve a SUPER_ADMIN via
 * UtilisateurPolicy (voir sa doc-comment pour le raisonnement).
 */
class UtilisateurController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            $this->authorize('create', Utilisateur::class);

            $request->validate([
                'prenom' => 'required|string|max:255',
                'nom' => 'required|string|max:255',
                'email' => 'required|email|unique:utilisateurs,email',
                'mot_de_passe' => 'required|string|min:8',
                'role_code' => 'required|string|exists:roles,code',
                'codes_sites' => 'array',
                'codes_sites.*' => 'string',
            ]);

            $role = Role::where('code', $request->role_code)->firstOrFail();

            $utilisateur = DB::transaction(function () use ($request, $role) {
                $utilisateur = Utilisateur::create([
                    'prenom' => $request->prenom,
                    'nom' => $request->nom,
                    'email' => $request->email,
                    'mot_de_passe' => Hash::make($request->mot_de_passe),
                    'role_id' => $role->id,
                    'est_actif' => true,
                ]);

                $utilisateur->attacherSites($request->input('codes_sites', []));

                return $utilisateur;
            });

            return response()->json([
                'data' => $utilisateur->load('role'),
            ], 201);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $utilisateur = Utilisateur::findOrFail($id);

            $this->authorize('update', $utilisateur);

            $request->validate([
                'prenom' => 'sometimes|required|string|max:255',
                'nom' => 'sometimes|required|string|max:255',
                'email' => ['sometimes', 'required', 'email', Rule::unique('utilisateurs', 'email')->ignore($utilisateur->id)],
                'mot_de_passe' => 'sometimes|required|string|min:8',
                'role_code' => 'sometimes|required|string|exists:roles,code',
                'codes_sites' => 'array',
                'codes_sites.*' => 'string',
            ]);

            DB::transaction(function () use ($request, $utilisateur) {
                if ($request->filled('prenom')) {
                    $utilisateur->prenom = $request->prenom;
                }
                if ($request->filled('nom')) {
                    $utilisateur->nom = $request->nom;
                }
                if ($request->filled('email')) {
                    $utilisateur->email = $request->email;
                }
                if ($request->filled('mot_de_passe')) {
                    $utilisateur->mot_de_passe = Hash::make($request->mot_de_passe);
                }
                if ($request->filled('role_code')) {
                    $utilisateur->role_id = Role::where('code', $request->role_code)->firstOrFail()->id;
                }
                $utilisateur->save();

                if ($request->has('codes_sites')) {
                    DB::table('utilisateur_site')->where('utilisateur_id', $utilisateur->id)->delete();
                    $utilisateur->attacherSites($request->input('codes_sites', []));
                }
            });

            return response()->json([
                'data' => $utilisateur->fresh()->load('role'),
            ]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (ValidationException $e) {
            return Outils::reponseErreur($e, 422);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    /**
     * "Suppression" = desactivation (est_actif = false), jamais de DELETE SQL :
     * l'utilisateur peut etre reference ailleurs (sessions ouvertes_par...) et
     * la tracabilite est une exigence transverse des deux documents source.
     */
    public function desactiver(string $id): JsonResponse
    {
        try {
            $utilisateur = Utilisateur::findOrFail($id);

            $this->authorize('delete', $utilisateur);

            $utilisateur->update(['est_actif' => false]);

            return response()->json(['data' => true]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }

    public function reactiver(string $id): JsonResponse
    {
        try {
            $utilisateur = Utilisateur::findOrFail($id);

            $this->authorize('update', $utilisateur);

            $utilisateur->update(['est_actif' => true]);

            return response()->json(['data' => true]);
        } catch (AuthorizationException $e) {
            return Outils::reponseErreur($e, 403);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 400);
        }
    }
}

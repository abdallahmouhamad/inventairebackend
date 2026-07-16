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
use OpenApi\Attributes as OA;

/**
 * CRUD des comptes Web Admin. L'API PHP RererentielX3 n'expose pas les
 * utilisateurs (FRONTEND_CONTEXT.md §2.3) : leur gestion est entierement a
 * la charge de Laravel. Aucune permission "users.*" n'etant definie dans la
 * matrice RBAC documentee, l'acces est reserve a SUPER_ADMIN via
 * UtilisateurPolicy (voir sa doc-comment pour le raisonnement).
 */
#[OA\Tag(name: 'Utilisateurs', description: 'Gestion des comptes -- reserve SUPER_ADMIN')]
class UtilisateurController extends Controller
{
    #[OA\Post(
        path: '/api/utilisateurs',
        summary: 'Creer un compte (SUPER_ADMIN uniquement)',
        security: [['bearerAuth' => []]],
        tags: ['Utilisateurs'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['prenom', 'nom', 'email', 'mot_de_passe', 'role_code'],
                properties: [
                    new OA\Property(property: 'prenom', type: 'string', example: 'Agent'),
                    new OA\Property(property: 'nom', type: 'string', example: 'Terrain'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'agent.terrain@inventaire.sn'),
                    new OA\Property(property: 'mot_de_passe', type: 'string', minLength: 8, example: 'Passer2026'),
                    new OA\Property(property: 'role_code', type: 'string', enum: ['SUPER_ADMIN', 'INVENTORY_MANAGER', 'READONLY', 'OPERATOR', 'MOBILE_MANAGER'], example: 'OPERATOR'),
                    new OA\Property(property: 'codes_sites', type: 'array', items: new OA\Items(type: 'string'), description: "Uniquement pertinent pour INVENTORY_MANAGER, sinon []", example: []),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Compte cree.'),
            new OA\Response(response: 403, description: 'Acteur non SUPER_ADMIN.'),
            new OA\Response(response: 422, description: 'Validation echouee (email deja pris, mot de passe trop court, role_code inconnu...).'),
        ],
    )]
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

    #[OA\Put(
        path: '/api/utilisateurs/{id}',
        summary: 'Modifier un compte (SUPER_ADMIN uniquement)',
        security: [['bearerAuth' => []]],
        tags: ['Utilisateurs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            description: 'Tous les champs sont optionnels ; envoyer codes_sites remplace entierement la liste existante.',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'prenom', type: 'string'),
                    new OA\Property(property: 'nom', type: 'string'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'mot_de_passe', type: 'string', minLength: 8),
                    new OA\Property(property: 'role_code', type: 'string', enum: ['SUPER_ADMIN', 'INVENTORY_MANAGER', 'READONLY', 'OPERATOR', 'MOBILE_MANAGER']),
                    new OA\Property(property: 'codes_sites', type: 'array', items: new OA\Items(type: 'string')),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Compte mis a jour.'),
            new OA\Response(response: 403, description: 'Acteur non SUPER_ADMIN.'),
            new OA\Response(response: 422, description: 'Validation echouee.'),
        ],
    )]
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
    #[OA\Delete(
        path: '/api/utilisateurs/{id}',
        summary: 'Desactiver un compte (SUPER_ADMIN uniquement) -- pas une suppression reelle',
        security: [['bearerAuth' => []]],
        tags: ['Utilisateurs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Compte desactive (est_actif=false).', content: new OA\JsonContent(example: ['data' => true])),
            new OA\Response(response: 403, description: 'Acteur non SUPER_ADMIN, ou tentative de se desactiver soi-meme.'),
        ],
    )]
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

    #[OA\Put(
        path: '/api/utilisateurs/{id}/reactiver',
        summary: 'Reactiver un compte desactive (SUPER_ADMIN uniquement)',
        security: [['bearerAuth' => []]],
        tags: ['Utilisateurs'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Compte reactive.', content: new OA\JsonContent(example: ['data' => true])),
            new OA\Response(response: 403, description: 'Acteur non SUPER_ADMIN.'),
        ],
    )]
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

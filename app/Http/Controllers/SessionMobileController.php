<?php

namespace App\Http\Controllers;

use App\Models\QueryModel;
use App\Support\Outils;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sessions vues par l'app mobile -- acces et filtrage differents du Web
 * Admin (SessionInventaireController) : pas de scoping par site, mais par
 * agent autorise, et le statut IMPORTED_FROM_X3 n'est jamais visible
 * (FRONTEND_CONTEXT.md §3.3). Routes protegees par le middleware role.mobile
 * (symetrique de role.web).
 */
class SessionMobileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sessions = QueryModel::getQuerySessionInventaireMobile($request->user())->get();

        return response()->json(['data' => $sessions]);
    }

    /**
     * 404 (pas 403) si la session existe mais n'est pas accessible a cet
     * agent : evite de reveler l'existence de sessions hors de sa portee.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $session = QueryModel::getQuerySessionInventaireMobile($request->user())
                ->where('id', $id)
                ->firstOrFail();

            return response()->json(['data' => $session]);
        } catch (Exception $e) {
            return Outils::reponseErreur($e, 404);
        }
    }
}

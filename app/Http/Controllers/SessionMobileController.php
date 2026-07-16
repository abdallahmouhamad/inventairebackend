<?php

namespace App\Http\Controllers;

use App\Models\QueryModel;
use App\Support\Outils;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Sessions vues par l'app mobile -- acces et filtrage differents du Web
 * Admin (SessionInventaireController) : pas de scoping par site, mais par
 * agent autorise, et le statut IMPORTED_FROM_X3 n'est jamais visible
 * (FRONTEND_CONTEXT.md §3.3). Routes protegees par le middleware role.mobile
 * (symetrique de role.web).
 */
#[OA\Tag(name: 'Sessions (mobile)', description: 'Sessions accessibles a l\'agent connecte')]
class SessionMobileController extends Controller
{
    #[OA\Get(
        path: '/api/mobile/sessions',
        summary: 'Sessions ou l\'agent connecte est explicitement autorise',
        description: 'Ne renvoie jamais une session encore IMPORTED_FROM_X3.',
        security: [['bearerAuth' => []]],
        tags: ['Sessions (mobile)'],
        responses: [new OA\Response(response: 200, description: 'Liste (non paginee).')],
    )]
    public function index(Request $request): JsonResponse
    {
        $sessions = QueryModel::getQuerySessionInventaireMobile($request->user())->get();

        return response()->json(['data' => $sessions]);
    }

    /**
     * 404 (pas 403) si la session existe mais n'est pas accessible a cet
     * agent : evite de reveler l'existence de sessions hors de sa portee.
     */
    #[OA\Get(
        path: '/api/mobile/sessions/{id}',
        summary: 'Detail d\'une session accessible a l\'agent',
        security: [['bearerAuth' => []]],
        tags: ['Sessions (mobile)'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Detail de la session.'),
            new OA\Response(response: 404, description: 'Session introuvable ou agent non autorise (volontairement 404, pas 403).'),
        ],
    )]
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

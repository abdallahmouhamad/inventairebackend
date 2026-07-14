<?php

namespace App\Http\Middleware;

use App\Support\Outils;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Symetrique de EnsureWebRole : rejette les tokens SUPER_ADMIN/
 * INVENTORY_MANAGER/READONLY sur les routes reservees a l'app mobile
 * (doc fonctionnel §2.3 : "Les rôles SUPER_ADMIN, INVENTORY_MANAGER et
 * READONLY n'ont PAS accès à l'application mobile").
 */
class EnsureMobileRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if (!$utilisateur || !$utilisateur->isMobileRole()) {
            return Outils::reponseErreur(new Exception("Acces reserve a l'application mobile."), 403);
        }

        return $next($request);
    }
}

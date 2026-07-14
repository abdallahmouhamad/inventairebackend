<?php

namespace App\Http\Middleware;

use App\Support\Outils;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejette les tokens OPERATOR/MOBILE_MANAGER sur les routes reservees au Web
 * Admin -- verification centralisee (doc fonctionnel §8.1/§8.3), pas laissee
 * a la charge de chaque controleur.
 */
class EnsureWebRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if (!$utilisateur || !$utilisateur->isWebRole()) {
            return Outils::reponseErreur(new Exception('Acces reserve au Web Admin.'), 403);
        }

        return $next($request);
    }
}

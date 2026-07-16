<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Porte les attributs OpenAPI globaux (info, serveurs, schema de securite).
 * Ne contient aucune logique -- juste un point d'ancrage pour zircote/swagger-php,
 * qui scanne tout app/ (voir config/l5-swagger.php) a la recherche d'attributs #[OA\...].
 */
#[OA\Info(
    version: '1.0.0',
    title: 'BackInventaireX3 API',
    description: "API du systeme d'inventaire pharmaceutique connecte a Sage X3. "
        . "Deux familles de routes : Web Admin (roles SUPER_ADMIN / INVENTORY_MANAGER / READONLY) "
        . "et Mobile (roles OPERATOR / MOBILE_MANAGER) -- un token obtenu avec un role d'une famille "
        . "est rejete en 403 sur les routes de l'autre famille.",
)]
// L5_SWAGGER_CONST_HOST vient de config('l5-swagger...constants'), lui-meme lu depuis
// la variable d'env du meme nom -- jamais une URL en dur ici : un domaine fige dans le
// code a deja fait pointer "Try it out" vers le mauvais serveur lors d'un changement de
// domaine en production (le Swagger UI envoyait les requetes vers l'ancien serveur sans
// que personne ne s'en rende compte au premier abord).
#[OA\Server(
    url: L5_SWAGGER_CONST_HOST,
    description: 'Production',
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: "Token JWT obtenu via POST /api/auth/login. A envoyer dans le header "
        . "Authorization: Bearer <token> sur toutes les routes sauf /api/auth/login.",
)]
class OpenApiBase
{
    //
}

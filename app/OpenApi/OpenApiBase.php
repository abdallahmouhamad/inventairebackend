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
#[OA\Server(
    url: 'https://inventairebackend.erpsmartshop.com',
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

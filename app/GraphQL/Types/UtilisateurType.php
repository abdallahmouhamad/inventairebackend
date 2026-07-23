<?php

namespace App\GraphQL\Types;

use App\Models\Utilisateur;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class UtilisateurType extends GraphQLType
{
    protected $attributes = [
        'name' => 'Utilisateur',
        'description' => "Un utilisateur du Web Admin ou de l'application mobile",
        'model' => Utilisateur::class,
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::id()),
            ],
            'prenom' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'nom' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'email' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'est_actif' => [
                'type' => Type::nonNull(Type::boolean()),
            ],
            'derniere_connexion_le' => [
                'type' => Type::string(),
                'description' => 'Date ISO 8601 de la derniere connexion',
                'resolve' => fn (Utilisateur $utilisateur): ?string => $utilisateur->derniere_connexion_le?->toIso8601String(),
            ],
            'x3_utilisateur_id' => [
                'type' => Type::string(),
            ],
            'role' => [
                'type' => GraphQL::type('Role'),
                'resolve' => fn (Utilisateur $utilisateur) => $utilisateur->role,
            ],
            'codes_sites' => [
                'type' => Type::listOf(Type::string()),
                'description' => "Sites auxquels l'utilisateur est rattache (vide = tous les sites, cas INVENTORY_MANAGER uniquement)",
                'resolve' => fn (Utilisateur $utilisateur): array => $utilisateur->codesSites(),
            ],
        ];
    }
}

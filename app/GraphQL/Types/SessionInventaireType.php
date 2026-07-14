<?php

namespace App\GraphQL\Types;

use App\Models\SessionInventaire;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class SessionInventaireType extends GraphQLType
{
    protected $attributes = [
        'name' => 'SessionInventaire',
        'description' => "Session d'inventaire (module metier Web Admin, distincte des sessions natives X3)",
        'model' => SessionInventaire::class,
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
            'code' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'nom' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'code_site' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'statut' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'date_debut' => [
                'type' => Type::string(),
                'resolve' => fn (SessionInventaire $session): ?string => $session->date_debut?->toDateString(),
            ],
            'date_fin' => [
                'type' => Type::string(),
                'resolve' => fn (SessionInventaire $session): ?string => $session->date_fin?->toDateString(),
            ],
            'x3_session_id' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'importee_de_x3_le' => [
                'type' => Type::string(),
                'resolve' => fn (SessionInventaire $session): ?string => $session->importee_de_x3_le?->toIso8601String(),
            ],
            'ouverte_aux_agents_le' => [
                'type' => Type::string(),
                'resolve' => fn (SessionInventaire $session): ?string => $session->ouverte_aux_agents_le?->toIso8601String(),
            ],
            'ouverte_par' => [
                'type' => GraphQL::type('Utilisateur'),
                'resolve' => fn (SessionInventaire $session) => $session->ouvertePar,
            ],
            'total_lignes' => [
                'type' => Type::int(),
            ],
            'lignes_soumises' => [
                'type' => Type::int(),
            ],
            'lignes_validees' => [
                'type' => Type::int(),
            ],
        ];
    }
}

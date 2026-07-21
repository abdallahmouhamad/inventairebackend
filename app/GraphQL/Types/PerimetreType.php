<?php

namespace App\GraphQL\Types;

use App\Models\Perimetre;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class PerimetreType extends GraphQLType
{
    protected $attributes = [
        'name' => 'Perimetre',
        'description' => "Zone d'inventaire (depot + rayons) declaree par un agent mobile",
        'model' => Perimetre::class,
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
            'session' => [
                'type' => GraphQL::type('SessionInventaire'),
                'resolve' => fn (Perimetre $perimetre) => $perimetre->session,
            ],
            'code_depot' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'codes_rayons' => [
                'type' => Type::listOf(Type::string()),
                'resolve' => fn (Perimetre $perimetre): array => $perimetre->codesRayons(),
            ],
            'statut' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'agent_declarant' => [
                'type' => GraphQL::type('Utilisateur'),
                'resolve' => fn (Perimetre $perimetre) => $perimetre->agentDeclarant,
            ],
            'declare_le' => [
                'type' => Type::string(),
                'resolve' => fn (Perimetre $perimetre): ?string => $perimetre->declare_le?->toIso8601String(),
            ],
            'libere_le' => [
                'type' => Type::string(),
                'resolve' => fn (Perimetre $perimetre): ?string => $perimetre->libere_le?->toIso8601String(),
            ],
            'libere_par' => [
                'type' => GraphQL::type('Utilisateur'),
                'resolve' => fn (Perimetre $perimetre) => $perimetre->liberePar,
            ],
            'motif_liberation_forcee' => [
                'type' => Type::string(),
            ],
            'tentatives_acces' => [
                'type' => Type::listOf(GraphQL::type('TentativeAccesPerimetre')),
                'resolve' => fn (Perimetre $perimetre) => $perimetre->tentativesAcces,
            ],
            'motif_recomptage' => [
                'type' => Type::string(),
            ],
            'recount_agent' => [
                'type' => GraphQL::type('Utilisateur'),
                'resolve' => fn (Perimetre $perimetre) => $perimetre->recountAgent,
            ],
            'recount_requested_at' => [
                'type' => Type::string(),
                'resolve' => fn (Perimetre $perimetre): ?string => $perimetre->recount_requested_at?->toIso8601String(),
            ],
            'recount_submitted_at' => [
                'type' => Type::string(),
                'resolve' => fn (Perimetre $perimetre): ?string => $perimetre->recount_submitted_at?->toIso8601String(),
            ],
            'arbitrated_at' => [
                'type' => Type::string(),
                'resolve' => fn (Perimetre $perimetre): ?string => $perimetre->arbitrated_at?->toIso8601String(),
            ],
            'arbitre_par' => [
                'type' => GraphQL::type('Utilisateur'),
                'resolve' => fn (Perimetre $perimetre) => $perimetre->arbitrePar,
            ],
        ];
    }
}

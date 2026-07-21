<?php

namespace App\GraphQL\Types;

use App\Models\FicheComptage;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class FicheComptageType extends GraphQLType
{
    protected $attributes = [
        'name' => 'FicheComptage',
        'description' => "Fiche de comptage soumise par un agent, avec ses lignes",
        'model' => FicheComptage::class,
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
                'resolve' => fn (FicheComptage $fiche) => $fiche->session,
            ],
            'perimetre' => [
                'type' => GraphQL::type('Perimetre'),
                'resolve' => fn (FicheComptage $fiche) => $fiche->perimetre,
            ],
            'agent' => [
                'type' => GraphQL::type('Utilisateur'),
                'resolve' => fn (FicheComptage $fiche) => $fiche->agent,
            ],
            'statut' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'soumise_le' => [
                'type' => Type::string(),
                'resolve' => fn (FicheComptage $fiche): ?string => $fiche->soumise_le?->toIso8601String(),
            ],
            'commentaire_revision' => [
                'type' => Type::string(),
            ],
            'has_out_of_list_items' => [
                'type' => Type::nonNull(Type::boolean()),
                'resolve' => fn (FicheComptage $fiche): bool => $fiche->aDesArticlesHorsListe(),
            ],
            'has_lot_corrections' => [
                'type' => Type::nonNull(Type::boolean()),
                'resolve' => fn (FicheComptage $fiche): bool => $fiche->aDesCorrectionsDeLot(),
            ],
            'lignes' => [
                'type' => Type::listOf(GraphQL::type('LigneComptage')),
                'resolve' => fn (FicheComptage $fiche) => $fiche->lignes,
            ],
            'est_recomptage' => [
                'type' => Type::nonNull(Type::boolean()),
            ],
            'fiche_initiale' => [
                'type' => GraphQL::type('FicheComptage'),
                'resolve' => fn (FicheComptage $fiche) => $fiche->ficheInitiale,
            ],
        ];
    }
}

<?php

namespace App\GraphQL\Types;

use App\Models\LigneComptage;
use App\Services\CalculEcart;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class LigneComptageType extends GraphQLType
{
    protected $attributes = [
        'name' => 'LigneComptage',
        'description' => "Ligne de comptage d'une fiche : article/lot compte, avec ecart calcule",
        'model' => LigneComptage::class,
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
            'code_article' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'nom_article' => [
                'type' => Type::string(),
            ],
            'code_emplacement' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'numero_lot' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'numero_lot_parent' => [
                'type' => Type::string(),
            ],
            'date_peremption' => [
                'type' => Type::string(),
                'resolve' => fn (LigneComptage $ligne): ?string => $ligne->date_peremption?->toDateString(),
            ],
            'est_correction_lot' => [
                'type' => Type::nonNull(Type::boolean()),
            ],
            'est_hors_liste' => [
                'type' => Type::nonNull(Type::boolean()),
            ],
            'qte_theorique_itu' => [
                'type' => Type::int(),
            ],
            'qte_theorique_stu' => [
                'type' => Type::int(),
            ],
            'qte_comptee_itu' => [
                'type' => Type::nonNull(Type::int()),
            ],
            'qte_comptee_stu' => [
                'type' => Type::nonNull(Type::int()),
            ],
            'statut_review' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'commentaire_rejet' => [
                'type' => Type::string(),
            ],
            'ecart_itu' => [
                'type' => Type::int(),
                'resolve' => fn (LigneComptage $ligne): int => CalculEcart::pour($ligne)['itu'],
            ],
            'ecart_stu' => [
                'type' => Type::int(),
                'resolve' => fn (LigneComptage $ligne): int => CalculEcart::pour($ligne)['stu'],
            ],
            'ecart_percent' => [
                'type' => Type::float(),
                'resolve' => fn (LigneComptage $ligne): ?float => CalculEcart::pour($ligne)['percent'],
            ],
            'ecart_criticite' => [
                'type' => Type::string(),
                'description' => 'normale / avertissement / critique / inconnue',
                'resolve' => fn (LigneComptage $ligne): string => CalculEcart::pour($ligne)['criticite'],
            ],
            'ligne_appariee' => [
                'type' => GraphQL::type('LigneComptage'),
                'description' => 'Sur une ligne de recomptage uniquement : la ligne correspondante de la fiche initiale',
                'resolve' => fn (LigneComptage $ligne) => $ligne->ligneAppariee,
            ],
            'resultat_arbitrage' => [
                'type' => Type::string(),
                'description' => 'INITIALE / RECOMPTAGE, renseigne une fois l\'arbitrage tranche pour cette paire',
            ],
        ];
    }
}

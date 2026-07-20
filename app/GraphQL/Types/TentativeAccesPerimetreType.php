<?php

namespace App\GraphQL\Types;

use App\Models\TentativeAccesPerimetre;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class TentativeAccesPerimetreType extends GraphQLType
{
    protected $attributes = [
        'name' => 'TentativeAccesPerimetre',
        'description' => "Tentative de declaration refusee sur un rayon deja occupe",
        'model' => TentativeAccesPerimetre::class,
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
            'code_depot' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'code_rayon' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'agent' => [
                'type' => GraphQL::type('Utilisateur'),
                'resolve' => fn (TentativeAccesPerimetre $tentative) => $tentative->agent,
            ],
            'tentee_le' => [
                'type' => Type::string(),
                'resolve' => fn (TentativeAccesPerimetre $tentative): ?string => $tentative->tentee_le?->toIso8601String(),
            ],
            'resolue_le' => [
                'type' => Type::string(),
                'resolve' => fn (TentativeAccesPerimetre $tentative): ?string => $tentative->resolue_le?->toIso8601String(),
            ],
            'resolue_par' => [
                'type' => GraphQL::type('Utilisateur'),
                'resolve' => fn (TentativeAccesPerimetre $tentative) => $tentative->resoluePar,
            ],
        ];
    }
}

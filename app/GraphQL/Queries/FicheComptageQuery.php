<?php

namespace App\GraphQL\Queries;

use App\Models\FicheComptage;
use App\Models\QueryModel;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class FicheComptageQuery extends Query
{
    protected $attributes = [
        'name' => 'ficheComptage',
        'description' => 'Recupere une fiche de comptage par son identifiant',
    ];

    public function type(): Type
    {
        return GraphQL::type('FicheComptage');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function args(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::id()),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    public function resolve(mixed $root, array $args): FicheComptage
    {
        return QueryModel::getQueryFicheComptage($args)->firstOrFail();
    }
}

<?php

namespace App\GraphQL\Queries;

use App\Models\FicheComptage;
use App\Models\QueryModel;
use GraphQL\Type\Definition\Type;
use Illuminate\Database\Eloquent\Collection;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class FichesComptageQuery extends Query
{
    protected $attributes = [
        'name' => 'fichesComptage',
        'description' => 'Liste (non paginee) des fiches de comptage filtrees',
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('FicheComptage'));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function args(): array
    {
        return [
            'session_id' => [
                'type' => Type::id(),
            ],
            'statut' => [
                'type' => Type::string(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return Collection<int, FicheComptage>
     */
    public function resolve(mixed $root, array $args): Collection
    {
        return QueryModel::getQueryFicheComptage($args)->get();
    }
}

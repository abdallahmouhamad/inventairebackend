<?php

namespace App\GraphQL\Queries;

use App\Models\QueryModel;
use App\Support\Outils;
use GraphQL\Type\Definition\Type;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class FicheComptagePaginatedQuery extends Query
{
    protected $attributes = [
        'name' => 'ficheComptagePaginated',
        'description' => 'Liste paginee des fiches de comptage',
    ];

    public function type(): Type
    {
        return GraphQL::paginate('FicheComptage');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function args(): array
    {
        return Outils::paginationArgs() + [
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
     */
    public function resolve(mixed $root, array $args): LengthAwarePaginator
    {
        return QueryModel::getQueryFicheComptage($args)->paginate(
            $args['count'] ?? 15,
            ['*'],
            'page',
            $args['page'] ?? 1
        );
    }
}

<?php

namespace App\GraphQL\Queries;

use App\Models\QueryModel;
use App\Support\Outils;
use GraphQL\Type\Definition\Type;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class SessionInventairePaginatedQuery extends Query
{
    protected $attributes = [
        'name' => 'sessionInventairePaginated',
        'description' => "Liste paginee des sessions d'inventaire",
    ];

    public function type(): Type
    {
        return GraphQL::paginate('SessionInventaire');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function args(): array
    {
        return Outils::paginationArgs() + [
            'recherche' => [
                'type' => Type::string(),
            ],
            'code_site' => [
                'type' => Type::string(),
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
        return QueryModel::getQuerySessionInventaire($args, $root)->paginate(
            $args['count'] ?? 15,
            ['*'],
            'page',
            $args['page'] ?? 1
        );
    }
}

<?php

namespace App\GraphQL\Queries;

use App\Models\QueryModel;
use App\Support\Outils;
use GraphQL\Type\Definition\Type;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class PerimetrePaginatedQuery extends Query
{
    protected $attributes = [
        'name' => 'perimetrePaginated',
        'description' => 'Liste paginee des perimetres',
    ];

    public function type(): Type
    {
        return GraphQL::paginate('Perimetre');
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
        return QueryModel::getQueryPerimetre($args, $root)->paginate(
            $args['count'] ?? 15,
            ['*'],
            'page',
            $args['page'] ?? 1
        );
    }
}

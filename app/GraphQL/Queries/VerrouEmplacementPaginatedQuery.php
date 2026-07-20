<?php

namespace App\GraphQL\Queries;

use App\Models\QueryModel;
use App\Support\Outils;
use GraphQL\Type\Definition\Type;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class VerrouEmplacementPaginatedQuery extends Query
{
    protected $attributes = [
        'name' => 'verrouEmplacementPaginated',
        'description' => 'Liste paginee des verrous d\'emplacement',
    ];

    public function type(): Type
    {
        return GraphQL::paginate('VerrouEmplacement');
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
            'actifs_seulement' => [
                'type' => Type::boolean(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    public function resolve(mixed $root, array $args): LengthAwarePaginator
    {
        return QueryModel::getQueryVerrouEmplacement($args)->paginate(
            $args['count'] ?? 15,
            ['*'],
            'page',
            $args['page'] ?? 1
        );
    }
}

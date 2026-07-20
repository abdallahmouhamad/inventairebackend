<?php

namespace App\GraphQL\Queries;

use App\Models\Perimetre;
use App\Models\QueryModel;
use GraphQL\Type\Definition\Type;
use Illuminate\Database\Eloquent\Collection;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class PerimetresQuery extends Query
{
    protected $attributes = [
        'name' => 'perimetres',
        'description' => 'Liste (non paginee) des perimetres filtres',
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('Perimetre'));
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
     * @return Collection<int, Perimetre>
     */
    public function resolve(mixed $root, array $args): Collection
    {
        return QueryModel::getQueryPerimetre($args, $root)->get();
    }
}

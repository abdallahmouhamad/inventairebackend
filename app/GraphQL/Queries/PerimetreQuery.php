<?php

namespace App\GraphQL\Queries;

use App\Models\Perimetre;
use App\Models\QueryModel;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class PerimetreQuery extends Query
{
    protected $attributes = [
        'name' => 'perimetre',
        'description' => 'Recupere un perimetre par son identifiant',
    ];

    public function type(): Type
    {
        return GraphQL::type('Perimetre');
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
    public function resolve(mixed $root, array $args): Perimetre
    {
        return QueryModel::getQueryPerimetre($args, $root)->firstOrFail();
    }
}

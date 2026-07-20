<?php

namespace App\GraphQL\Queries;

use App\Models\QueryModel;
use App\Models\VerrouEmplacement;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class VerrouEmplacementQuery extends Query
{
    protected $attributes = [
        'name' => 'verrouEmplacement',
        'description' => 'Recupere un verrou d\'emplacement par son identifiant',
    ];

    public function type(): Type
    {
        return GraphQL::type('VerrouEmplacement');
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
    public function resolve(mixed $root, array $args): VerrouEmplacement
    {
        return QueryModel::getQueryVerrouEmplacement($args)->firstOrFail();
    }
}

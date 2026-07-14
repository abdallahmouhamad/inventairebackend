<?php

namespace App\GraphQL\Queries;

use App\Models\QueryModel;
use App\Models\SessionInventaire;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class SessionInventaireQuery extends Query
{
    protected $attributes = [
        'name' => 'sessionInventaire',
        'description' => "Recupere une session d'inventaire par son identifiant",
    ];

    public function type(): Type
    {
        return GraphQL::type('SessionInventaire');
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
    public function resolve(mixed $root, array $args): SessionInventaire
    {
        return QueryModel::getQuerySessionInventaire($args, $root)->firstOrFail();
    }
}

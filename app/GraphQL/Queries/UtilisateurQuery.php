<?php

namespace App\GraphQL\Queries;

use App\Models\QueryModel;
use App\Models\Utilisateur;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class UtilisateurQuery extends Query
{
    protected $attributes = [
        'name' => 'utilisateur',
        'description' => 'Recupere un utilisateur par son identifiant',
    ];

    public function type(): Type
    {
        return GraphQL::type('Utilisateur');
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
    public function resolve(mixed $root, array $args): Utilisateur
    {
        return QueryModel::getQueryUtilisateur($args, $root)->firstOrFail();
    }
}

<?php

namespace App\GraphQL\Queries;

use App\Models\QueryModel;
use App\Models\Utilisateur;
use GraphQL\Type\Definition\Type;
use Illuminate\Database\Eloquent\Collection;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class UtilisateursQuery extends Query
{
    protected $attributes = [
        'name' => 'utilisateurs',
        'description' => 'Liste (non paginee) des utilisateurs filtres',
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('Utilisateur'));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function args(): array
    {
        return [
            'recherche' => [
                'type' => Type::string(),
                'description' => 'Filtre sur prenom, nom ou email',
            ],
            'role_code' => [
                'type' => Type::string(),
                'description' => 'Filtre sur le code du role (ex: SUPER_ADMIN)',
            ],
            'est_actif' => [
                'type' => Type::boolean(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return Collection<int, Utilisateur>
     */
    public function resolve(mixed $root, array $args): Collection
    {
        return QueryModel::getQueryUtilisateur($args, $root)->get();
    }
}

<?php

namespace App\GraphQL\Queries;

use App\Models\QueryModel;
use App\Support\Outils;
use GraphQL\Type\Definition\Type;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class UtilisateurPaginatedQuery extends Query
{
    protected $attributes = [
        'name' => 'utilisateurs',
        'description' => 'Liste paginee des utilisateurs',
    ];

    public function type(): Type
    {
        return GraphQL::paginate('Utilisateur');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function args(): array
    {
        return Outils::paginationArgs() + [
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
     */
    public function resolve(mixed $root, array $args): LengthAwarePaginator
    {
        return QueryModel::getQueryUtilisateur($args, $root)->paginate(
            $args['count'] ?? 15,
            ['*'],
            'page',
            $args['page'] ?? 1
        );
    }
}

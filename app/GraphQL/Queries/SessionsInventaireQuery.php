<?php

namespace App\GraphQL\Queries;

use App\Models\QueryModel;
use App\Models\SessionInventaire;
use GraphQL\Type\Definition\Type;
use Illuminate\Database\Eloquent\Collection;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class SessionsInventaireQuery extends Query
{
    protected $attributes = [
        'name' => 'sessionsInventaire',
        'description' => "Liste (non paginee) des sessions d'inventaire filtrees",
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('SessionInventaire'));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function args(): array
    {
        return [
            'recherche' => [
                'type' => Type::string(),
                'description' => 'Filtre sur le code ou le nom',
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
     * @return Collection<int, SessionInventaire>
     */
    public function resolve(mixed $root, array $args): Collection
    {
        return QueryModel::getQuerySessionInventaire($args, $root)->get();
    }
}

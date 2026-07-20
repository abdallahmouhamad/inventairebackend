<?php

namespace App\GraphQL\Queries;

use App\Models\QueryModel;
use App\Models\VerrouEmplacement;
use GraphQL\Type\Definition\Type;
use Illuminate\Database\Eloquent\Collection;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Query;

class VerrousEmplacementQuery extends Query
{
    protected $attributes = [
        'name' => 'verrousEmplacement',
        'description' => 'Liste (non paginee) des verrous d\'emplacement filtres',
    ];

    public function type(): Type
    {
        return Type::listOf(GraphQL::type('VerrouEmplacement'));
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
            'actifs_seulement' => [
                'type' => Type::boolean(),
                'description' => 'Ne renvoyer que les verrous encore actifs (libere_le null)',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return Collection<int, VerrouEmplacement>
     */
    public function resolve(mixed $root, array $args): Collection
    {
        return QueryModel::getQueryVerrouEmplacement($args)->get();
    }
}

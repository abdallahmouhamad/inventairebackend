<?php

namespace App\GraphQL\Types;

use App\Models\Role;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Type as GraphQLType;

class RoleType extends GraphQLType
{
    protected $attributes = [
        'name' => 'Role',
        'description' => "Un role applicatif (OPERATOR, MOBILE_MANAGER, SUPER_ADMIN, INVENTORY_MANAGER, READONLY)",
        'model' => Role::class,
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::id()),
            ],
            'code' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Code fixe du role (ex: SUPER_ADMIN)',
            ],
            'libelle' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Libelle affichable du role',
            ],
        ];
    }
}

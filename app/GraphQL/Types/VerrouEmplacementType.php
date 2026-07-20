<?php

namespace App\GraphQL\Types;

use App\Models\VerrouEmplacement;
use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Type as GraphQLType;

class VerrouEmplacementType extends GraphQLType
{
    protected $attributes = [
        'name' => 'VerrouEmplacement',
        'description' => "Verrou sur un emplacement precis, a l'interieur d'un perimetre, pendant la saisie active",
        'model' => VerrouEmplacement::class,
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
            'session' => [
                'type' => GraphQL::type('SessionInventaire'),
                'resolve' => fn (VerrouEmplacement $verrou) => $verrou->session,
            ],
            'perimetre' => [
                'type' => GraphQL::type('Perimetre'),
                'resolve' => fn (VerrouEmplacement $verrou) => $verrou->perimetre,
            ],
            'code_depot' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'code_rayon' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'code_emplacement' => [
                'type' => Type::nonNull(Type::string()),
            ],
            'agent' => [
                'type' => GraphQL::type('Utilisateur'),
                'resolve' => fn (VerrouEmplacement $verrou) => $verrou->agent,
            ],
            'verrouille_le' => [
                'type' => Type::string(),
                'resolve' => fn (VerrouEmplacement $verrou): ?string => $verrou->verrouille_le?->toIso8601String(),
            ],
            'derniere_activite_le' => [
                'type' => Type::string(),
                'resolve' => fn (VerrouEmplacement $verrou): ?string => $verrou->derniere_activite_le?->toIso8601String(),
            ],
            'obsolete' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Calcule a la volee selon le seuil configure -- jamais stocke.',
                'resolve' => fn (VerrouEmplacement $verrou): bool => $verrou->estObsolete(),
            ],
            'libere_le' => [
                'type' => Type::string(),
                'resolve' => fn (VerrouEmplacement $verrou): ?string => $verrou->libere_le?->toIso8601String(),
            ],
            'libere_par' => [
                'type' => GraphQL::type('Utilisateur'),
                'resolve' => fn (VerrouEmplacement $verrou) => $verrou->liberePar,
            ],
            'force_libere' => [
                'type' => Type::nonNull(Type::boolean()),
            ],
            'motif_liberation_forcee' => [
                'type' => Type::string(),
            ],
        ];
    }
}

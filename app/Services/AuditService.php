<?php

namespace App\Services;

use App\Models\EntreeAudit;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Point d'entree unique pour ecrire dans le journal d'audit (doc fonctionnel
 * §6.8) : "centraliser la creation des entrees d'audit dans un service
 * unique ... appele systematiquement par chaque service metier qui modifie
 * une donnee, plutot que de laisser chaque controleur le faire de facon
 * dispersee et inconsistante." Un controleur ne doit jamais creer une
 * EntreeAudit directement -- toujours passer par log().
 */
class AuditService
{
    public const CONNEXION = 'connexion';

    public const DECONNEXION = 'deconnexion';

    public const SESSION_OUVERTURE = 'session.ouverture';

    public const SESSION_SYNCHRONISATION_X3 = 'session.synchronisation_x3';

    public const PERIMETRE_DECLARATION = 'perimetre.declaration';

    public const PERIMETRE_LIBERATION = 'perimetre.liberation';

    public const PERIMETRE_LIBERATION_FORCEE = 'perimetre.liberation_forcee';

    public const PERIMETRE_TENTATIVE_ACCES_REFUSEE = 'perimetre.tentative_acces_refusee';

    public const LIGNE_APPROBATION = 'ligne_comptage.approbation';

    public const LIGNE_REJET = 'ligne_comptage.rejet';

    public const LIGNE_REINITIALISATION = 'ligne_comptage.reinitialisation';

    public const FICHE_SOUMISSION = 'fiche_comptage.soumission';

    public const FICHE_VALIDATION = 'fiche_comptage.validation';

    public const FICHE_RENVOI_REVISION = 'fiche_comptage.renvoi_revision';

    public const VERROU_LIBERATION_FORCEE = 'verrou_emplacement.liberation_forcee';

    /**
     * @param array<string, mixed> $metadonnees
     */
    public static function log(string $action, ?Model $cible = null, array $metadonnees = [], ?Utilisateur $acteur = null): void
    {
        $acteur ??= Auth::guard('api')->user();

        if (!$acteur) {
            return;
        }

        EntreeAudit::create([
            'acteur_id' => $acteur->id,
            'action' => $action,
            'cible_type' => $cible ? $cible->getMorphClass() : null,
            'cible_id' => $cible?->getKey(),
            'metadonnees' => $metadonnees,
        ]);
    }
}

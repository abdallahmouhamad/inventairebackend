# Contexte Frontend — pour implémentation du Backend

> Document technique généré à partir de l'analyse exhaustive du code source du Web Admin.
> Objectif : donner au développeur backend (Laravel) tout ce qu'il doit savoir sur ce qui existe
> côté front pour construire une API qui matche exactement le contrat attendu — modèles de
> données, règles métier, machines à états, permissions, et endpoints implicites.
>
> **Mise à jour majeure** : depuis la première version de ce document, le front s'est connecté à
> une **vraie API PHP externe** ("RéférentielX3") qui expose en lecture les données natives de
> Sage X3. Ce n'est **pas** le backend Laravel à construire — c'est une source de données tierce
> déjà en place. Le §1 ci-dessous explique précisément qui fait quoi entre ces deux backends.

**État du front** : le module métier (sessions/submissions/perimeters/sync/locks/audit/settings)
reste une maquette **mockée** en Zustand + `localStorage`, exactement comme avant. Ce qui est
**nouveau et réel** : une couche `services/` qui interroge en HTTP une API PHP existante pour
consulter en lecture seule le référentiel Sage X3 (sites/dépôts/rayons/emplacements/stocks) et les
sessions/listes/lignes d'inventaire natives de X3. Les deux couches coexistent aujourd'hui **sans
être reliées entre elles**.

Stack front : React 19, TypeScript strict, Vite 8, Zustand (+persist), React Router v6,
TanStack Table v8, Recharts, date-fns, lucide-react, CSS Modules. Aucune nouvelle dépendance
ajoutée pour la couche API (fetch natif).

---

## 1. Architecture — trois systèmes, pas deux

```
┌──────────────────────────┐
│   Sage X3 ERP (SQL Server)│
└─────────────┬─────────────┘
              │ lecture directe (vues/requêtes SQL)
              ▼
┌───────────────────────────────────────┐        ┌──────────────────────────────┐
│  API PHP "RéférentielX3"               │        │   Backend Laravel (À CONSTRUIRE) │
│  DÉJÀ EN PLACE — existe indépendamment │        │   auth, workflow métier web,    │
│  du backend Laravel.                   │        │   orchestration PUSH/PULL X3,   │
│  Lecture seule, expose sites/dépôts/   │        │   audit, settings persistés     │
│  rayons/emplacements/stocks/sessions   │◄───────┤   (appellera probablement cette │
│  X3 natives en JSON.                   │  PULL  │   API PHP pour rapatrier les    │
│  Base configurée via VITE_API_BASE_URL │        │   données X3 plutôt que de      │
│  (ex: http://localhost:9090/           │        │   parler SQL Server en direct)  │
│  referentielx3)                        │        │                                  │
└───────────────┬───────────────────────┘        └───────────────┬──────────────────┘
                │ HTTP direct (aujourd'hui)                       │ HTTP (à câbler)
                ▼                                                  ▼
        ┌────────────────────────────────────────────────────────────┐
        │                     Web Admin React (CE PROJET)              │
        │  services/referenceService.ts, sessionService.ts  → API PHP  │
        │  stores/* (Zustand, mockés)                       → Laravel  │
        └────────────────────────────────────────────────────────────┘
```

**Point essentiel pour le développeur backend Laravel** : il existe déjà une API PHP tierce en
lecture seule sur les données X3 (référentiel + sessions natives). Le backend Laravel n'a
**probablement pas besoin de réimplémenter l'accès à SQL Server** pour ces données — il peut soit
consommer cette API PHP existante (proxy/orchestration), soit s'en inspirer pour le contrat de
données. En revanche, tout ce qui est **écriture / workflow métier web** (auth, review des
fiches, périmètres, recomptage, arbitrage, verrous, synchronisation PUSH, audit, paramètres,
gestion des utilisateurs web) reste entièrement à construire côté Laravel — rien de tout cela
n'existe encore en HTTP réel, uniquement en mock Zustand.

### 1.1 Bascule mock ↔ API réelle

Fichier `.env.example` :
```
VITE_API_BASE_URL=http://localhost:9090/referentielx3
VITE_USE_MOCK_API=true
```
- `VITE_USE_MOCK_API=true` (valeur par défaut livrée) : `referenceService`, `sessionService` et
  `settingsService` lisent des fichiers JSON locaux (`src/mocks/reference.json`,
  `src/mocks/settings.json`) sans aucun appel réseau.
- `VITE_USE_MOCK_API=false` : ces services appellent réellement `VITE_API_BASE_URL` via
  `services/apiService.ts` (client `fetch` centralisé, gère le token Bearer en
  `sessionStorage['auth_token']`, redirige vers `/login` sur 401).
- ⚠️ **Piège actuel à corriger côté Laravel** : `settingsService` (GET/PUT `/settings`) est câblé
  sur le **même** `VITE_API_BASE_URL` que le référentiel PHP — donc aujourd'hui, en mode réel, un
  appel `PUT /settings` partirait vers l'API PHP RéférentielX3, qui n'a aucune raison de l'exposer.
  Il faudra très probablement **une seconde variable d'env** (ex: `VITE_LARAVEL_API_BASE_URL`)
  pour distinguer les deux backends, et rebrancher `settingsService` (et tout futur service
  auth/submissions/perimeters/locks/sync/audit) sur l'URL du Laravel. Le composant
  `src/debug/ApiDebug.tsx` (badge flottant bas-droite en dev) affiche l'URL/mode actifs — utile
  pour vérifier le câblage pendant l'intégration.
- Le mock de `sessionService` renvoie des tableaux/objets **vides** (pas de vraies données de
  démo) — les écrans "Sessions X3" ne sont donc exploitables qu'avec l'API PHP réelle démarrée, ou
  à connecter au backend Laravel une fois prêt.

---

## 2. API PHP "RéférentielX3" — contrat observé (lecture seule, déjà existante)

Toutes les réponses suivent cette enveloppe (`PhpApiResponse<T>`, `src/types/phpApi.ts`) :
```ts
{ success: boolean; message: string; data: T; timestamp?: string }
```
Les endpoints paginés ajoutent un objet `pagination: { total, page, per_page }` en dehors de
`data` (vu sur `/rayons/:code/detail` et `/listes/:numero/lignes` — le front lit
`res.pagination?.total` etc., avec fallback si absent).

Les champs renvoyés sont des **noms X3 bruts en snake_case français** (`code_site`, `nom_site`,
`numero_session`, `statut`, `libelle_statut`...), souvent en `string` même pour des nombres —
le front les normalise systématiquement côté `services/*.ts` (`toNum()`, `cleanStr()`,
`cleanDate()`). Le backend Laravel doit connaître ce glossaire brut s'il consomme cette API.

### 2.1 Référentiel (sites, dépôts, rayons, emplacements, stock)

| Endpoint | Query params | Champs bruts retournés | Usage front |
|---|---|---|---|
| `GET /sites` | — | `code_site, nom_site, abreviation, code_pays` | arbre référentiel, filtres site |
| `GET /depots` | `?site=` | `code_depot, code_site, nom_depot` | arbre référentiel (chargé eager au démarrage avec sites) |
| `GET /emplacements` | `?depot=` | `code_emplacement, code_depot` | **attention** : utilisé aujourd'hui pour peupler le type interne `Aisle` (voir §2.4 — confusion terminologique à clarifier) |
| `GET /rayons` | `?site=&depot=` | `code_site, nom_site, code_depot, nom_depot, code_rayon, nb_emplacements, nb_emplacements_verrouilles` | arbre référentiel, chargé **à la demande** (lazy) quand l'utilisateur déplie un dépôt |
| `GET /rayons/:code/stock` | `?site=&depot=` | `code_rayon, code_depot, code_site, nb_articles, nb_lots, qte_totale_stu, qte_disponible_stu, nb_lots_perimes, nb_lots_epuises` | KPIs résumé stock par rayon |
| `GET /rayons/:code/detail` | `?site=&depot=&page=&per_page=` (pagination) | liste de lots : `code_site, code_depot, code_emplacement, code_rayon, code_article, designation_article, famille_article, numero_lot, date_peremption, qte_pcu, qte_stu, qte_disponible_stu, unite, coefficient, statut_dluo, verrouille_inventaire, numero_liste_inventaire` | page détail rayon (`RayonDetailPage`) — tableau paginé des lots en stock |
| `GET /emplacements/:code/stock` | `?site=&depot=` | `code_site, code_depot, code_emplacement, code_rayon, nb_articles, nb_lots, qte_totale_stu, qte_allouee_stu, qte_disponible_stu, nb_lots_perimes, nb_lots_epuises` | KPI stock par emplacement (préparé côté service, pas encore branché à une page dédiée) |

`statut_dluo` (statut de péremption d'un lot) est un enum déjà propre côté API :
`'OK' | 'EXPIRE_BIENTOT' | 'PERIME' | 'SANS_DLUO'`.

`verrouille_inventaire` / `verrouille` : `"1"`/autre (string) — signale qu'un lot ou une liste est
actuellement bloqué par un inventaire en cours dans X3 (empêche les mouvements de stock
concurrents). Impacte l'UI (bannière d'alerte, badge cadenas).

**⚠️ Incohérence de modèle à trancher avec le backend / l'équipe X3** : le front a deux notions
qui se chevauchent :
- `Aisle`/`Location` (types historiques `src/types/site.ts`, mockés) — hiérarchie
  Site→Dépôt→**Allée**→**Emplacement** à 4 niveaux, utilisée par l'ancien module Sessions/
  Submissions mocké.
- `Rayon` (nouveau, réel, `services/referenceService.ts`) — hiérarchie Site→Dépôt→**Rayon**
  (avec compteurs d'emplacements), sans niveau "Allée" séparé — le commentaire dans le code dit
  explicitement *"L'API PHP expose /emplacements — pas de niveau rayon séparé"*, alors qu'il
  existe pourtant un vrai endpoint `/rayons` distinct. Le mapping exact entre "Allée" (ancien
  concept mocké) et "Rayon" (nouveau concept réel X3) **n'est pas encore clarifié dans le code** —
  à confirmer avec l'équipe métier/X3 avant de figer le modèle de données Laravel définitif pour
  le référentiel géographique.

**Performance / contrainte de chargement** : le commentaire dans `hooks/useReference.ts` précise
que le référentiel complet des emplacements peut atteindre **~9593 items** — en conséquence :
- Le chargement initial (`useReference`) ne récupère que `sites + depots + users`.
- Les rayons sont chargés **à la demande** dépôt par dépôt (`loadAislesForDepot` /
  `referenceService.getRayons(siteId, depotId)`), jamais globalement.
- Le détail du stock d'un rayon (`getStockRayonDetail`) est **paginé** côté serveur
  (`page`, `per_page`, défaut 100/page).
- **Le backend Laravel doit impérativement conserver cette pagination/lazy-loading** sur tout
  endpoint qui expose des emplacements ou du stock détaillé — un endpoint qui renverrait tout
  d'un coup casserait les pages concernées.

### 2.2 Sessions / Listes / Lignes natives X3 (lecture seule — écran "Sessions X3")

Ceci est un **explorateur en lecture** des sessions d'inventaire telles qu'elles existent
nativement dans Sage X3 — distinct du module "Sessions" métier du Web Admin (voir §2.4 pour la
distinction critique).

| Endpoint | Query params | Usage |
|---|---|---|
| `GET /sessions` | `?site=&statut=` | `SessionsX3Page` (`/sessions-x3`) — liste + filtres |
| `GET /sessions/:numero_session` | — | en-tête de `SessionX3DetailPage` |
| `GET /sessions/:numero_session/listes` | — | tableau des listes de comptage de la session |
| `GET /listes/:numero_liste` | — | en-tête de `ListeDetailPage` |
| `GET /listes/:numero_liste/lignes` | `?page=&per_page=` (défaut 200/page) | lignes de comptage — **chargement progressif multi-pages côté client** (voir ci-dessous) |

Champs bruts session (`PhpSession`) : `numero_session, description, type_session, mode_session,
statut, libelle_statut, date_session, code_site, nom_site, depot_debut, depot_fin,
emplacement_debut, emplacement_fin, article_debut, article_fin, nb_articles_max, nb_lignes_max,
createur, date_creation, modificateur, date_modification`.

Champs bruts liste (`PhpListe`) : `numero_session, numero_liste, description, date_liste,
code_site, nom_site, code_depot, nom_depot, statut, libelle_statut, date_statut, verrouille,
nb_lignes, agent_assigné (⚠️ clé accentuée avec caractère é), date_import,
derniere_date_import, type_mouvement, createur, date_creation, modificateur, date_modification`.

Champs bruts ligne (`PhpLigne`) : `numero_session, numero_liste, numero_ligne, code_site,
code_depot, code_emplacement, code_article, designation_article, famille_article, numero_lot,
statut_stock, unite, coefficient, qte_theorique_pcu, qte_theorique_stu, qte_comptee_pcu,
qte_comptee_stu, est_comptee, date_peremption, statut_ligne, libelle_statut_ligne, verrouille,
date_comptage`.

**Dates SQL Server nulles** : le front nettoie explicitement les valeurs sentinelles
`1753-01-01` et `1899-12-31` (dates "nulles" typiques de SQL Server/anciens systèmes) en `null`
(`cleanDate()` dans `sessionService.ts`). Le backend Laravel doit prévoir le même nettoyage s'il
relaie ces données brutes.

**Codes de statut observés côté UI (partiels, à faire confirmer/lister exhaustivement par
l'équipe X3 — le front ne connaît aujourd'hui que ce qui est câblé en dur dans les filtres et
badges)** :
- `Session.statut` : `1` = "En préparation" (icône horloge, badge actif), `2` = "Terminée"
  (icône check). Seules ces deux valeurs sont proposées dans le filtre de `SessionsX3Page` — la
  liste complète des statuts possibles doit être demandée au référentiel X3/backend.
- `Liste.statut` : `5` = "Terminée" (check), `<= 2` = "Active" (horloge), autre = neutre.
- `Ligne.statut_ligne` : `3` ou `5` = traité/comptée (check vert), `4` = "en attente" (horloge),
  autre = neutre. Filtre UI propose `3` (Comptée), `4` (En attente), `5` (Terminée).
- ⚠️ Ces mappings numériques sont **déduits empiriquement de l'UI actuelle**, pas d'une
  documentation X3 formelle — à faire valider/compléter avec le backend avant de bâtir une logique
  serveur dessus (ex: calcul de KPIs, transitions).

**Chargement progressif des lignes** (`ListeDetailPage`) : la page charge la 1ère page (200
lignes), l'affiche immédiatement, puis enchaîne automatiquement les pages suivantes en tâche de
fond jusqu'à tout charger, avec une barre de progression (`loaded / total`). Le backend doit donc
garantir que `pagination.total` est fiable dès la première réponse pour que ce calcul de
progression fonctionne.

### 2.3 Ce que l'API PHP ne fournit PAS (comblé par mock en attendant Laravel)

- **Utilisateurs Web Admin** (`referenceService.getUsers()`) : le commentaire dans le code est
  explicite — *"Les utilisateurs Web Admin sont gérés par le backend Laravel — l'API PHP ne les
  expose pas — mocks conservés jusqu'à ce que Laravel soit prêt"*. Toujours lu depuis
  `mocks/reference.json` quel que soit le mode. **C'est donc un endpoint Laravel à créer en
  priorité** (`GET /users` côté web, avec `role`, `siteIds`, etc. — voir type `WebUser` §2.4).
- **Locations/emplacements détaillés par allée** (`getLocations`) : en mode réel, renvoie
  toujours un tableau vide — non exposé par l'API PHP dans le modèle actuel (remplacé par le
  concept `Rayon`).

### 2.4 Les DEUX notions de "Session" à ne pas confondre

C'est le point le plus important à comprendre pour le backend :

1. **`Session` (module métier Web Admin, mocké, store `sessionsStore`)** — l'entité que le
   responsable d'inventaire pilote dans le Web Admin : ouverture aux agents, suivi des fiches
   soumises, validation, synchronisation. Machine à états propre :
   `IMPORTED_FROM_X3 → OPEN → IN_PROGRESS → SIGNED → PENDING_SYNC → SYNCING → SYNCED_TO_X3 /
   SYNC_FAILED` (détaillée au §4.3 ci-dessous). Possède un champ `x3SessionId` censé référencer la
   session X3 d'origine — **mais ce lien n'est câblé nulle part dans le code actuel** (le mock
   `x3SessionId` est une chaîne arbitraire, jamais rapprochée d'un vrai `numero_session`).
   Routes : `/sessions`, `/sessions/:id/overview|agents|submissions|history`.

2. **`SessionX3` (nouveau, réel, lecture seule, service `sessionService`)** — la session
   d'inventaire telle qu'elle existe **nativement dans Sage X3** (`numero_session`), avec ses
   propres listes de comptage (`ListeX3`) et lignes (`LigneX3`), consultée en direct via l'API PHP
   RéférentielX3. Purement un écran d'exploration/consultation aujourd'hui — aucune action
   (ouverture, validation...) n'y est possible. Routes : `/sessions-x3`,
   `/sessions-x3/:numeroSession`, `/sessions-x3/:numeroSession/listes/:numeroListe`.

**Ce que cela implique pour le backend Laravel** : l'un des travaux d'orchestration centraux sera
probablement de **relier ces deux mondes** — par exemple, un PULL depuis X3 (via l'API PHP ou en
direct) qui crée/synchronise une `Session` métier Web Admin à partir d'une `SessionX3` détectée,
en peuplant correctement `x3SessionId`. Ce mapping n'existe pas encore et doit être conçu.

---

## 3. Modèle de données du module métier mocké (inchangé, toujours à implémenter en Laravel)

Cette section reprend telle quelle la logique déjà documentée précédemment — **aucune régression
ni changement de contrat** n'a été introduit sur cette partie par les derniers commits, elle est
toujours 100% mockée en Zustand/`localStorage`.

### 3.1 User / Auth

```ts
type UserRole = 'OPERATOR' | 'MOBILE_MANAGER' | 'SUPER_ADMIN' | 'INVENTORY_MANAGER' | 'READONLY';

type User = {
  id: string; email: string; firstName: string; lastName: string;
  role: UserRole;
  siteIds: string[];          // sites autorisés ; vide = tous les sites (SUPER_ADMIN)
  isActive: boolean;
  lastLoginAt?: string;
  x3UserId?: string;          // mapping vers profil utilisateur X3
};

type AuthUser = User & { currentSiteId?: string };
```
- Rôles mobile (`OPERATOR`, `MOBILE_MANAGER`) : aucun accès web — à rejeter côté API si tentative
  de login web (`isWebRole()`).
- Comptes de démo actuels (mock, mot de passe unique `demo2026`) — domaine **`@inventaire.com`**
  (le README racine mentionne `@pna.sn`, mais c'est le code source qui fait foi) :

| Email | Rôle | Accès |
|---|---|---|
| `admin@inventaire.com` | SUPER_ADMIN | tous sites |
| `inv.mcd@inventaire.com` | INVENTORY_MANAGER | site-1 (Magasin Central Dakar) |
| `inv.dkr@inventaire.com` | INVENTORY_MANAGER | site-2 (PRA Dakar) |
| `audit@inventaire.com` | READONLY | tous sites (lecture seule) |

- Persistance front : `sessionStorage` pour l'auth (clé `web-admin-auth` côté store mocké ;
  `auth_token` côté nouveau `apiService.ts`) — se vide à la fermeture de l'onglet. Prévoir un
  token de courte durée côté Web Admin.

### 3.2 Référentiel Site/Depot/Aisle/Location — vue "historique" du module mocké

```ts
type Site   = { id: string; code: string; name: string; city: string; isActive: boolean };
type Depot  = { id: string; siteId: string; code: string; name: string; type: 'PHARMA'|'CONSUMABLE'|'EQUIPMENT'; isActive: boolean };
type Aisle  = { id: string; depotId: string; code: string; name: string };
type Location = { id: string; aisleId: string; code: string; label: string };
```
Voir §2.1/2.4 pour la divergence avec le concept `Rayon` de l'API PHP réelle — à réconcilier.

### 3.3 Session (module métier — machine à états)

```ts
type SessionStatus =
  | 'IMPORTED_FROM_X3' | 'OPEN' | 'IN_PROGRESS' | 'SIGNED'
  | 'PENDING_SYNC' | 'SYNCING' | 'SYNCED_TO_X3' | 'SYNC_FAILED';

type Session = {
  id: string; code: string; name: string; siteId: string; depotIds: string[];
  authorizedUserIds: string[]; status: SessionStatus;
  startDate: string; endDate?: string;
  x3SessionId: string; importedFromX3At: string;
  openedToAgentsAt?: string; openedBy?: string;
  totalLines?: number; submittedLines?: number; validatedLines?: number;
};
```
```
IMPORTED_FROM_X3 --[openToAgents (responsable web)]--> OPEN
OPEN --[1er comptage agent mobile]--> IN_PROGRESS
IN_PROGRESS --[toutes fiches soumises+signées mobile]--> SIGNED
SIGNED --[toutes fiches VALIDATED côté web]--> PENDING_SYNC
PENDING_SYNC --[trigger PUSH]--> SYNCING
SYNCING --[succès total]--> SYNCED_TO_X3
SYNCING --[échec/partiel]--> SYNC_FAILED | PENDING_SYNC (retry)
```
Seule transition implémentée côté web : `openToAgents` (garde : refuse si statut ≠
`IMPORTED_FROM_X3`). Les autres sont pilotées par l'app mobile ou le module Sync (§3.7).

### 3.4 Submission (fiche de comptage) et CountLine

```ts
type SubmissionStatus = 'SUBMITTED' | 'IN_REVIEW' | 'REVISION' | 'VALIDATED' | 'ARCHIVED' | 'RECOUNT_PENDING';
type ReviewStatus = 'PENDING' | 'APPROVED' | 'REJECTED';
type Quantity = { itu: number; stu: number };
type Variance = { itu: number; stu: number; percent: number };

type CountLine = {
  id: string; submissionId: string;
  articleCode: string; articleName: string;
  locationId: string; locationCode: string;
  lotNumber: string; parentLotNumber?: string; expiryDate?: string;
  isLotCorrection: boolean; isOutOfList: boolean;
  theoreticalQty: Quantity; countedQty: Quantity; variance: Variance;
  reviewStatus: ReviewStatus; reviewComment?: string; reviewedAt?: string; reviewedBy?: string;
};

type Submission = {
  id: string; sessionId: string; agentId: string;
  perimeter: { depotId: string; aisleIds: string[]; locationIds: string[] };
  perimeterId?: string;
  isRecount: boolean; recountOfSubmissionId?: string; isArchived: boolean;
  status: SubmissionStatus;
  submittedAt: string; reviewStartedAt?: string; reviewedAt?: string; reviewedBy?: string;
  countLines: CountLine[];
  hasOutOfListItems: boolean; hasLotCorrections: boolean; revisionComment?: string;
};
```

Machine à états + règles serveur strictes à répliquer :
```
SUBMITTED --[startReview]--> IN_REVIEW
IN_REVIEW --[approveLine / rejectLine / resetLine par ligne]--> (reste IN_REVIEW)
IN_REVIEW --[validateSubmission, condition: TOUTES les lignes APPROVED]--> VALIDATED
IN_REVIEW --[sendToRevision, condition: ≥1 REJECTED ET 0 PENDING]--> REVISION
```
- `startReview` : idempotent (no-op si déjà ≥ `IN_REVIEW`), échoue seulement si fiche introuvable.
- `rejectLine(lineId, comment)` : **commentaire obligatoire**, rejeté si vide/whitespace.
- `resetLine` : remet `PENDING`, efface commentaire/horodatage/reviewer.
- `validateSubmission` : **bloqué si une seule ligne n'est pas `APPROVED`**.
- `sendToRevision` : **bloqué si aucune ligne REJECTED, ou si des lignes restent PENDING**.
- Chaque action doit générer une entrée d'audit (§3.8).

### 3.5 Perimeter — workflow recomptage & arbitrage

```ts
type PerimeterStatus =
  | 'PENDING' | 'ASSIGNED' | 'SUBMITTED'
  | 'RECOUNT_REQUESTED' | 'RECOUNT_IN_PROGRESS' | 'RECOUNT_SUBMITTED'
  | 'AWAITING_ARBITRATION' | 'ARBITRATED' | 'VALIDATED';

type Perimeter = {
  id: string; sessionId: string; siteId: string; depotId: string; aisleIds: string[];
  label: string;
  assignedAgentId?: string; recountAgentId?: string;   // DOIT être différent de assignedAgentId
  initialSubmissionId?: string; recountSubmissionId?: string;
  status: PerimeterStatus;
  createdAt: string; assignedAt?: string; submittedAt?: string;
  recountRequestedAt?: string; recountRequestedBy?: string;
  recountSubmittedAt?: string; arbitratedAt?: string; arbitratedBy?: string;
};
```
```
PENDING --[assignAgent]--> ASSIGNED
ASSIGNED --[setInitialSubmission]--> SUBMITTED
SUBMITTED --[requestRecount]--> RECOUNT_REQUESTED
RECOUNT_REQUESTED --[assignRecountAgent]--> RECOUNT_IN_PROGRESS
RECOUNT_REQUESTED --[cancelRecountRequest]--> SUBMITTED
RECOUNT_IN_PROGRESS --[setRecountSubmission]--> AWAITING_ARBITRATION
AWAITING_ARBITRATION --[completeArbitration]--> ARBITRATED
ARBITRATED --[validatePerimeter]--> VALIDATED
```
Règles métier critiques :
- **Recomptage aveugle** : l'agent de recomptage ne doit jamais voir les valeurs du comptage
  initial pendant sa saisie (contrainte côté API mobile).
- `recountAgentId` doit être **différent** de `assignedAgentId` (validation serveur requise).
- `requestRecount` : possible seulement si statut `SUBMITTED`.
- **Appariement des lignes pour arbitrage** (`lib/arbitration.ts`) : clé composite
  `(locationId, articleCode, lotNumber)`. Divergence = `countedQty.itu` OU `.stu` diffèrent.
- **Criticité** : `divergencePct = |recount.itu - initial.itu| / initial.theoreticalQty.itu * 100`
  → `< recountModerateCriticalityThresholdPct` (2%) = `low` ; entre modéré et
  `recountHighCriticalityThresholdPct` (10%) = `moderate` ; ≥ high = `high`.
- **Arbitrage** : pour chaque paire, choix `'initial'` ou `'recount'` (défaut `'initial'`) ; la
  ligne gagnante devient `APPROVED` et sera la valeur finale synchronisée vers X3. Le backend doit
  imposer que **tous les choix soient faits** avant de valider l'arbitrage (règle UI actuelle :
  bouton désactivé tant que incomplet).

### 3.6 Lock (verrou d'emplacement)

```ts
type LocationLock = {
  id: string; locationId: string; locationCode: string; agentId: string; sessionId: string;
  lockedAt: string; lastActivityAt: string; isStale: boolean;
  releasedAt?: string; releasedBy?: string; forceReleased?: boolean;
};
```
- `isStale` calculé dynamiquement : `elapsedMinutes = (now - lastActivityAt)/60000 ; isStale =
  elapsedMinutes >= settings.lockTimeoutMinutes` (défaut 15 min).
- `forceRelease` : réservé à `lock.force_release` (SUPER_ADMIN, INVENTORY_MANAGER) ; échoue si
  déjà libéré ; tracé en audit avec ancien agent/dates/emplacement.
- UI rafraîchit toutes les 30s (polling) — prévoir un endpoint léger, ou SSE/WebSocket si le
  backend veut éviter le polling pur.

### 3.7 Sync (synchronisation Sage X3 — PULL/PUSH orchestrés par le Web Admin)

```ts
type SyncDirection = 'INBOUND' | 'OUTBOUND';  // INBOUND=PULL X3→Web, OUTBOUND=PUSH Web→X3
type SyncJobStatus = 'PENDING' | 'RUNNING' | 'SUCCESS' | 'PARTIAL' | 'FAILED';
type SyncStepStatus = 'PENDING' | 'RUNNING' | 'SUCCESS' | 'FAILED' | 'SKIPPED';

type SyncJob = {
  id: string; direction: SyncDirection; sessionId?: string;   // requis pour OUTBOUND
  triggeredBy: string; startedAt: string; completedAt?: string;
  status: SyncJobStatus; steps: SyncStep[]; errors: SyncError[];
  totalItems: number; syncedItems: number; failedItems: number;
};
```
Étapes simulées aujourd'hui (à remplacer par la vraie orchestration Laravel, probablement en
s'appuyant sur l'API PHP RéférentielX3 pour le PULL) :

INBOUND : Connexion X3 → Récupération sessions → Récupération référentiel → MàJ habilitations →
Validation données.
OUTBOUND : Validation périmètre X3 → Vérification lots/emplacements → Push comptages validés →
Confirmation X3 et clôture session.

Catalogue d'erreurs attendu par l'UI :
| Code | Message | Retryable |
|---|---|---|
| `ARTICLE_NOT_FOUND` | Article inexistant dans X3 | non |
| `LOT_LOCKED_IN_X3` | Lot verrouillé par une autre transaction | oui |
| `DEPOT_UNAVAILABLE` | Dépôt cible temporairement indisponible | oui |
| `QTY_EXCEEDS_LIMIT` | Quantité dépasse le plafond autorisé | non |
| `X3_TIMEOUT` | Délai dépassé pour X3 | oui |
| `INVALID_PERIMETER` | Périmètre modifié dans X3 | non |

- Répercussion sur `Session.status` : OUTBOUND `SUCCESS`→`SYNCED_TO_X3`, `FAILED`→`SYNC_FAILED`,
  `PARTIAL`→`PENDING_SYNC` (retry possible).
- Lancer un OUTBOUND ne prend que les submissions `VALIDATED` de la session ; l'UI impose une
  **double confirmation** avant lancement, traité comme définitif une fois démarré.
- `sync.retry` : permission distincte pour relancer un job `PARTIAL`/`FAILED`.

### 3.8 Audit — règle absolue

```ts
type AuditEntry = {
  id: string; timestamp: string;
  actorId: string; actorEmail: string; actorRole: UserRole;
  action: AuditAction; targetType: AuditTargetType; targetId: string;
  metadata: Record<string, unknown>;
};
```
**Toute mutation métier doit écrire une entrée d'audit**, avec identité complète de l'acteur
dénormalisée et des `metadata` riches (valeurs avant/après, codes articles, emplacements,
commentaires). Le backend doit garantir la même exhaustivité sur chaque endpoint de mutation.
Pagination attendue côté UI : 50 entrées/page, 5 filtres, export CSV avec BOM UTF-8.

Actions catalogées : `LOGIN, LOGOUT, SESSION_OPENED_TO_AGENTS, SUBMISSION_REVIEW_STARTED,
LINE_APPROVED, LINE_REJECTED, LINE_RESET, SUBMISSION_VALIDATED, SUBMISSION_SENT_TO_REVISION,
LOCK_FORCE_RELEASED, SYNC_INBOUND_TRIGGERED, SYNC_OUTBOUND_TRIGGERED, SYNC_STEP_COMPLETED,
SYNC_SUCCESS, SYNC_FAILED, SYNC_RETRIED, SETTING_CHANGED, PERIMETER_CREATED, PERIMETER_ASSIGNED,
PERIMETER_RECOUNT_REQUESTED, PERIMETER_RECOUNT_ASSIGNED, PERIMETER_ARBITRATION_STARTED,
PERIMETER_ARBITRATION_COMPLETED, PERIMETER_VALIDATED, RECOUNT_REQUEST_CREATED,
RECOUNT_REQUEST_CANCELLED`.

### 3.9 Settings (paramètres système, locaux au Web Admin)

```ts
type SystemSettings = {
  lockTimeoutMinutes: number;                      // défaut 15
  lotCorrectionSuffixPattern: string;               // défaut '-{LETTER}'
  varianceWarningThresholdPct: number;              // défaut 5
  varianceCriticalThresholdPct: number;             // défaut 15
  locationNamingConvention: string;                 // défaut '{DEPOT}-{AISLE}-{LOC}'
  syncAutoRetry: boolean;                           // défaut true
  syncMaxRetries: number;                           // défaut 3
  recountModerateCriticalityThresholdPct: number;   // défaut 2
  recountHighCriticalityThresholdPct: number;       // défaut 10
};
```
Éditables uniquement par `SUPER_ADMIN` (`settings.update` ; `INVENTORY_MANAGER`/`READONLY` n'ont
que `settings.view`). Chaque changement → audit `SETTING_CHANGED` avec `oldValue`/`newValue`. Un
vrai endpoint `GET/PUT /settings` doit être créé côté Laravel (voir piège §1.1 sur le mauvais
base URL actuellement câblé).

---

## 4. RBAC — Matrice de permissions (`lib/permissions.ts`, inchangée)

```ts
type Permission =
  | 'session.view' | 'session.open'
  | 'submission.view' | 'submission.review' | 'submission.validate' | 'submission.send_revision'
  | 'lock.view' | 'lock.force_release'
  | 'sync.view' | 'sync.trigger_inbound' | 'sync.trigger_outbound' | 'sync.retry'
  | 'settings.view' | 'settings.update'
  | 'reference.view'
  | 'audit.view' | 'audit.export'
  | 'perimeter.view' | 'perimeter.manage' | 'perimeter.request_recount' | 'perimeter.arbitrate' | 'perimeter.validate';
```

| Permission | SUPER_ADMIN | INVENTORY_MANAGER | READONLY |
|---|:---:|:---:|:---:|
| session.view / session.open | ✅ | ✅ | view only |
| submission.* | ✅ | ✅ | view only |
| lock.view / lock.force_release | ✅ | ✅ | view only |
| sync.* | ✅ | ✅ | view only |
| settings.view / settings.update | ✅ / ✅ | ✅ / ❌ | ✅ / ❌ |
| reference.view | ✅ | ✅ | ✅ |
| audit.view / audit.export | ✅ / ✅ | ✅ / ❌ | ✅ / ✅ |
| perimeter.* | ✅ tout | ✅ tout | view only |
| OPERATOR / MOBILE_MANAGER | ❌ aucune permission web | | |

Cette matrice doit être reproduite côté backend (middleware/policy Laravel) — le front seul ne
sécurise jamais les mutations. Scoping par site (`user.siteIds`) à appliquer côté API, pas
seulement en filtrage front (déjà fait ainsi dans `DashboardPage`).

---

## 5. Pages / Routes → surface d'API complète (mise à jour)

| Route front | Page | Backend concerné | Données/actions nécessaires |
|---|---|---|---|
| `/login` | LoginPage | Laravel | `POST /auth/login` |
| `/dashboard` | DashboardPage | Laravel | KPIs agrégés, sessions par site, alertes, 5 dernières entrées d'audit |
| `/sessions` | SessionsListPage | Laravel | `GET /sessions` (module métier mocké) |
| `/sessions/:id/overview\|agents\|submissions\|history` | SessionDetailLayout + tabs | Laravel | détail session, locks actifs (poll 30s), submissions, historique audit |
| `/submissions`, `/submissions/:id` | SubmissionsListPage, SubmissionReviewPage | Laravel | liste + `start-review`, `approve/reject/reset` ligne, `validate`, `send-revision` |
| `/perimeters`, `/perimeters/:id/arbitration` | PerimetersPage, PerimeterArbitrationPage | Laravel | liste groupée par session, `assign-agent`, `request-recount`, `cancel-recount`, arbitrage par ligne, `complete-arbitration`, `validate` |
| `/sync`, `/sync/:jobId` | SyncPage, SyncJobDetailPage | Laravel | `GET /sync/jobs`, `POST /sync/inbound`, `POST /sync/outbound`, suivi temps réel (poll ou SSE) |
| `/settings/system`, `/settings/naming` | SystemSettingsPage, NamingConventionsPage | Laravel | `GET/PUT /settings` (⚠️ mauvais base URL actuellement, voir §1.1) |
| `/settings/reference` | ReferenceDataPage | **API PHP RéférentielX3** (déjà réelle) | arbre sites→dépôts→rayons (lazy), liste users web (mock en attendant Laravel, voir §2.3) |
| `/settings/reference/rayons/:siteId/:depotId/:rayonId` | RayonDetailPage | **API PHP RéférentielX3** | `GET /rayons/:code/detail` paginé — détail des lots en stock d'un rayon |
| `/sessions-x3` | SessionsX3Page | **API PHP RéférentielX3** | `GET /sessions` — explorateur sessions natives X3 |
| `/sessions-x3/:numeroSession` | SessionX3DetailPage | **API PHP RéférentielX3** | `GET /sessions/:numero`, `GET /sessions/:numero/listes` |
| `/sessions-x3/:numeroSession/listes/:numeroListe` | ListeDetailPage | **API PHP RéférentielX3** | `GET /listes/:numero`, `GET /listes/:numero/lignes` (chargement progressif paginé) |
| `/audit` | AuditPage | Laravel | pagination 50/page, 5 filtres, export CSV |

Le préfixe d'API Laravel n'est pas encore fixé côté front (aucun appel réseau réel pour cette
partie) — à définir avec le backend (ex: `/api/v1/...`), potentiellement via une variable d'env
distincte de `VITE_API_BASE_URL` (voir §1.1).

---

## 6. Ce qui est simulé et devra être remplacé par du réel

- **`lib/syncSimulation.ts`** : durées d'étapes et issue (`SUCCESS`/`PARTIAL`/`FAILED` tirée
  aléatoirement 90/7/3%) — à remplacer par la vraie orchestration Laravel↔X3 (probablement via
  l'API PHP RéférentielX3 pour le PULL, et un mécanisme à définir pour le PUSH réel des comptages
  vers X3). L'UI attend le même contrat de données (`SyncStep[]`, `SyncError[]`).
- **Login** : mot de passe unique en dur — à remplacer par un vrai flux JWT + refresh.
- **Tout le module métier** (`src/mocks/*.ts` + stores Zustand persistés) reste à migrer vers de
  vraies tables/API Laravel : sessions (module web), submissions, perimeters, locks, sync jobs,
  audit, settings, users web.
- **Le référentiel géographique et les sessions X3 natives**, eux, sont **déjà réels** via l'API
  PHP — ne pas les re-mocker côté Laravel, plutôt consommer/orchestrer cette API existante.

---

## 7. Conventions de code utiles au backend

- IDs générés côté front actuellement via `generateId(prefix)` (mock only) — le backend générera
  ses propres IDs (UUID recommandé).
- Dates : chaînes ISO 8601 partout côté module métier mocké. Côté API PHP, dates SQL Server brutes
  avec sentinelles nulles à nettoyer (`1753-01-01`, `1899-12-31` → `null`).
- `Variance.percent` signé (positif = surplus, négatif = manquant).
- Export CSV : BOM UTF-8 requis en tête de fichier (compatibilité Excel FR).
- Deux enveloppes de réponse coexistent dans le code front — à harmoniser avec le backend :
  - `ResourceResponse<T> = { data: T }` / `PaginatedResponse<T> = { data: T[], meta: {...} }`
    (`src/types/api.ts`) — conventions "à la Laravel", pas encore utilisées par un vrai endpoint.
  - `PhpApiResponse<T> = { success, message, data, timestamp? }` + `pagination` externe — c'est le
    format réel de l'API PHP RéférentielX3 existante. Si Laravel décide de proxyfier cette API,
    il faudra choisir de conserver ce format ou de le convertir vers `ResourceResponse`.

---

## 8. Ordre de priorité suggéré pour le backend Laravel

1. **Auth** (`/auth/login`) + RBAC middleware — bloque tout le reste.
2. **Users web** (`GET/POST/PUT /users`) — actuellement 100% mocké, aucune vraie source ; l'API
   PHP ne les expose pas (§2.3), donc entièrement à la charge de Laravel.
3. **Sessions (module métier)** + transition `openToAgents` — envisager dès cette étape le lien
   `x3SessionId` ↔ `numero_session` réel (§2.4) en s'appuyant sur l'API PHP pour le PULL initial.
4. **Submissions** + workflow de review complet.
5. **Locks** (lecture + force-release).
6. **Perimeters** (assignation, recomptage, arbitrage).
7. **Sync** (inbound/outbound réels — orchestrer l'API PHP pour le PULL référentiel/sessions X3 ;
   définir le mécanisme PUSH réel des comptages validés vers X3, non encore existant nulle part).
8. **Settings** + **Audit** — transverses, à développer en parallèle dès le début (l'audit doit
   être branché sur chaque endpoint de mutation dès sa création). Corriger au passage le câblage
   de `settingsService` vers la bonne base URL Laravel (§1.1).

**Clarifications à obtenir avant de figer le modèle** (compilées depuis les ⚠️ ci-dessus) :
- Domaine email réel des comptes (`@inventaire.com` code vs `@pna.sn` README).
- Réconciliation du modèle géographique Allée/Emplacement (ancien mock) vs Rayon (API PHP réelle).
- Liste exhaustive des codes de statut numériques X3 (`Session.statut`, `Liste.statut`,
  `Ligne.statut_ligne`) — le front n'en connaît qu'un sous-ensemble déduit de l'UI.
- Stratégie définitive de séparation des base URLs entre l'API PHP RéférentielX3 (existante,
  lecture seule) et l'API Laravel (à construire, lecture/écriture).

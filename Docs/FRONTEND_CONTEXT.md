# Contexte Frontend — pour implémentation du Backend

> Document technique généré à partir de l'analyse exhaustive du code source du Web Admin.
> Objectif : donner au développeur backend (Laravel + intégration Sage X3) tout ce qu'il doit
> savoir sur ce qui existe côté front pour construire une API qui matche exactement le contrat
> attendu — modèles de données, règles métier, machines à états, permissions, et endpoints
> implicites. À utiliser en complément de la documentation projet globale existante.

**État du front** : maquette V1 fonctionnelle, 100% mockée (aucun appel réseau). Toute la logique
métier ci-dessous est aujourd'hui exécutée **côté client**, dans des stores Zustand persistés en
`localStorage`. Le backend doit **reproduire exactement ces règles côté serveur** (elles ne
doivent plus être approximées côté client une fois l'API branchée), sans changer le contrat des
stores pour ne pas casser les composants React.

Stack front : React 19, TypeScript strict, Vite 8, Zustand (+persist), React Router v6,
TanStack Table v8, Recharts, date-fns, lucide-react, CSS Modules.

---

## 1. Architecture globale et rôle de ce projet

```
Sage X3 ERP  ⇄ (PULL/PUSH manuel)  ⇄  Backend Laravel (à construire)  ⇄  API REST JSON
                                              │                              │
                                              ▼                              ▼
                                     Web Admin (CE PROJET)          App Mobile React Native
                                     supervision/validation          saisie terrain (agents)
```

- Le Web Admin ne parle jamais directement à Sage X3 : tout passe par le backend Laravel qui
  orchestre les synchronisations PULL (X3 → app) et PUSH (app → X3).
- L'app mobile (projet séparé) est le point de saisie des comptages ; le Web Admin est le point
  de supervision/validation/arbitrage.
- **Migration prévue** : chaque store Zustand remplacera ses données mockées par des appels API,
  en gardant la même interface (mêmes noms de méthodes, mêmes signatures) — donc l'API doit être
  pensée pour correspondre 1:1 aux actions de store listées plus bas.

---

## 2. Modèle de données (types TypeScript = contrat de données attendu)

### 2.1 User / Auth

```ts
type UserRole = 'OPERATOR' | 'MOBILE_MANAGER' | 'SUPER_ADMIN' | 'INVENTORY_MANAGER' | 'READONLY';

type User = {
  id: string;
  email: string;
  firstName: string;
  lastName: string;
  role: UserRole;
  siteIds: string[];        // sites autorisés ; vide = tous les sites (cas SUPER_ADMIN)
  isActive: boolean;
  lastLoginAt?: string;     // ISO datetime
  x3UserId?: string;        // mapping vers profil utilisateur X3
};

type AuthUser = User & {
  currentSiteId?: string;   // site actuellement sélectionné dans l'UI (état local, pas persistant côté back)
};
```

- **Rôles mobile** (`OPERATOR`, `MOBILE_MANAGER`) : aucun accès au Web Admin — réservés à l'app
  mobile. Le backend doit rejeter leur connexion sur l'endpoint web (`isWebRole()` équivalent).
- **Rôles web** : `SUPER_ADMIN`, `INVENTORY_MANAGER`, `READONLY`.
- En V1, un seul mot de passe partagé (`demo2026`) pour tous les comptes de démo — à remplacer
  par un vrai `/auth/login` avec hash + JWT + refresh token.
- Persistance front : `sessionStorage` (clé `web-admin-auth`) — se vide à la fermeture de l'onglet,
  contrairement aux autres stores en `localStorage`. Le backend doit prévoir un token de courte
  durée de vie côté session pour le Web Admin (à distinguer d'un éventuel token mobile longue durée).

### 2.2 Référentiel Sites / Dépôts / Allées / Emplacements

```ts
type Site   = { id: string; code: string; name: string; city: string; isActive: boolean };
type Depot  = { id: string; siteId: string; code: string; name: string; type: 'PHARMA'|'CONSUMABLE'|'EQUIPMENT'; isActive: boolean };
type Aisle  = { id: string; depotId: string; code: string; name: string };
type Location = { id: string; aisleId: string; code: string; label: string };
```

- Référentiel en **lecture seule** côté Web Admin — provient de Sage X3 via synchronisation
  INBOUND. Le backend doit exposer ces données mais ne jamais permettre leur édition depuis le
  Web Admin (seul un PULL X3 les met à jour).
- Hiérarchie stricte : Site → Depot → Aisle → Location.

### 2.3 Session d'inventaire

```ts
type SessionStatus =
  | 'IMPORTED_FROM_X3' | 'OPEN' | 'IN_PROGRESS' | 'SIGNED'
  | 'PENDING_SYNC' | 'SYNCING' | 'SYNCED_TO_X3' | 'SYNC_FAILED';

type Session = {
  id: string;
  code: string;                 // ex: "INV-2026-001"
  name: string;
  siteId: string;
  depotIds: string[];
  authorizedUserIds: string[];  // agents mobiles autorisés
  status: SessionStatus;
  startDate: string;
  endDate?: string;
  x3SessionId: string;          // référence X3
  importedFromX3At: string;
  openedToAgentsAt?: string;
  openedBy?: string;            // userId responsable web
  totalLines?: number;          // calculé
  submittedLines?: number;      // calculé
  validatedLines?: number;      // calculé
};
```

**Machine à états (Session)** :
```
IMPORTED_FROM_X3 --[openToAgents (responsable web)]--> OPEN
OPEN --[1er comptage agent mobile]--> IN_PROGRESS
IN_PROGRESS --[toutes fiches soumises+signées mobile]--> SIGNED
SIGNED --[toutes fiches VALIDATED côté web]--> PENDING_SYNC
PENDING_SYNC --[trigger PUSH]--> SYNCING
SYNCING --[succès total]--> SYNCED_TO_X3
SYNCING --[échec/partiel]--> SYNC_FAILED | PENDING_SYNC (retry)
```
- Seule transition actuellement implémentée côté web : `openToAgents` (IMPORTED_FROM_X3 → OPEN),
  garde-fou : rejette si le statut n'est pas `IMPORTED_FROM_X3`. Les autres transitions
  (IN_PROGRESS, SIGNED) sont pilotées par l'app mobile (hors périmètre de ce front) — le backend
  doit les gérer, le web ne fait que les consulter.
- `PENDING_SYNC → SYNCING → SYNCED_TO_X3 / SYNC_FAILED` est piloté par le module Sync (§5).

### 2.4 Submission (fiche de comptage) et CountLine

```ts
type SubmissionStatus =
  | 'SUBMITTED' | 'IN_REVIEW' | 'REVISION' | 'VALIDATED'
  | 'ARCHIVED'          // fiche initiale archivée si recomptage demandé
  | 'RECOUNT_PENDING';  // en attente de recomptage côté agent mobile

type ReviewStatus = 'PENDING' | 'APPROVED' | 'REJECTED';

type Quantity = { itu: number; stu: number };   // ITU = unité de transport, STU = unité de stockage
type Variance = { itu: number; stu: number; percent: number };

type CountLine = {
  id: string; submissionId: string;
  articleCode: string; articleName: string;
  locationId: string; locationCode: string;
  lotNumber: string; parentLotNumber?: string;  // correction de lot : lot enfant lié à un lot parent
  expiryDate?: string;
  isLotCorrection: boolean;
  isOutOfList: boolean;                          // article compté hors référentiel attendu
  theoreticalQty: Quantity;                       // stock théorique (issu de X3)
  countedQty: Quantity;                           // saisi par l'agent mobile
  variance: Variance;                             // countedQty - theoreticalQty (à calculer serveur)
  reviewStatus: ReviewStatus;
  reviewComment?: string;
  reviewedAt?: string;
  reviewedBy?: string;
};

type SubmissionPerimeter = { depotId: string; aisleIds: string[]; locationIds: string[] };

type Submission = {
  id: string; sessionId: string; agentId: string;
  perimeter: SubmissionPerimeter;
  perimeterId?: string;               // lien vers Perimeter (workflow recomptage)
  isRecount: boolean;
  recountOfSubmissionId?: string;     // ID de la fiche initiale si isRecount
  isArchived: boolean;
  status: SubmissionStatus;
  submittedAt: string;
  reviewStartedAt?: string;
  reviewedAt?: string;
  reviewedBy?: string;
  countLines: CountLine[];
  hasOutOfListItems: boolean;
  hasLotCorrections: boolean;
  revisionComment?: string;
};
```

**Machine à états (Submission)** :
```
SUBMITTED --[startReview (auto au 1er accès responsable)]--> IN_REVIEW
IN_REVIEW --[approveLine / rejectLine / resetLine sur chaque CountLine]--> (reste IN_REVIEW)
IN_REVIEW --[validateSubmission, condition: TOUTES les lignes APPROVED]--> VALIDATED
IN_REVIEW --[sendToRevision, condition: ≥1 ligne REJECTED ET 0 ligne PENDING]--> REVISION
```
Règles serveur à répliquer strictement :
- `startReview` : idempotent, ne fait rien si déjà `IN_REVIEW` ou au-delà (retourne succès quand
  même) ; échoue seulement si la fiche n'existe pas.
- `approveLine(lineId)` : passe `reviewStatus = APPROVED`, efface le commentaire, horodate,
  identifie le reviewer.
- `rejectLine(lineId, comment)` : **commentaire obligatoire** (rejeté si vide/whitespace) ; passe
  `reviewStatus = REJECTED`.
- `resetLine(lineId)` : remet `reviewStatus = PENDING`, efface commentaire/horodatage/reviewer.
- `validateSubmission` : **bloqué si une seule ligne n'est pas `APPROVED`** — le backend doit
  renvoyer une erreur explicite listant les lignes non traitées.
- `sendToRevision(comment?)` : **bloqué si aucune ligne REJECTED, ou si des lignes sont encore
  PENDING** (toutes les lignes doivent avoir été traitées — soit approuvées, soit rejetées —
  avant de pouvoir renvoyer en révision).
- Chaque action doit générer une entrée d'audit (voir §6).

### 2.5 Perimeter (périmètre) — workflow recomptage & arbitrage

```ts
type PerimeterStatus =
  | 'PENDING' | 'ASSIGNED' | 'SUBMITTED'
  | 'RECOUNT_REQUESTED' | 'RECOUNT_IN_PROGRESS' | 'RECOUNT_SUBMITTED'
  | 'AWAITING_ARBITRATION' | 'ARBITRATED' | 'VALIDATED';

type Perimeter = {
  id: string; sessionId: string; siteId: string; depotId: string; aisleIds: string[];
  label: string;                    // ex: "Allée A1 — Dépôt Principal"
  assignedAgentId?: string;         // agent du comptage initial
  recountAgentId?: string;          // agent du recomptage — DOIT être différent de assignedAgentId
  initialSubmissionId?: string;
  recountSubmissionId?: string;
  status: PerimeterStatus;
  createdAt: string;
  assignedAt?: string;
  submittedAt?: string;
  recountRequestedAt?: string;
  recountRequestedBy?: string;
  recountSubmittedAt?: string;
  arbitratedAt?: string;
  arbitratedBy?: string;
};
```

**Machine à états (Perimeter)** :
```
PENDING --[assignAgent]--> ASSIGNED
ASSIGNED --[setInitialSubmission (soumission fiche par agent mobile)]--> SUBMITTED
SUBMITTED --[requestRecount (responsable web)]--> RECOUNT_REQUESTED
RECOUNT_REQUESTED --[assignRecountAgent]--> RECOUNT_IN_PROGRESS
RECOUNT_REQUESTED --[cancelRecountRequest]--> SUBMITTED (annulation)
RECOUNT_IN_PROGRESS --[setRecountSubmission (agent recomptage soumet)]--> AWAITING_ARBITRATION
AWAITING_ARBITRATION --[completeArbitration]--> ARBITRATED
ARBITRATED --[validatePerimeter]--> VALIDATED
```
Règles métier critiques :
- **Recomptage aveugle** : l'agent de recomptage ne doit JAMAIS voir les valeurs du comptage
  initial pendant sa saisie (contrainte à faire respecter côté API mobile — ne pas exposer la
  fiche initiale à l'agent de recomptage).
- `recountAgentId` **doit obligatoirement être différent** de `assignedAgentId` — validation
  serveur requise (pas trouvée explicitement dans le code front actuel, mais mentionnée dans l'UI
  comme contrainte métier — cf. `RecountRequestModal.tsx`).
- `requestRecount` : seulement possible si le statut est `SUBMITTED`.
- `cancelRecountRequest` : remet le statut à `SUBMITTED`, efface `recountRequestedAt`,
  `recountRequestedBy`, `recountAgentId`.
- **Appariement des lignes pour arbitrage** (`lib/arbitration.ts` — `buildLinePairs`) : les
  lignes de la fiche initiale et de la fiche de recomptage sont appariées sur la clé composite
  `(locationId, articleCode, lotNumber)`. Une ligne initiale sans correspondance dans le
  recomptage a `recount = null`.
- **Divergence** : une paire est divergente si `countedQty.itu` OU `countedQty.stu` diffèrent
  entre initial et recomptage.
- **Criticité d'une divergence** (`getCriticality`) :
  - `divergencePct = |recount.itu - initial.itu| / initial.theoreticalQty.itu * 100`
  - `< recountModerateCriticalityThresholdPct` (défaut 2%) → `low`
  - `>= moderate` et `< recountHighCriticalityThresholdPct` (défaut 10%) → `moderate`
  - `>= high` → `high`
- **Arbitrage** (`completeArbitration` / `applyArbitrationChoices`) : pour chaque paire, le
  responsable choisit `'initial'` ou `'recount'` (défaut `'initial'` si non choisi) ; la ligne
  gagnante est marquée `reviewStatus = APPROVED` et devient la valeur finale à synchroniser vers
  X3. Le bouton de validation d'arbitrage est désactivé côté UI tant que **tous** les choix n'ont
  pas été faits explicitement (`allChoicesMade`) — le backend doit imposer la même règle
  (endpoint d'arbitrage à rejeter si choix incomplets).
- Un périmètre en `SUBMITTED` avec fiche initiale ayant des écarts déclenche la proposition de
  recomptage — seuils de variance utilisés pour l'alerte : `varianceWarningThresholdPct` (5%) et
  `varianceCriticalThresholdPct` (15%), distincts des seuils de criticité d'arbitrage ci-dessus.
- Quand un recomptage est demandé, la fiche initiale doit passer en `ARCHIVED`
  (`Submission.isArchived = true`) — cohérence à vérifier côté backend car pas explicitement vue
  dans le store actuel (probablement géré côté app mobile / futur endpoint).

### 2.6 Lock (verrou d'emplacement — activité agent mobile)

```ts
type LocationLock = {
  id: string; locationId: string; locationCode: string;
  agentId: string; sessionId: string;
  lockedAt: string; lastActivityAt: string;
  isStale: boolean;              // recalculé dynamiquement côté client — À CALCULER CÔTÉ SERVEUR
  releasedAt?: string; releasedBy?: string; forceReleased?: boolean;
};
```
- Un agent mobile verrouille un emplacement pendant sa saisie (empêche un autre agent de compter
  le même emplacement simultanément).
- **`isStale` est calculé dynamiquement** (pas stocké en dur) :
  `elapsedMinutes = (now - lastActivityAt) / 60000 ; isStale = elapsedMinutes >= lockTimeoutMinutes`
  (`lockTimeoutMinutes` = paramètre système, défaut 15 min). Le backend doit exposer ce calcul
  (soit le faire à la volée en réponse API, soit fournir `lastActivityAt` et laisser le calcul
  au client — mais la logique de seuil doit être centralisée sur `settings.lockTimeoutMinutes`).
- `forceRelease(lockId)` : **réservé aux rôles avec permission `lock.force_release`**
  (SUPER_ADMIN, INVENTORY_MANAGER). Un verrou déjà libéré (`releasedAt` non null) ne peut pas
  être libéré à nouveau. Action tracée en audit avec métadonnées : ancien agent, dates, code
  emplacement.
- Le Web Admin rafraîchit l'affichage des verrous actifs toutes les 30s (polling) + tick 5s pour
  les durées relatives — suggère un besoin d'endpoint léger et rapide type
  `GET /sessions/:id/locks` ou passage à du websocket/SSE si le backend veut éviter le polling.

### 2.7 Sync (synchronisation Sage X3)

```ts
type SyncDirection = 'INBOUND' | 'OUTBOUND';  // INBOUND = PULL X3→Web, OUTBOUND = PUSH Web→X3
type SyncJobStatus = 'PENDING' | 'RUNNING' | 'SUCCESS' | 'PARTIAL' | 'FAILED';
type SyncStepStatus = 'PENDING' | 'RUNNING' | 'SUCCESS' | 'FAILED' | 'SKIPPED';

type SyncStep = { id: string; label: string; status: SyncStepStatus; message?: string; startedAt?: string; completedAt?: string };
type SyncError = { id: string; lineId?: string; articleCode?: string; errorCode: string; message: string; retryable: boolean };

type SyncJob = {
  id: string; direction: SyncDirection;
  sessionId?: string;         // requis pour OUTBOUND ; optionnel pour INBOUND (peut couvrir plusieurs sessions)
  triggeredBy: string; startedAt: string; completedAt?: string;
  status: SyncJobStatus;
  steps: SyncStep[];
  errors: SyncError[];
  totalItems: number; syncedItems: number; failedItems: number;
};
```

**Étapes simulées côté front** (`lib/syncSimulation.ts`) — à remplacer par les vraies étapes
d'intégration X3 côté backend, mais donnent une idée précise du déroulé attendu par l'UI :

INBOUND (PULL) :
1. Connexion à Sage X3
2. Récupération des sessions d'inventaire
3. Récupération du référentiel (sites, dépôts, emplacements)
4. Mise à jour des habilitations
5. Validation des données récupérées

OUTBOUND (PUSH) :
1. Validation du périmètre X3
2. Vérification des lots et emplacements
3. Push des comptages validés
4. Confirmation X3 et clôture session

Catalogue d'erreurs attendu par l'UI (`errorCode`, avec `retryable`) :
| Code | Message | Retryable |
|---|---|---|
| `ARTICLE_NOT_FOUND` | L'article n'existe pas dans le référentiel X3 | non |
| `LOT_LOCKED_IN_X3` | Le lot est verrouillé dans X3 par une autre transaction | oui |
| `DEPOT_UNAVAILABLE` | Le dépôt cible est temporairement indisponible | oui |
| `QTY_EXCEEDS_LIMIT` | La quantité comptée dépasse le plafond autorisé | non |
| `X3_TIMEOUT` | Délai d'attente dépassé pour X3 | oui |
| `INVALID_PERIMETER` | Le périmètre de la session a été modifié dans X3 | non |

Règles de complétion de job (`completeJob`, à réimplémenter réellement côté backend intégration
X3 plutôt que simulée) :
- `SUCCESS` → `syncedItems = totalItems`, `failedItems = 0`
- `PARTIAL` → `failedItems = max(1, floor(totalItems * 0.15))` (dans la simulation ; en réel, ce
  sera le compte réel d'erreurs), `syncedItems = totalItems - failedItems`
- `FAILED` → `syncedItems = 0`, `failedItems = totalItems`
- **Répercussion sur `Session.status`** :
  - OUTBOUND `SUCCESS` → session passe à `SYNCED_TO_X3`
  - OUTBOUND `FAILED` → session passe à `SYNC_FAILED`
  - OUTBOUND `PARTIAL` → session repasse à `PENDING_SYNC` (retry possible)
- Démarrer un OUTBOUND sync passe la session en `SYNCING` immédiatement, et ne prend en compte
  que les submissions `VALIDATED` de la session (`totalItems` = somme de leurs `countLines`).
- Le flux OUTBOUND côté UI a une **double confirmation** avant lancement (écran "confirm1" →
  "confirm2" avec avertissement explicite "action irréversible") — le backend doit traiter le PUSH
  comme définitif une fois lancé (pas d'annulation en cours de route dans l'UI actuelle).
- `sync.retry` est une permission distincte — probablement pour relancer un job `PARTIAL`/`FAILED`
  sans tout resoumettre (endpoint à prévoir, ex: `POST /sync/:jobId/retry`).

### 2.8 Audit

```ts
type AuditAction =
  | 'LOGIN' | 'LOGOUT'
  | 'SESSION_OPENED_TO_AGENTS'
  | 'SUBMISSION_REVIEW_STARTED' | 'LINE_APPROVED' | 'LINE_REJECTED' | 'LINE_RESET'
  | 'SUBMISSION_VALIDATED' | 'SUBMISSION_SENT_TO_REVISION'
  | 'LOCK_FORCE_RELEASED'
  | 'SYNC_INBOUND_TRIGGERED' | 'SYNC_OUTBOUND_TRIGGERED' | 'SYNC_STEP_COMPLETED'
  | 'SYNC_SUCCESS' | 'SYNC_FAILED' | 'SYNC_RETRIED'
  | 'SETTING_CHANGED'
  | 'PERIMETER_CREATED' | 'PERIMETER_ASSIGNED' | 'PERIMETER_RECOUNT_REQUESTED'
  | 'PERIMETER_RECOUNT_ASSIGNED' | 'PERIMETER_ARBITRATION_STARTED'
  | 'PERIMETER_ARBITRATION_COMPLETED' | 'PERIMETER_VALIDATED'
  | 'RECOUNT_REQUEST_CREATED' | 'RECOUNT_REQUEST_CANCELLED';

type AuditTargetType = 'session' | 'submission' | 'countLine' | 'lock' | 'setting' | 'user' | 'syncJob' | 'perimeter' | 'recountRequest';

type AuditEntry = {
  id: string; timestamp: string;
  actorId: string; actorEmail: string; actorRole: UserRole;
  action: AuditAction; targetType: AuditTargetType; targetId: string;
  metadata: Record<string, unknown>;
};
```
- **Règle absolue observée dans tout le code front** : *toute mutation métier doit écrire une
  entrée d'audit*, avec l'identité complète de l'acteur dénormalisée (id + email + rôle, pas
  seulement un userId) et des `metadata` contextuelles riches (valeurs avant/après, codes
  articles, emplacements, commentaires...). Le backend doit garantir la même exhaustivité
  d'audit sur chaque endpoint de mutation.
- Le store d'audit front garde les 1000 dernières entrées (`slice(0, 1000)`), triées les plus
  récentes en premier — le backend devra bien sûr tout conserver et paginer (voir `AuditPage` :
  50 entrées/page, 5 filtres, export CSV avec BOM UTF-8 pour compatibilité Excel FR).

### 2.9 Settings (paramètres système)

```ts
type SystemSettings = {
  lockTimeoutMinutes: number;                        // défaut 15
  lotCorrectionSuffixPattern: string;                 // défaut '-{LETTER}'
  varianceWarningThresholdPct: number;                // défaut 5
  varianceCriticalThresholdPct: number;               // défaut 15
  locationNamingConvention: string;                   // défaut '{DEPOT}-{AISLE}-{LOC}'
  syncAutoRetry: boolean;                             // défaut true
  syncMaxRetries: number;                             // défaut 3
  recountModerateCriticalityThresholdPct: number;     // défaut 2 — seuil arbitrage "modéré"
  recountHighCriticalityThresholdPct: number;         // défaut 10 — seuil arbitrage "élevé"
};
```
- Paramètres **locaux au Web Admin uniquement** (pas des paramètres X3). Éditables uniquement par
  `SUPER_ADMIN` (permission `settings.update` — `INVENTORY_MANAGER` et `READONLY` n'ont que
  `settings.view`).
- Chaque changement génère une entrée d'audit `SETTING_CHANGED` avec `oldValue`/`newValue`.
- Ces seuils pilotent directement l'UI (alertes dashboard, badges de criticité, calculs
  d'arbitrage) — le backend doit les exposer via une API et les utiliser pour tout calcul serveur
  de variance/criticité afin de rester cohérent avec le front.

---

## 3. RBAC — Matrice de permissions (`lib/permissions.ts`)

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
| submission.* (view/review/validate/send_revision) | ✅ | ✅ | view only |
| lock.view / lock.force_release | ✅ | ✅ | view only |
| sync.view / trigger_inbound / trigger_outbound / retry | ✅ | ✅ | view only |
| settings.view / settings.update | ✅ / ✅ | ✅ / ❌ | ✅ / ❌ |
| reference.view | ✅ | ✅ | ✅ |
| audit.view / audit.export | ✅ / ✅ | ✅ / ❌ | ✅ / ✅ |
| perimeter.* (view/manage/request_recount/arbitrate/validate) | ✅ tout | ✅ tout | view only |
| — OPERATOR / MOBILE_MANAGER | ❌ aucune permission web | | |

- Cette matrice doit être **reproduite côté backend** pour l'autorisation API (middleware/policy
  Laravel), pas seulement côté front — le front seul ne suffit jamais à sécuriser les mutations.
- Un `SUPER_ADMIN` avec `siteIds = []` a accès à tous les sites ; les autres rôles sont scopés à
  leurs `siteIds`. Le dashboard filtre déjà les sessions par site pour les non-admin
  (`DashboardPage.tsx`) — cette règle de scoping par site doit être appliquée côté API (ne pas se
  reposer sur un filtrage front).

---

## 4. Pages / Routes → surface d'API implicite

| Route front | Page | Données/actions nécessaires côté API |
|---|---|---|
| `/login` | LoginPage | `POST /auth/login` |
| `/dashboard` | DashboardPage | KPIs agrégés (sessions à ouvrir, actives, fiches à valider, écarts critiques, recomptages en attente, arbitrages requis), sessions par site, alertes, 5 dernières entrées d'audit |
| `/sessions` | SessionsListPage | `GET /sessions` (filtrable par site/statut) |
| `/sessions/:id/overview` | SessionOverviewTab | `GET /sessions/:id` |
| `/sessions/:id/agents` | SessionAgentsTab | `GET /sessions/:id/locks` (actifs + stale), `GET /sessions/:id/submissions`, `GET /sessions/:id/perimeters`, `POST /locks/:id/force-release` — polling 30s attendu |
| `/sessions/:id/submissions` | SessionSubmissionsTab | `GET /sessions/:id/submissions` |
| `/sessions/:id/history` | SessionHistoryTab | `GET /audit?targetType=session&targetId=:id` (ou équivalent) |
| `/submissions` | SubmissionsListPage | `GET /submissions` (tous statuts, tous sites scopés) |
| `/submissions/:id` | SubmissionReviewPage | `GET /submissions/:id`, `POST /submissions/:id/start-review`, `POST /submissions/:id/lines/:lineId/approve`, `.../reject`, `.../reset`, `POST /submissions/:id/validate`, `POST /submissions/:id/send-revision` |
| `/perimeters` | PerimetersPage | `GET /perimeters` (groupés par session, filtrables statut/session), `POST /perimeters/:id/cancel-recount` |
| `/perimeters/:id/arbitration` | PerimeterArbitrationPage | `GET /perimeters/:id` + fiches initiale/recomptage, `POST /perimeters/:id/arbitration` (choix par ligne), `POST /perimeters/:id/complete-arbitration` |
| `/sync` | SyncPage | `GET /sync/jobs`, `POST /sync/inbound`, `POST /sync/outbound` (avec sessionId) |
| `/sync/:jobId` | SyncJobDetailPage | `GET /sync/jobs/:id` (steps + erreurs), potentiellement SSE/WebSocket pour suivre `RUNNING` en direct |
| `/settings/system` | SystemSettingsPage | `GET/PUT /settings` |
| `/settings/reference` | ReferenceDataPage | `GET /sites`, `/depots`, `/aisles`, `/locations`, `GET /users` (web) |
| `/settings/naming` | NamingConventionsPage | `PUT /settings` (sous-ensemble naming/lot) |
| `/audit` | AuditPage | `GET /audit` — pagination 50/page, 5 filtres, `GET /audit/export.csv` |

Le préfixe d'API n'est pas fixé côté front (aucun appel réseau encore) — à définir avec le
backend (ex: `/api/v1/...`), en respectant les ressources ci-dessus.

---

## 5. Comptes de démonstration actuels (mock)

Mot de passe unique : `demo2026`

| Email | Rôle | Accès |
|---|---|---|
| `admin@inventaire.com` | SUPER_ADMIN | tous sites |
| `inv.mcd@inventaire.com` | INVENTORY_MANAGER | site-1 (Magasin Central Dakar) |
| `inv.dkr@inventaire.com` | INVENTORY_MANAGER | site-2 (PRA Dakar) |
| `audit@inventaire.com` | READONLY | tous sites (lecture seule) |

> Note : le README racine liste des emails `@pna.sn`, mais le fichier mock actuel
> (`src/mocks/users.ts`) utilise `@inventaire.com`. Vérifier avec l'équipe quelle convention est
> la cible réelle avant de câbler le backend — actuellement le code source fait foi :
> `@inventaire.com`.

12 utilisateurs mockés au total : 6 `OPERATOR` + 2 `MOBILE_MANAGER` (mobile, répartis sur 4 sites)
+ 1 `SUPER_ADMIN` + 2 `INVENTORY_MANAGER` + 1 `READONLY` (web).

---

## 6. Ce qui est simulé côté front et devra être remplacé par du réel

- **`lib/syncSimulation.ts`** : durées d'étapes et issue (`SUCCESS`/`PARTIAL`/`FAILED`, tirée
  aléatoirement 90%/7%/3%) et erreurs générées aléatoirement. Le backend doit remplacer ceci par
  la vraie intégration Sage X3 (API/DB liaison), mais l'UI attend le même contrat de données
  (`SyncStep[]`, `SyncError[]`, mêmes codes d'erreur si possible pour ne pas casser l'affichage).
- **Login** : vérifie juste un mot de passe unique en dur (`MOCK_PASSWORD`) — à remplacer par un
  vrai flux d'authentification (hash, JWT, refresh).
- **Toutes les données** (`src/mocks/*.ts`) sont la source de vérité V1 et devront être migrées
  en base réelle, alimentée initialement par un PULL X3 pour sites/dépôts/allées/emplacements et
  sessions, et par l'app mobile pour submissions/locks.
- **`isStale` des locks** est recalculé côté client à la volée — le backend peut soit exposer un
  champ déjà calculé, soit laisser le front recalculer à partir de `lastActivityAt` +
  `settings.lockTimeoutMinutes` (les deux fonctionnent, mais la source de vérité du seuil doit
  rester le `SystemSettings` serveur).

---

## 7. Conventions de code utiles au backend (cohérence de contrat)

- IDs générés côté front actuellement via `generateId(prefix)` →
  `${prefix}-${timestamp}-${random}` (ex: `sync-…`, `audit-…`, `err-…`, `step-…`). Le backend
  générera ses propres IDs (UUID recommandé) — le front n'a pas de dépendance stricte au format,
  juste besoin d'un `string` unique.
- Toutes les dates sont des **chaînes ISO 8601** (`new Date().toISOString()`).
- Nombres/pourcentages : `Variance.percent` est signé (positif = surplus, négatif = manquant) ;
  formaté côté front avec signe explicite (`formatPercent`).
- Export CSV : BOM UTF-8 requis en tête de fichier pour compatibilité Excel français.

---

## 8. Ordre de priorité suggéré pour le backend (basé sur la dépendance des écrans)

1. **Auth** (`/auth/login`) + RBAC middleware — bloque tout le reste.
2. **Référentiel** (sites/depots/aisles/locations/users) — nécessaire pour peupler tous les
   écrans, généralement alimenté par un premier PULL X3.
3. **Sessions** + transition `openToAgents`.
4. **Submissions** + workflow de review complet (approve/reject/reset/validate/send_revision).
5. **Locks** (lecture + force-release) — dépend des sessions.
6. **Perimeters** (assignation, recomptage, arbitrage) — logique la plus complexe, dépend de
   Submissions.
7. **Sync** (inbound/outbound réels vers X3) — dépend de tout le reste étant validé.
8. **Settings** + **Audit** — transverses, peuvent être développés en parallèle dès le début
   (l'audit doit être branché sur *chaque* endpoint de mutation dès sa création, pas ajouté après
   coup).

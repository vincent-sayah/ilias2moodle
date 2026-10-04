# Architecture ILIAS2Moodle

## Principe

ILIAS2Moodle sépare la lecture ILIAS, la normalisation et les écritures Moodle.

```text
           ILIAS 10
              │
      export + lectures read-only
              │
              ▼
       Python / extracteurs
              │
              ▼
       package neutralisé
       migration.json + assets
              │
              ▼
       Moodle local plugin
              │
      ┌───────┴────────┐
      │ Console V1     │
      │ Orchestrateur  │
      │ Executors      │
      └───────┬────────┘
              ▼
            Moodle
```

## Composants

### Préparation

`src/ilias2moodle/` et `tools/` lisent l'export ILIAS et, lorsque nécessaire, des informations complémentaires en lecture seule. Ils produisent le modèle neutre et les assets.

### Modèle intermédiaire

`migration.json` conserve les identifiants ILIAS, l'arbre, l'ordre et les métadonnées nécessaires à la reconstruction.

### Plugin Moodle

`moodle/local_iliasmigration/` contient :

- planners/dry-runs ;
- validateurs de package ;
- executors Moodle ;
- mapping persistant ILIAS ↔ Moodle ;
- CLI spécialisées ;
- console opérateur V1.

## Console opérateur V1

### Persistance

Trois tables dédiées :

```text
local_iliasmigration_job
  1 ───── n local_iliasmigration_step
  1 ───── n local_iliasmigration_log
```

`job` stocke l'état global, la source, la cible, la politique d'erreur et le résumé final.

`step` stocke l'ordre, l'état, le nombre de tentatives, le résultat JSON et l'erreur éventuelle.

`log` constitue le journal d'audit horodaté.

### Exécution

La console ne réalise pas une longue migration pendant la requête HTTP. Elle crée un job et met une **tâche ad hoc Moodle** en file.

```text
Interface
   ↓
QUEUED
   ↓
Moodle cron
   ↓
adhoc task
   ↓
operator_job_runner
   ↓
étapes persistantes
```

Un lock Moodle `operator_job_<id>` empêche deux workers d'exécuter le même job simultanément.

### Machine d'état

```text
NEW → QUEUED → RUNNING ─────────────→ COMPLETED
                  │
                  ├─ avertissements → COMPLETED_WARNINGS
                  │
                  ├─ erreur critique → FAILED
                  │
                  └─ erreur skippable
                        ├─ policy=continue → SKIPPED_ERROR → suite
                        └─ policy=pause    → PAUSED
                                               │
                                      Retry / Ignore
                                               │
                                             QUEUED
```

### Étapes

Le pipeline est généré d'après les familles réellement présentes dans `migration.json`.

Ordre général :

1. préflight — critique ;
2. structure — critique ;
3. ressources simples ;
4. SCORM ;
5. Learning Modules ;
6. Tests / banques ;
7. Content Pages ;
8. Glossaires ;
9. Wikis ;
10. Exercices ;
11. Forums ;
12. Mediacasts ;
13. Blogs ;
14. Media Pools ;
15. Item Groups ;
16. réconciliation d'ordre quand elle est sûre ;
17. rapport final.

Une famille absente n'ajoute aucune étape.

### Isolation des erreurs

Les executors historiques sont généralement transactionnels par **famille**. La V1 exploite ce comportement :

- une erreur d'étape rollbacke cette famille ;
- les familles déjà terminées restent en place ;
- l'opérateur peut réessayer ou ignorer une famille non critique ;
- une erreur du préflight ou de la structure arrête le job.

La future isolation « un objet Wiki précis échoue mais les autres Wikis passent » exige un refactoring en transactions objet-par-objet. Elle n'est pas annoncée comme acquise en V1.

### Reprise

`Retry from step` remet l'étape choisie et les étapes suivantes en `PENDING`. Ce choix repose sur l'idempotence des executors : les objets déjà mappés doivent revenir en UPDATE/KEEP plutôt qu'être dupliqués.

### Rapport

Le rapport final agrège :

- identité du job et SHA-256 de la source ;
- cible Moodle ;
- statut de chaque étape ;
- résultats structurés ;
- erreurs ignorées ou bloquantes ;
- journal des événements.

Il est consultable et téléchargeable en JSON.

## Règles d'architecture

1. aucune copie directe base ILIAS → base Moodle ;
2. API Moodle/File API privilégiées pour les contenus ;
3. dry-run/validation intégrés aux executors gardés ;
4. identités ILIAS conservées ;
5. mappings persistants ;
6. idempotence requise ;
7. ambiguïté = blocage explicite ;
8. aucune reconstruction historique inventée ;
9. erreur partielle visible et auditée ;
10. la reprise doit être déterministe.

## Limites V1

- entrée : package ILIAS2Moodle préparé, pas export ILIAS brut autonome ;
- isolation : famille, pas objet individuel ;
- Phase 7.1/7.4/7.6 spécialisées non encore incorporées au pipeline principal ;
- Phase 7.3 reste rapport historique ;
- avec Item Groups, l'ancien `order_reconciler` n'est pas lancé automatiquement ; l'intégration de la réconciliation V2 doit être transformée en service réutilisable avant automatisation.

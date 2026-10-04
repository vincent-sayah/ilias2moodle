# local_iliasmigration

Plugin Moodle du projet ILIAS2Moodle.

Version candidate opérateur :

```text
0.21.0-rc1
2026100401
```

Compatibilité minimale : Moodle 4.5. Qualification cible : Moodle 5.0.2 / PHP 8.3.

## Fonctions principales

Le plugin fournit :

- mapping persistant ILIAS ↔ Moodle ;
- planners/dry-runs ;
- validateurs de packages ;
- executors idempotents ;
- import structure, ressources, SCORM, Books, Quiz/qbank et objets Phase 6.5 ;
- traitements Phase 7 spécialisés ;
- console opérateur V1 avec jobs, logs, reprise et rapport.

La liste exacte des objets supportés est maintenue dans le [README racine](../../README.md) et [docs/mapping.md](../../docs/mapping.md).

## Console opérateur

Après l'upgrade Moodle :

```text
Administration du site
→ Plugins
→ Plugins locaux
→ Console opérateur ILIAS2Moodle
```

La console accepte :

- un ZIP de **package ILIAS2Moodle préparé** ;
- ou un chemin serveur vers `migration.json`.

Elle ne prépare pas encore directement un export ILIAS brut.

Les jobs sont exécutés en tâche ad hoc Moodle ; le cron doit être actif.

États de job :

```text
NEW / QUEUED / RUNNING / PAUSED / FAILED
COMPLETED / COMPLETED_WARNINGS
```

Une étape non critique peut être réessayée ou ignorée. L'isolation est par famille d'objets dans cette première version.

Voir [docs/operator-console.md](../../docs/operator-console.md).

## CLI

Les CLI existantes restent supportées.

Structure :

```bash
php local/iliasmigration/cli/import.php \
  --source=/path/to/migration.json \
  --category-path="Marine > Formation" \
  --phase=2 \
  --apply
```

Ressources/SCORM/Book/Quiz :

```bash
php local/iliasmigration/cli/import.php --source=/path/to/migration.json --category=ID --phase=3 --apply
php local/iliasmigration/cli/import.php --source=/path/to/migration.json --category=ID --phase=4 --apply
php local/iliasmigration/cli/import.php --source=/path/to/migration.json --category=ID --phase=5 --apply
php local/iliasmigration/cli/import.php --source=/path/to/migration.json --category=ID --phase=6 --apply
```

Les objets Phase 6.5 disposent aussi d'executors/CLI dédiés lorsqu'une migration ciblée est nécessaire.

## Phase 7.3

Progression et résultats sont **rapport historique uniquement** :

```text
historical_reporting_only = true
native_moodle_writes = false
apply_reason = PHASE73_HISTORICAL_REPORT_ONLY
```

## Tables

- `local_iliasmigration_map` : mappings persistants ;
- `local_iliasmigration_job` : jobs opérateur ;
- `local_iliasmigration_step` : étapes ;
- `local_iliasmigration_log` : audit.

## Principe d'écriture

Les contenus pédagogiques sont écrits via les API/outils Moodle du module cible et la File API. Les données ambiguës ou non fidèlement reproductibles sont bloquées, différées ou conservées comme historique.

# ILIAS2Moodle

> Migration contrôlée et rejouable de contenus pédagogiques **ILIAS 10** vers **Moodle 4.5+**.

ILIAS2Moodle transforme un export ILIAS en un package neutre, puis reconstruit les objets Moodle avec les API Moodle. Le projet privilégie la fidélité, l'idempotence, la traçabilité et le refus explicite des conversions approximatives.

## État du projet

- POC fonctionnel validé sur ILIAS 10.8 → Moodle 5.0.2.
- Compatibilité minimale déclarée du plugin : Moodle 4.5.
- Version de travail de la première console opérateur : **0.21.0-rc1**.
- Phase 7.3 clôturée en **rapport historique uniquement**.
- Objet ILIAS Groupe (`grp`) toujours différé dans #22.

La version `0.21.0-rc1` ajoute une première interface d'exploitation. Elle doit être qualifiée sur une VM Moodle avant promotion finale/tag.

## Chaîne de migration

```text
ILIAS 10
   │
   ├─ export natif
   └─ récupérations complémentaires read-only si nécessaires
            │
            ▼
     préparation ILIAS2Moodle
            │
            ▼
  package + migration.json
            │
            ▼
   Console opérateur Moodle
            │
     ┌──────┴────────┐
     │ job + étapes  │
     │ logs persist. │
     │ retry / ignore│
     └──────┬────────┘
            ▼
       objets Moodle
            │
            ▼
       rapport final
```

La V1 opérateur consomme un **package ILIAS2Moodle déjà préparé**. Elle n'accepte pas encore un ZIP ILIAS natif comme entrée autonome, car plusieurs familles peuvent nécessiter des récupérations complémentaires côté ILIAS (IRSS, MediaObjects, Wiki, etc.).

## Objets migrables par ILIAS2Moodle

Cette table est le résumé opérationnel de ce que le plugin sait réellement reconstruire.

| Objet ILIAS | Code | Cible Moodle | Migration validée | Console opérateur V1 | Limites principales |
|---|---|---|---|---|---|
| Catégorie / sous-catégorie | `cat` | Catégorie Moodle | Oui | Oui, via chemin de catégorie | création/choix déterministe ; ambiguïtés bloquées |
| Cours | `crs` | Cours Moodle | Oui | Oui | cours créé masqué pendant migration |
| Dossier niveau 1 | `fold` | Section | Oui | Oui | — |
| Dossier niveau 2 | `fold` | `mod_subsection` | Oui | Oui | nécessite `mod_subsection` |
| Dossier profond | `fold` | structure aplatie/synthétique | Oui, politique contrôlée | Partiel | cas non représentable bloqué plutôt qu'approximé |
| Fichier / PDF / Office / image / vidéo / audio | `file` | `mod_resource` | Oui | Oui | fichier source requis dans le package |
| URL / WebResource | `webr` | `mod_url` | Oui | Oui | lien interne réécrit uniquement si cible sûre |
| Module HTML | `htlm` | `mod_resource` | Oui | Oui | package HTML complet requis |
| SCORM | `sahs` | `mod_scorm` | Oui | Oui | historique ancien des tentatives non reconstruit |
| Learning Module ILIAS | `lm` | `mod_book` | Oui | Oui | remplacement d'un Book modifié volontairement gardé |
| Banque de questions | `qpl` | `mod_qbank` | Oui | Oui | qtypes Moodle nécessaires |
| Test | `tst` | `mod_quiz` | Oui | Oui | résultats/tentatives historiques non reconstruits |
| Content Page | `copa` | `mod_page` | Oui | Oui | assets/liens soumis aux validations du package |
| Glossaire | `glo` | `mod_glossary` | Oui | Oui | termes et médias validés |
| Wiki | `wiki` | `mod_wiki` | Oui | Oui | historique complet des révisions = historique seulement |
| Exercice | `exc` | un ou plusieurs `mod_assign` | Oui | Oui | structure/unité ; remises/notes/feedback non injectés |
| Forum | `frm` | `mod_forum` | Oui | Oui pour le conteneur | contributions/auteurs Phase 7 disponibles séparément |
| Mediacast | `mcst` | `mod_data` | Oui | Oui | POC : MP4 local + URL externe |
| Blog | `blog` | `mod_data` | Oui | Oui | auteur final réconciliable en Phase 7 |
| Media Pool | `mep` | `mod_data` | Oui | Oui | POC : images/MP4/COPage/dossiers |
| Item Group | `itgr` | Section ou `mod_subsection` structurelle | Oui | Oui | déplace les activités déjà migrées ; ne les recrée pas |
| Utilisateurs / inscriptions / rôles | — | Users + enrolments + roles | Oui | Pas encore dans l'orchestrateur V1 | CLI Phase 7.1 validée |
| Auteurs/contributions Forum | — | discussions/posts/assets | Oui | Pas encore dans l'orchestrateur V1 | nécessite mappings utilisateurs |
| Auteur/date Wiki courants | — | métadonnées Wiki | Oui | Pas encore dans l'orchestrateur V1 | historique complet non recréé |
| Progression / résultats historiques | — | Rapport historique | Oui | Rapport seulement | aucune note, tentative ou completion native écrite |
| Groupe ILIAS complet | `grp` | Aucun équivalent automatique validé | **Non** | Non | différé #22 : conteneur + membres + restrictions + contenus |

La matrice détaillée et les décisions sémantiques sont dans [`docs/mapping.md`](docs/mapping.md).

## Console opérateur V1

La console est intégrée au plugin Moodle `local_iliasmigration`.

Fonctions prévues dans `0.21.0-rc1` :

- lancement depuis **Administration du site → Plugins locaux → Console opérateur ILIAS2Moodle** ;
- import d'un ZIP de package ILIAS2Moodle préparé ou utilisation d'un `migration.json` déjà présent sur le serveur ;
- choix du chemin de catégorie Moodle cible ;
- création d'un **job de migration persistant** ;
- exécution en tâche de fond via les tâches ad hoc/cron Moodle ;
- journalisation persistante des étapes et erreurs ;
- isolation des étapes par famille d'objets ;
- politique d'erreur :
  - **pause** : l'opérateur choisit `Réessayer` ou `Ignorer et continuer` ;
  - **continue** : une étape non critique en échec est journalisée et sautée automatiquement ;
- impossibilité d'ignorer le préflight ou la structure du cours ;
- rapport final consultable et exportable en JSON ;
- verrou Moodle par job pour empêcher deux exécutions concurrentes du même job.

### Granularité d'erreur de la V1

La V1 isole les erreurs **par famille** : Wikis, Glossaires, SCORM, Tests, etc.

Exemple :

```text
Structure    SUCCESS
Ressources   SUCCESS
SCORM        SUCCESS
Wikis        FAILED  ← opérateur averti
Glossaires   PENDING

          [Réessayer]
          [Ignorer et continuer]
```

Si l'opérateur ignore l'étape Wikis, la migration continue avec les autres familles. Dans cette V1, si plusieurs Wikis appartiennent à la même étape, l'executor Wiki utilise encore une transaction de famille : l'échec d'un Wiki annule donc l'étape Wiki entière. Une isolation transactionnelle objet-par-objet est prévue pour une évolution ultérieure.

Guide : [`docs/operator-console.md`](docs/operator-console.md).

## Préparation du package

Exemple depuis une machine de préparation :

```bash
./tools/run-ilias2moodle.sh prepare-export \
  --zip=/path/to/export_ilias.zip \
  --output=/path/to/package \
  --ilias-version=10.8
```

Selon les objets présents, des récupérations complémentaires read-only peuvent être nécessaires avant d'obtenir un package complet (Exercise/IRSS, Mediacast/MediaObjects, Wiki, pièces jointes Forum...). La console V1 ne masque pas cette contrainte.

Le package doit contenir un unique `migration.json`.

## Principes de sûreté

- pas de copie directe de base ILIAS → base Moodle ;
- écritures Moodle via les API du module cible et la File API ;
- validations et fingerprints avant les écritures sensibles ;
- mappings persistants ILIAS ↔ Moodle ;
- exécutions rejouables/idempotentes ;
- opérations ambiguës ou structurellement non sûres bloquées ;
- progression/résultats Phase 7.3 conservés comme historique plutôt que reconstruits artificiellement ;
- ZIP opérateur contrôlé contre chemins absolus, traversal et liens symboliques ;
- accès à la console limité par la capability `local/iliasmigration:manage`.

## Installation développeur

```bash
git clone https://github.com/vincent-sayah/ilias2moodle.git
cd ilias2moodle
python3 -m venv .venv
source .venv/bin/activate
pip install -e ".[dev]"
```

Plugin Moodle :

```text
moodle/local_iliasmigration
```

Pour la candidate opérateur :

```text
release = 0.21.0-rc1
build   = 2026100401
```

Après copie du plugin dans Moodle :

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

Le cron Moodle doit fonctionner pour exécuter les jobs opérateur :

```bash
php admin/cli/cron.php
```

## CLI

Les CLI historiques restent disponibles pour le diagnostic, le dry-run ciblé et les opérations spécialisées. Exemple :

```bash
php local/iliasmigration/cli/import.php \
  --source=/path/to/migration.json \
  --category=ID \
  --phase=6 \
  --dry-run
```

La console opérateur réutilise les mêmes executors au lieu d'implémenter une seconde logique de migration.

## Phase 7.3

La Phase 7.3 est définitivement **historique/read-only** :

```text
historical_reporting_only = true
native_moodle_writes      = false
automatic_apply           = false
apply_reason              = PHASE73_HISTORICAL_REPORT_ONLY
```

Voir [`docs/phase7-progress-results.md`](docs/phase7-progress-results.md).

## Roadmap immédiate

Pour transformer la candidate opérateur en release stable :

1. CI complète ;
2. installation/upgrade réel sur Moodle 5.0.2 ;
3. migration end-to-end depuis un package validé ;
4. injection volontaire d'une erreur sur une famille (par exemple Wiki), puis test `Ignorer et continuer` ;
5. test `Réessayer` après correction ;
6. contrôle du rapport final ;
7. second passage/idempotence ;
8. promotion/tag après validation.

Les évolutions suivantes sont documentées dans [`docs/roadmap.md`](docs/roadmap.md).

## Licence

ILIAS2Moodle est distribué sous **GNU GPL v3.0 or later** (`GPL-3.0-or-later`).

**Copyright (C) 2026 Vincent Sayah**

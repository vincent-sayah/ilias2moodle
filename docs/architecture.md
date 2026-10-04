# Architecture

## Objectif

ILIAS2Moodle sépare volontairement l’extraction ILIAS de l’import Moodle.

```text
ILIAS 10
   │
   ├── SOAP / API
   └── Exports XML, HTML, ZIP, QTI
          │
          ▼
      Extracteur
          │
          ▼
  Modèle intermédiaire
     migration.json
          │
          ├── validation
          ├── rapport
          └── dry-run
          │
          ▼
   Importeur Moodle
          │
          ▼
      Moodle 4.5
```

## Composants

### `ilias/`

Responsable de la lecture des données ILIAS. Le reste du projet ne doit pas dépendre des structures SOAP ou XML brutes.

### `model/`

Contient le modèle neutre partagé entre extraction, conversion, rapports et import.

### `converters/`

Transforme progressivement les objets ILIAS en représentations compatibles Moodle.

### `report/`

Produit les indicateurs de migration, les avertissements et les objets non supportés.

### `moodle/local_iliasmigration/`

Plugin local Moodle chargé, à partir de la Phase 2, de créer les objets Moodle en utilisant les API Moodle.

## Règles d’architecture

1. Pas d’écriture directe dans les tables Moodle.
2. Les identifiants ILIAS sont conservés dans le modèle intermédiaire.
3. Les relations parent/enfant et l’ordre sont explicites.
4. Une migration doit pouvoir être simulée.
5. Une migration rejouée ne doit pas produire de doublons.
6. Les erreurs partielles doivent être rapportées sans masquer les objets concernés.

## Flux cible

```text
analyse ILIAS
    ↓
migration.json
    ↓
validation
    ↓
plan de migration
    ↓
import Moodle
    ↓
mapping ILIAS ID ↔ Moodle ID
    ↓
rapport final
```

## Console opérateur et orchestration persistante

À partir de `0.21.0-beta1`, le plugin Moodle ajoute une couche d'orchestration au-dessus des executors existants.

```text
migration.json
     │
     ▼
Console opérateur Moodle
     │
     ├── local_iliasmigration_run
     │
     ├── local_iliasmigration_step
     │
     └── local_iliasmigration_log
     │
     ▼
Pipeline ordonné
     │
     ├── Structure
     ├── Ressources
     ├── SCORM
     ├── Book
     ├── Questions / Quiz
     ├── Content Page
     ├── Glossaire
     ├── Wiki
     ├── Exercice
     ├── Forum
     ├── MediaCast
     ├── Blog
     ├── Media Pool
     └── Item Groups
     │
     ▼
Executors existants
     │
     ▼
Mappings + objets Moodle
     │
     ▼
Rapport HTML / JSON
```

### Principe de reprise

Le pipeline n'est pas exécuté dans une unique requête web. Une requête traite une seule famille, persiste son résultat puis redirige vers l'étape suivante.

Cette stratégie rend le run :

- reprenable ;
- observable ;
- moins sensible aux timeouts HTTP ;
- capable de s'arrêter proprement sur une erreur.

Un échec passe le run à `WAITING_DECISION`. L'opérateur peut ensuite :

```text
FAILED
  ├── retry  -> PENDING -> exécution
  └── ignore -> SKIPPED -> étape suivante
```

### Isolation des erreurs

La beta1 isole les erreurs à la granularité d'une **famille d'objet**. Chaque executor conserve sa transaction et ses garde-fous.

La granularité par `source_ref_id` est une évolution ultérieure afin de ne pas casser les invariants transactionnels déjà validés des executors existants.

### Journal et audit

Le journal opérateur ne remplace pas `local_iliasmigration_map`.

- `local_iliasmigration_map` répond à « quel objet ILIAS correspond à quel objet Moodle ? » ;
- `local_iliasmigration_run/step/log` répond à « que s'est-il passé pendant cette migration opérateur ? ».

Les deux ensembles sont complémentaires.


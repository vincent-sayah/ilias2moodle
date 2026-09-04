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

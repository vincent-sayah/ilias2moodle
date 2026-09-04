# ILIAS2Moodle

> Outil de migration semi-automatisée de contenus pédagogiques **ILIAS 10** vers **Moodle 4.5**, en conservant au mieux l’arborescence, les ressources et la logique pédagogique existantes.

## Présentation

**ILIAS2Moodle** a pour objectif de construire une moulinette de migration **contrôlée, rejouable et traçable** entre ILIAS 10 et Moodle 4.5.

Le projet suit une approche **ETL** :

```text
ILIAS 10
   ↓
Extraction
   ↓
Transformation
   ↓
migration.json
   ↓
Validation / dry-run
   ↓
Import Moodle
   ↓
Moodle 4.5
```

L’objectif n’est pas de copier directement les bases de données, mais de reconstruire les contenus Moodle en utilisant des formats intermédiaires et les API applicatives.

## Objectifs

Le projet vise à migrer progressivement :

- catégories et sous-catégories ;
- cours ;
- dossiers et sous-dossiers ;
- pages et contenus HTML ;
- fichiers, PDF, documents Office, images, audio et vidéo ;
- URL ;
- packages SCORM ;
- modules d’apprentissage ILIAS ;
- tests et banques de questions ;
- utilisateurs, inscriptions et groupes ;
- certaines données de progression lorsque cela est techniquement fiable.

## Principes

ILIAS2Moodle doit être :

- **non destructif** : aucun accès SQL direct aux tables Moodle ;
- **traçable** : chaque objet migré possède un statut et un mapping ILIAS/Moodle ;
- **rejouable** : une nouvelle exécution ne doit pas dupliquer les objets ;
- **testable** : un mode `--dry-run` doit permettre de simuler la migration ;
- **progressif** : les objets complexes sont ajoutés phase par phase ;
- **auditable** : les objets non supportés doivent apparaître dans les rapports.

## Architecture cible

```text
                    ILIAS 10
                       │
        ┌──────────────┴──────────────┐
        │                             │
   API / SOAP                   Exports ILIAS
   Métadonnées                   XML / HTML / ZIP
   Arborescence                  QTI / fichiers
        │                             │
        └──────────────┬──────────────┘
                       │
                       ▼
              Python ILIAS2Moodle
                       │
                       ▼
                 migration.json
                       │
                       ▼
               Validation / Dry-run
                       │
                       ▼
          Plugin Moodle local_iliasmigration
                       │
                       ▼
                   Moodle 4.5
```

## Format intermédiaire

La migration passe par un modèle neutre sérialisé en JSON.

Exemple :

```json
{
  "schema_version": "1.0",
  "source": {
    "lms": "ILIAS",
    "version": "10"
  },
  "course": {
    "source_id": "15342",
    "title": "Formation LMS",
    "description": "Formation de démonstration",
    "items": [
      {
        "source_id": "15343",
        "type": "folder",
        "title": "Séquence 1",
        "items": [
          {
            "source_id": "15344",
            "type": "file",
            "title": "Présentation.pdf",
            "file": "files/presentation.pdf"
          }
        ]
      }
    ]
  }
}
```

Voir [`docs/migration-format.md`](docs/migration-format.md).

## Correspondance ILIAS → Moodle

| ILIAS 10 | Moodle 4.5 | Automatisation visée |
|---|---|---|
| Catégorie | Catégorie de cours | Très élevée |
| Sous-catégorie | Sous-catégorie | Très élevée |
| Cours | Cours | Très élevée |
| Dossier | Section / Sous-section | Très élevée |
| Fichier | Ressource Fichier | Très élevée |
| URL | Ressource URL | Très élevée |
| Page ILIAS | Page / Texte et média | Élevée |
| SCORM | Activité SCORM | Très élevée |
| Module d’apprentissage | Livre Moodle | Moyenne à élevée |
| Test | Quiz Moodle | Moyenne |
| Banque de questions | Banque Moodle | Moyenne |
| Groupe | Groupe / Groupement | À étudier |
| Progression | Achèvement Moodle | Complexe |
| Historique des tentatives | Données Moodle | Très complexe |

La matrice détaillée est maintenue dans [`docs/mapping.md`](docs/mapping.md).

## Les 7 phases

### Phase 1 — Inventaire et analyse

Analyser un cours ILIAS sans écrire dans Moodle :

- découverte de l’arborescence ;
- identification des objets ;
- récupération des métadonnées ;
- comptage par type ;
- détection des objets non supportés ;
- génération de `migration.json` ;
- génération d’un rapport JSON/HTML.

### Phase 2 — Structure

Recréer dans Moodle :

- catégories ;
- sous-catégories ;
- cours ;
- sections ;
- sous-sections ;
- ordre des objets.

### Phase 3 — Ressources simples

Migrer :

- fichiers ;
- PDF ;
- documents Office ;
- images ;
- audio et vidéo ;
- URL ;
- pages HTML simples ;
- liens internes lorsque possible.

### Phase 4 — SCORM

Reprendre les packages SCORM ILIAS et les créer comme activités SCORM Moodle.

### Phase 5 — Modules d’apprentissage ILIAS

Convertir les modules d’apprentissage natifs ILIAS vers un format Moodle approprié, avec **Moodle Book** comme cible privilégiée.

### Phase 6 — Tests et banques de questions

Convertir les tests et questions ILIAS vers des formats Moodle, notamment Moodle XML, puis reconstruire banques de questions et quiz.

### Phase 7 — Utilisateurs, inscriptions et progression

Traiter séparément :

- utilisateurs ;
- inscriptions ;
- rôles ;
- groupes ;
- achèvements et progression sélectionnés.

Les données historiques trop spécifiques à ILIAS seront signalées plutôt que migrées de manière approximative.

## Arborescence du dépôt

```text
ilias2moodle/
├── README.md
├── CHANGELOG.md
├── CONTRIBUTING.md
├── .env.example
├── .gitignore
├── pyproject.toml
├── requirements.txt
├── docs/
│   ├── architecture.md
│   ├── mapping.md
│   ├── migration-format.md
│   └── roadmap.md
├── src/
│   └── ilias2moodle/
│       ├── __init__.py
│       ├── cli.py
│       ├── config.py
│       ├── ilias/
│       ├── model/
│       ├── converters/
│       ├── report/
│       └── utils/
├── moodle/
│   └── local_iliasmigration/
├── examples/
├── tests/
│   ├── unit/
│   └── integration/
└── exports/
    └── .gitkeep
```

## Installation développeur

Pré-requis :

- Python 3.11 ou supérieur ;
- environnement virtuel Python ;
- accès à une instance ILIAS 10 de test pour les phases d’intégration ;
- accès à une instance Moodle 4.5 de test pour les phases d’import.

```bash
git clone https://github.com/vincent-sayah/ilias2moodle.git
cd ilias2moodle

python3 -m venv .venv
source .venv/bin/activate
pip install -e ".[dev]"
```

Sous Windows PowerShell :

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -e ".[dev]"
```

## Configuration

Copier le modèle d’environnement :

```bash
cp .env.example .env
```

Les secrets ne doivent jamais être versionnés.

## CLI

Le squelette initial expose une commande :

```bash
ilias2moodle --help
```

Phase 1 :

```bash
ilias2moodle analyse --course 1234 --output ./exports/course-1234
```

Mode simulation :

```bash
ilias2moodle analyse --course 1234 --output ./exports/course-1234 --dry-run
```

Au début du projet, la commande fonctionne avec un adaptateur ILIAS de démonstration tant que le connecteur réel ILIAS n’est pas encore implémenté.

## Idempotence

Chaque objet source devra conserver sa correspondance Moodle :

```text
ILIAS ref_id 1234  → Moodle course 72
ILIAS ref_id 1240  → Moodle section 403
ILIAS ref_id 1241  → Moodle resource 728
```

Les actions possibles seront :

```text
CREATE
UPDATE
SKIP
ERROR
```

## Dry-run

Aucune écriture Moodle ne doit être effectuée en mode simulation.

Exemple de rapport :

```text
Cours                  : 1
Sections               : 8
Sous-sections          : 23
PDF                     : 34
Documents               : 12
URL                     : 6
SCORM                   : 5
Learning Modules        : 3
Tests                   : 4
Questions               : 126
Objets non supportés    : 2
```

## Premier POC

Le premier cours ILIAS de référence devra idéalement contenir :

```text
Cours
├── Page d’accueil
├── Dossier
│   ├── PDF
│   ├── document Office
│   └── URL
├── Sous-dossier
│   ├── image
│   └── vidéo
├── SCORM
├── Module d’apprentissage ILIAS
└── Test
    └── Banque de questions
```

## Roadmap

```text
Phase 1  [~] Inventaire et analyse
Phase 2  [ ] Structure
Phase 3  [ ] Ressources simples
Phase 4  [ ] SCORM
Phase 5  [ ] Modules d’apprentissage ILIAS
Phase 6  [ ] Tests et banques de questions
Phase 7  [ ] Utilisateurs, inscriptions et progression
```

La Phase 1 démarre avec la mise en place du modèle de données, du CLI, du rapport et du contrat du futur adaptateur ILIAS.

## Licence

La licence du projet n’est pas encore définie.

## Statut

**Projet en développement — Phase 1 : Inventaire et analyse.**

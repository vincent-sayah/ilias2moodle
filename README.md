# ILIAS2Moodle

> Outil de migration semi-automatisée de contenus pédagogiques **ILIAS 10** vers **Moodle 4.5+**, conçu pour conserver au mieux l’arborescence, les ressources et la logique pédagogique existantes.

## Présentation

**ILIAS2Moodle** construit une chaîne de migration contrôlée, rejouable, traçable et testable entre ILIAS 10 et Moodle.

Le projet suit une approche ETL :

```text
ILIAS 10
   ↓
Extraction / export natif
   ↓
Transformation
   ↓
migration.json
   ↓
Validation / dry-run
   ↓
Import Moodle
   ↓
Moodle 4.5+
```

L’objectif n’est pas de copier directement les bases de données, mais de reconstruire les contenus Moodle à partir d’un format intermédiaire neutre et des API applicatives Moodle.

## Environnement de validation

Le POC de référence a été validé sur :

- ILIAS `10.8` ;
- Moodle `5.0.2` ;
- compatibilité minimale du plugin conservée à Moodle `4.5` ;
- PHP `8.3` côté Moodle ;
- Python `3.11+` côté préparation des exports.

Cours POC principal :

- ILIAS `ref_id=128`, `obj_id=504` ;
- titre : `cours test migration` ;
- cours Moodle `id=5`, shortname `ILIAS-128`.

## Principes

ILIAS2Moodle est conçu pour être :

- **non destructif** : aucune copie directe de base à base ;
- **traçable** : chaque objet migré conserve un mapping ILIAS ↔ Moodle ;
- **rejouable** : les exécutions successives ne doivent pas créer de doublons ;
- **testable** : le mode `--dry-run` interdit les écritures Moodle ;
- **progressif** : les familles d’objets sont prises en charge phase par phase ;
- **auditable** : les cas non supportés ou ambigus sont signalés explicitement ;
- **gardé** : les situations structurelles non sûres bloquent l’apply au lieu de produire une migration approximative.

## Architecture

```text
                    ILIAS 10
                       │
                Export natif ZIP
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
                  Moodle 4.5+
```

## Format intermédiaire

La migration passe par un modèle neutre sérialisé en JSON.

Exemple simplifié :

```json
{
  "schema_version": "1.0",
  "source": {
    "lms": "ILIAS",
    "version": "10.8"
  },
  "course": {
    "source_id": "128",
    "title": "cours test migration",
    "items": [
      {
        "source_id": "230",
        "type": "folder",
        "title": "quizz",
        "items": []
      }
    ]
  }
}
```

Voir [`docs/migration-format.md`](docs/migration-format.md).

## Correspondance ILIAS → Moodle

| ILIAS 10 | Moodle | État |
|---|---|---|
| Catégorie / sous-catégorie | Catégorie de cours | Validé Phase 2 |
| Cours | Cours | Validé Phase 2 |
| Dossier niveau 1 | Section | Validé Phase 2 |
| Dossier niveau 2 | `mod_subsection` | Validé Phase 2 |
| Dossier niveau 3+ | Sous-section sœur avec titre hiérarchique | Validé Phase 2 |
| Fichier / PDF / URL / HTML simple | Ressource Moodle | Socle validé Phase 3 |
| SCORM | Activité SCORM | Validé Phase 4 |
| Module d’apprentissage ILIAS | Moodle Book | Validé Phase 5 |
| Test | Quiz Moodle | Validé Phase 6 |
| Banque de questions | Banque Moodle | Validé Phase 6 |
| Utilisateurs / inscriptions / groupes | À définir | Phase 7 |
| Progression / historique | À étudier | Phase 7 |

La matrice détaillée est maintenue dans [`docs/mapping.md`](docs/mapping.md).

## Phase 2 — Structure : terminée

La Phase 2 est clôturée depuis le **13 septembre 2026**.

Les éléments suivants sont validés sur le POC réel :

- création et mise à jour idempotentes des cours ;
- création et mise à jour des sections ;
- création et mise à jour des sous-sections Moodle ;
- mapping persistant ILIAS ↔ Moodle ;
- vrai dry-run sans écriture ;
- politique déterministe pour les dossiers ILIAS de profondeur supérieure à 2 ;
- réconciliation de l’ordre global ;
- sections synthétiques déterministes pour les ressources racine ;
- sélection ou création de catégories/sous-catégories par chemin ;
- blocage des chemins de catégories ambigus ;
- contrôle visuel et idempotence réels sur Moodle 5.0.2.

Politique des catégories :

```bash
php local/iliasmigration/cli/import.php \
  --source=/path/to/migration.json \
  --category-path="Parent > Sous-categorie" \
  --phase=2 \
  --dry-run
```

Le mode historique reste disponible :

```bash
php local/iliasmigration/cli/import.php \
  --source=/path/to/migration.json \
  --category=ID \
  --phase=2 \
  --dry-run
```

Les catégories créées automatiquement sont masquées pendant le POC. Les catégories existantes ne sont ni renommées, ni déplacées, ni masquées par le résolveur.

## Installation développeur

Pré-requis :

- Python 3.11 ou supérieur ;
- environnement virtuel Python ;
- accès à une instance ILIAS 10 de test ;
- accès à une instance Moodle 4.5+ de test.

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

## Préparation d’un export ILIAS

```bash
./tools/run-ilias2moodle.sh prepare-export \
  --zip=/path/to/export_ilias.zip \
  --output=/path/to/package \
  --ilias-version=10.8
```

Le package produit contient notamment `migration.json`, les ressources extraites et les rapports de préparation.

## Plugin Moodle

Le plugin Moodle est situé dans :

```text
moodle/local_iliasmigration
```

Version courante après clôture de la Phase 2 :

```text
0.15.0-alpha
2026091302
```

## Idempotence

Les correspondances persistantes permettent de rejouer les imports sans dupliquer les objets :

```text
ILIAS ref_id 128  → Moodle course 5
ILIAS ref_id 230  → Moodle section 22
ILIAS ref_id 237  → Moodle subsection CMID 14
ILIAS ref_id 246  → Moodle subsection CMID 38
```

Les plans utilisent notamment les états :

```text
CREATE
UPDATE
SELECT
DEFER
BLOCKED
ERROR_STALE_MAPPING
```

## Roadmap

```text
Phase 1  [x] Inventaire et analyse
Phase 2  [x] Structure
Phase 3  [~] Ressources simples — socle validé, couverture étendue à poursuivre
Phase 4  [x] SCORM
Phase 5  [x] Modules d’apprentissage ILIAS
Phase 6  [x] Tests et banques de questions
Phase 7  [ ] Utilisateurs, inscriptions et progression
```

## État actuel

**Phase 2 terminée et clôturée.**

Les validations réelles couvrent désormais la structure Moodle complète du POC, y compris les catégories, les profondeurs de dossiers supérieures à 2 et l’idempotence. La Phase 3 reste ouverte pour sa couverture étendue, et la Phase 7 n’a pas encore démarré.

## Licence

La licence du projet n’est pas encore définie.

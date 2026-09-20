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
| Fichier / PDF / URL / HTML simple | Ressource Moodle | Validé Phase 3 |
| SCORM | Activité SCORM | Validé Phase 4 |
| Module d’apprentissage ILIAS | Moodle Book | Validé Phase 5 |
| Test | Quiz Moodle | Validé Phase 6 |
| Banque de questions | Banque Moodle | Validé Phase 6 |
| Content Page | `mod_page` | Validé Phase 6.5 |
| Glossaire | `mod_glossary` | Validé Phase 6.5 |
| Wiki | `mod_wiki` | Validé Phase 6.5 |
| Exercice | `mod_assign` | Validé Phase 6.5 |
| Forum | `mod_forum` | Validé Phase 6.5 — structure ; contributions Phase 7 |
| Mediacast | `mod_data` | Validé Phase 6.5 — MP4 local + URL externe |
| Blog | `mod_data` | Validé Phase 6.5 — billets + images ; auteurs Moodle Phase 7 |
| Media Pool / galerie média | `mod_data` | Validé Phase 6.5 — images + MP4 + COPage + dossiers |
| Groupe | Groupe / Groupement + restrictions si nécessaire | Phase 7 |
| Utilisateurs / inscriptions | Comptes / inscriptions / rôles | Phase 7 |
| Progression / historique | Completion / historique | Phase 7 |

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

## Phase 3 — Ressources simples : terminée

La Phase 3 est clôturée depuis le **14 septembre 2026**.

Les validations réelles couvrent :

- URL externes → `mod_url` ;
- fichiers génériques, PDF, DOCX, PPTX, images, vidéos et audio → `mod_resource` ;
- modules HTML exportés → `mod_resource` avec paquet complet et fichier de démarrage ;
- conservation des descriptions non vides dans l’introduction Moodle ;
- placement dans section 0, section, sous-section déléguée et section synthétique ;
- validation des fichiers manquants et des chemins de package ;
- mapping persistant et idempotence `CREATE` / `UPDATE` ;
- réécriture des liens internes ILIAS `type|ref_id` vers une cible Moodle migrée lorsqu’un mapping unique et sûr existe ;
- conservation du lien ILIAS d’origine comme fallback si la cible Moodle est absente ou ambiguë.

POC final de lien interne :

```text
ILIAS ref 132 : htlm|240
        ↓
ILIAS ref 240 migré
        ↓
Moodle mod_resource CMID 20
        ↓
/mod/resource/view.php?id=20
```

Le clic sur l’activité Moodle `lien` ouvre bien la ressource `chimie`. Le dry-run après apply reste idempotent et le package Phase 3 termine avec `blocked_resources=0` et `ready=true`.

## Phase 6.5 — Extension des objets pédagogiques : planifiée

Avant de démarrer la Phase 7, le projet ajoute une étape d’extension pour migrer des objets ILIAS supplémentaires.

Issue maître : [#13 — Phase 6.5 — Extension des objets pédagogiques ILIAS](https://github.com/vincent-sayah/ilias2moodle/issues/13).

Ordre retenu :

1. [#14 Content Page](https://github.com/vincent-sayah/ilias2moodle/issues/14) → `mod_page` ;
2. [#15 Glossaire](https://github.com/vincent-sayah/ilias2moodle/issues/15) → `mod_glossary` ;
3. [#16 Wiki](https://github.com/vincent-sayah/ilias2moodle/issues/16) → `mod_wiki` ;
4. [#17 Exercice](https://github.com/vincent-sayah/ilias2moodle/issues/17) → `mod_assign` ;
5. [#18 Forum](https://github.com/vincent-sayah/ilias2moodle/issues/18) → `mod_forum` ;
6. [#19 Mediacast](https://github.com/vincent-sayah/ilias2moodle/issues/19) → `mod_data` ;
7. [#20 Blog](https://github.com/vincent-sayah/ilias2moodle/issues/20) → `mod_data` ;
8. [#21 Media Pool / galerie média](https://github.com/vincent-sayah/ilias2moodle/issues/21) → `mod_data`.

L’objet ILIAS Groupe reste volontairement dans la Phase 7 (#7), car sa migration complète dépend des utilisateurs, des membres, des inscriptions et des groupements.

La stratégie détaillée est documentée dans [`docs/phase6-5-extended-objects.md`](docs/phase6-5-extended-objects.md).

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

Version courante :

```text
0.16.19-alpha
2026092002
```

La Phase 6.5 dispose désormais d’implémentations fonctionnelles validées sur le POC réel pour Content Page, Glossaire, Wiki, Exercice, Forum, Mediacast, Blog et Media Pool. Pour le Forum, le conteneur `mod_forum` est migré en Phase 6.5 tandis que discussions, messages et pièces jointes de contributions restent conservés dans le package neutre et reportés à la Phase 7 tant que les auteurs ne sont pas rapprochés de façon fiable. Pour le Mediacast, le périmètre validé couvre les MP4 locaux et les URL externes : un Mediacast devient un `mod_data`, chaque entrée devient un record, les MP4 sont stockés via la Files API et lus dans un lecteur HTML5 intégré. Pour le Blog, un Blog devient un `mod_data` et chaque billet devient un record ; le contenu COPage, les grilles et les images locales sont conservés, tandis que l’identifiant auteur ILIAS est stocké sans attribution artificielle à un utilisateur Moodle avant la Phase 7. Pour le Media Pool, un `mep` devient un `mod_data`, chaque nœud de contenu devient un record, les dossiers sont conservés via `folder_path`, les PNG et MP4 du POC sont stockés via Moodle Files API et les COPage internes sont rendues dans les records.

## Idempotence

Les correspondances persistantes permettent de rejouer les imports sans dupliquer les objets :

```text
ILIAS ref_id 128  → Moodle course 5
ILIAS ref_id 230  → Moodle section 22
ILIAS ref_id 237  → Moodle subsection CMID 14
ILIAS ref_id 246  → Moodle subsection CMID 38
ILIAS ref_id 240  → Moodle resource CMID 20
ILIAS ref_id 269  → Moodle resource CMID 43
ILIAS ref_id 275  → Moodle forum CMID 59
ILIAS ref_id 276  → Moodle database CMID 60
ILIAS ref_id 247  → Moodle database CMID 61
ILIAS ref_id 278  → Moodle database CMID 62
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
Phase 1    [x] Inventaire et analyse
Phase 2    [x] Structure
Phase 3    [x] Ressources simples
Phase 4    [x] SCORM
Phase 5    [x] Modules d’apprentissage ILIAS
Phase 6    [x] Tests et banques de questions
Phase 6.5  [x] Extension des objets pédagogiques
Phase 7    [ ] Utilisateurs, inscriptions, groupes et progression
```

## État actuel

**Phases 1 à 6 terminées et validées sur le POC de référence.**

La **Phase 6.5 est terminée** : Content Page, Glossaire, Wiki, Exercice, Forum, Mediacast, Blog et Media Pool sont validés sur le POC réel. La prochaine étape active est la **Phase 7**, consacrée notamment aux utilisateurs, inscriptions, groupes, auteurs, remises, contributions et autres données nécessitant un rapprochement d’identité fiable.

## Licence

ILIAS2Moodle est distribué sous la licence **GNU General Public License v3.0 or later** (`GPL-3.0-or-later`).

**Copyright (C) 2026 Vincent Sayah**

Toute redistribution du projet doit conserver les mentions de copyright et de licence applicables. Les versions modifiées redistribuées doivent respecter les obligations de la GPL, notamment l’indication des modifications et la mise à disposition du code source correspondant dans les conditions prévues par la licence.

Le texte complet de la licence est disponible dans [`LICENSE`](LICENSE).

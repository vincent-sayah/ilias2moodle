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

## Phase 6.5 — Extension des objets pédagogiques : terminée

La Phase 6.5 a étendu la couverture du POC à plusieurs objets pédagogiques ILIAS supplémentaires avant la Phase 7.

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

L’objet ILIAS Groupe complet reste volontairement différé dans #22 : un Moodle Group simple n’est pas un équivalent fonctionnel complet d’un objet ILIAS `grp` pouvant contenir des ressources, activités et restrictions.

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
0.20.0-rc2
2026092605
```

La Phase 6.5 dispose d’implémentations fonctionnelles validées sur le POC réel pour Content Page, Glossaire, Wiki, Exercice, Forum, Mediacast, Blog et Media Pool. Les dépendances d’identité différées ont ensuite été traitées en Phase 7 : auteurs et contributions Forum, auteurs Blog, ainsi que l’auteur courant et les dates des pages Wiki. Les données ne disposant pas d’un équivalent sûr restent explicitement classées `HISTORY_ONLY`, `NO_DATA` ou `DEFERRED`.

## Phase 7 — Utilisateurs, contributions et progression

La Phase 7 est validée sur le périmètre du POC réel.

Résultats validés :

- extraction complémentaire en lecture seule des identités et memberships absents du ZIP natif ;
- rapprochement déterministe par override explicite, login unique puis email unique ;
- interdiction du rapprochement automatique par nom/prénom ;
- support fonctionnel des emails partagés lorsque Moodle est configuré avec `allowaccountssameemail=1` ;
- mapping administrateur global ILIAS `usr_id=6/root` vers Moodle `user id=2/admin` ;
- création des comptes `stagiaire.1`, `stagiaire.2` et `stagiaire.10` ;
- inscription des trois membres au cours Moodle `5` avec le rôle `student` ;
- mappings persistants utilisateurs et inscriptions ;
- mots de passe initiaux générés aléatoirement, non journalisés et changement forcé au premier accès ;
- second apply idempotent : aucun compte ni enrolment supplémentaire créé.

Les auteurs/contributions Forum, Blog et Wiki ont été validés. La progression et les résultats disponibles ont été inventoriés et classifiés. Deux extensions restent hors périmètre de la RC : l’objet Groupe complet (#22) et le POC avancé de progression multi-utilisateurs (#41).

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
Phase 7    [x] Utilisateurs, inscriptions et progression du POC
  Phase 7.1 [x] Utilisateurs, inscriptions et rôles
  Phase 7.3 [x] Progression/résultats du POC analysés et classifiés
  Phase 7.4 [x] Forum : auteurs, posts et pièces jointes
  Phase 7.5 [x] Blog : auteurs
  Phase 7.6 [x] Wiki : auteur courant et dates
```

## État actuel

**Les phases 1 à 7 du POC de référence sont terminées et validées.**

La **Phase 6.5 est terminée** : Content Page, Glossaire, Wiki, Exercice, Forum, Mediacast, Blog et Media Pool sont validés sur le POC réel. La **Phase 7 du POC courant est clôturée** : utilisateurs, inscriptions, rôles, progression/résultats disponibles, auteurs et contributions Forum/Blog/Wiki ont été analysés ou migrés avec contrôle d'idempotence.

Deux sujets restent volontairement hors du périmètre de la release candidate du POC :
- l'objet ILIAS Groupe complet (#22), classé DEFERRED / HISTORY_ONLY tant qu'un mapping conteneur + contenus + restrictions n'est pas validé ;
- les tests avancés de progression multi-utilisateurs (#41), qui nécessitent un POC enrichi.

La release candidate courante est `0.20.0-rc2`. Elle corrige le défaut d'installation neuve MySQL/MariaDB découvert lors du test de `v0.20.0-rc1` sur une Moodle 5.0.2 vierge (#50). Le tag `v0.20.0-rc1` reste conservé pour la traçabilité.

## Licence

ILIAS2Moodle est distribué sous la licence **GNU General Public License v3.0 or later** (`GPL-3.0-or-later`).

**Copyright (C) 2026 Vincent Sayah**

Toute redistribution du projet doit conserver les mentions de copyright et de licence applicables. Les versions modifiées redistribuées doivent respecter les obligations de la GPL, notamment l’indication des modifications et la mise à disposition du code source correspondant dans les conditions prévues par la licence.

Le texte complet de la licence est disponible dans [`LICENSE`](LICENSE).

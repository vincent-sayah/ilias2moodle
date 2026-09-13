# Phase 2 — Reconstruction de la structure Moodle

## Environnement POC Moodle

Validation de référence :

- Moodle `5.0.2 (Build: 20250811)` ;
- PHP CLI `8.3.26` ;
- module `subsection` présent et actif (`visible=1`) ;
- compatibilité minimale du plugin conservée à Moodle 4.5.

## Structure déjà validée

La Phase 2 reconstruit la structure Moodle avant l'import des contenus pédagogiques :

- cours ILIAS → cours Moodle ;
- dossier ILIAS niveau 1 → section Moodle ;
- dossier ILIAS niveau 2 → `mod_subsection` Moodle ;
- mapping persistant ILIAS `ref_id` ↔ identifiant Moodle ;
- dry-run sans écriture ;
- CREATE / UPDATE idempotents ;
- ordre global réconcilié après les Phases 2 à 6.

Le POC `course-128-v5` a validé l'ordre final, la conservation des activités Moodle hors migration et la création idempotente d'une section synthétique `Contenu` pour les activités ILIAS racine situées après un dossier.

## Politique des dossiers de profondeur supérieure à 2

Moodle ne permet pas d'imbriquer récursivement des `mod_subsection`. La politique ILIAS2Moodle est donc un aplatissement contrôlé et déterministe.

Exemple ILIAS :

```text
Dossier niveau 1
└── Dossier niveau 2
    ├── ressource A
    └── Dossier niveau 3
        ├── ressource B
        └── Dossier niveau 4
            └── ressource C
```

Représentation Moodle :

```text
Section : Dossier niveau 1
├── Sous-section : Dossier niveau 2
│   └── ressource A
├── Sous-section : Dossier niveau 2 / Dossier niveau 3
│   └── ressource B
└── Sous-section : Dossier niveau 2 / Dossier niveau 3 / Dossier niveau 4
    └── ressource C
```

Règles :

- tous les dossiers ILIAS de profondeur 2 ou plus deviennent des `mod_subsection` sœurs sous la section Moodle issue du dossier de niveau 1 ;
- les dossiers de profondeur 3+ reçoivent un titre hiérarchique déterministe ;
- les `source_id` / `ref_id` ILIAS ne sont jamais modifiés ;
- les ressources directes restent rattachées au dossier source le plus proche ;
- la transformation est effectuée uniquement en mémoire côté Moodle ; le `migration.json` source n'est jamais réécrit ;
- les métadonnées ajoutées conservent la profondeur, le parent et le titre ILIAS d'origine ;
- le plan expose un résumé dans `source.transformations.folder_flattening`.

Cette politique évite `FLATTEN_REQUIRED` tout en restant compatible avec les API Moodle et la table de mapping existante.

## Limite d'ordre assumée

Lorsqu'un dossier ILIAS profond est intercalé entre deux activités directes de son dossier parent, Moodle ne peut pas représenter exactement cette imbrication puisque le dossier profond devient une sous-section sœur.

La politique retenue est :

- conserver l'ordre relatif des activités directes dans leur sous-section ;
- placer les sous-sections profondes immédiatement après leur ancêtre de niveau 2, dans un ordre profondeur d'abord déterministe ;
- rendre la transformation explicite dans le rapport de normalisation.

## Sécurité et idempotence

Le dry-run doit rester strictement sans écriture. Les écritures réelles utilisent les API Moodle de cours et de modules ; aucun contournement direct des séquences Moodle n'est autorisé.

Chaque dossier profond conserve son `ref_id`, de sorte qu'un second import retrouve le même mapping `targettype=subsection` et effectue un UPDATE au lieu de créer un doublon.

## Test automatisé

Le test exécutable :

```bash
php tests/php/test_folder_flattener.php
```

valide notamment :

- profondeur 3 et 4 ;
- promotion en sous-sections sœurs ;
- titres hiérarchiques ;
- conservation des enfants directs ;
- conservation des parents/profondeurs source dans les métadonnées ;
- résumé déterministe de la transformation.

La CI GitHub exécute ce test en plus de la syntaxe PHP, de Ruff et de Pytest.

## Point Phase 2 restant après validation du POC profondeur

Une fois cette politique validée sur le vrai cours ILIAS enrichi et sur Moodle 5.0.2, le dernier chantier de la Phase 2 sera la création/sélection automatique des catégories et sous-catégories Moodle. Le paramètre `--category=<id>` reste pour l'instant obligatoire.

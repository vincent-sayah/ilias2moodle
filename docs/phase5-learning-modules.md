# Phase 5 — Modules d'apprentissage ILIAS natifs

## Objectif

Convertir les Learning Modules natifs ILIAS (`lm`) vers une représentation neutre, puis vers Moodle Book.

## POC réel ILIAS 10.8

Cours source : `ref_id=128`, `obj_id=504`, `cours test migration`.

Export v4 : `1788613890__0__crs_504.zip`.

Learning Module :

- `ref_id=243` ;
- `obj_id=721` ;
- titre : `module ilias` ;
- description : `ceci est un module de test pour la migration` ;
- export interne : `set_10/1788613890__0__lm_721`.

Structure observée :

- `components/ILIAS/LearningModule/set_0/export.xml` : métadonnées et arbre `lm_tree` ;
- `components/ILIAS/COPage/set_0/export.xml` : contenu des pages ;
- `components/ILIAS/MediaObjects/set_0/export.xml` : médias ;
- `components/ILIAS/File/set_0/export.xml` : fichiers intégrés ;
- `components/ILIAS/MetaData/...` : métadonnées LOM ;
- `components/ILIAS/Style/...` : styles ILIAS.

Arbre POC :

```text
module ilias
├── Chapitre1 (st 2425)
│   ├── Nouvelle page (pg 2426)
│   └── page2 (pg 2427)
└── Chapitre 2 (st 2428)
    └── page 1 (pg 2429)
```

Contenus représentatifs observés :

- paragraphes ;
- média via `MediaAlias OriginId=il_0_mob_722` ;
- tableau ;
- section `Characteristic=Remark` ;
- `FileList` avec deux PDF ;
- image JPEG `vince.jpg`.

## Représentation neutre

Le parseur produit temporairement `learning_module_structure` avec :

- paramètres du Learning Module ;
- nœuds `root`, `chapter`, `page` avec parent, profondeur et ordre ;
- pages indexées par identifiant ILIAS ;
- blocs de contenu typés (`paragraph`, `media`, `file_list`, `table`, `section`, `internal_link`, `unsupported`) ;
- inventaire des médias et fichiers ;
- liste explicite des composants non supportés.

Pendant `prepare-export`, cette structure est écrite dans :

```text
learning_modules/<ref_id>/structure.json
```

Les ressources sont extraites dans :

```text
learning_modules/<ref_id>/media/<mob_id>/...
learning_modules/<ref_id>/files/<file_id>/...
```

`migration.json` conserve ensuite une référence compacte via :

```json
{
  "migration_structure_path": "learning_modules/243/structure.json"
}
```

## Étape Moodle suivante

La création `mod_book` n'est pas encore activée. La prochaine étape consiste à :

1. valider le `structure.json` produit par le POC réel ;
2. définir le rendu HTML des blocs ;
3. définir la représentation des chapitres ILIAS dans Moodle Book ;
4. déposer médias et fichiers via la File API Moodle ;
5. créer le Book et ses chapitres via les API Moodle ;
6. vérifier idempotence et réécriture des liens internes.

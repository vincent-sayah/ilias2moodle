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

## Représentation neutre validée

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

Validation réelle v4 :

- `learning_module_structures=1` ;
- `learning_module_media_files=1` ;
- `learning_module_files=2` ;
- `missing_count=0` ;
- 6 nœuds : 1 root, 2 chapitres, 3 pages ;
- 1 média : `vince.jpg` ;
- 2 PDF intégrés ;
- `unsupported_components=[]`.

## Phase 5 Moodle dry-run

Plugin : `local_iliasmigration` `0.9.0-alpha`.

Commande :

```bash
php local/iliasmigration/cli/import.php \
  --source=/opt/ilias2moodle-data/course-128-v4/migration.json \
  --category=1 \
  --phase=5 \
  --dry-run
```

Le dry-run :

1. reconstruit le plan Phase 2/3/4 afin de vérifier qu'aucun objet plus ancien n'est en attente ;
2. vérifie que `mod_book` est installé et activé ;
3. résout l'action du Learning Module en `CREATE` ou `UPDATE` via la table de mapping ;
4. valide le chemin et le JSON `structure.json` ;
5. valide l'identité ILIAS, l'arbre, les parents et les pages ;
6. vérifie tous les médias et fichiers référencés ;
7. vérifie les références page -> média/fichier ;
8. bloque explicitement tout composant `unsupported` ;
9. produit une prévisualisation déterministe de la future navigation Moodle Book.

### Politique de navigation POC

Moodle Book ne possède qu'un indicateur `subchapter` et ne reproduit pas un arbre arbitraire. Pour la structure POC validée :

- chaque chapitre ILIAS devient un marqueur de chapitre Moodle Book (`subchapter=0`) ;
- chaque page ILIAS du chapitre devient une entrée Moodle Book en sous-chapitre (`subchapter=1`).

Le POC doit donc produire cinq entrées :

```text
1. Chapitre1          (chapter marker)
2. Nouvelle page      (subchapter)
3. page2              (subchapter)
4. Chapitre 2         (chapter marker)
5. page 1             (subchapter)
```

Cette politique conserve les titres de chapitres et les titres de pages dans la table des matières. Les profondeurs ILIAS supérieures devront être traitées par une politique de réduction/flattening explicite avant généralisation.

## Prérequis v4

L'export v4 contient plus d'objets que le v3, notamment un deuxième URL et un deuxième SCORM. Le dry-run Phase 5 doit donc signaler tout objet Phase 2/3/4 encore en `CREATE`, `BLOCKED`, `CONFLICT` ou mapping invalide.

Un futur apply Book ne sera autorisé que lorsque :

- tous les objets Phase 2/3/4 du même export v4 sont synchronisés ;
- `phase3_package.ready=true` ;
- `phase4_package.ready=true` ;
- `phase5_prerequisites.ready=true` ;
- `phase5_package.structure_checks_ready=true` ;
- aucun Learning Module n'est bloqué.

## Moodle Book — API cible

La création réelle n'est pas encore activée. Moodle Book fournit le module `mod_book`; les chapitres sont stockés dans `book_chapters` avec notamment `pagenum`, `subchapter`, `title`, `content` et `contentformat`. Les fichiers de chapitre sont servis par la zone File API `mod_book/chapter`.

L'apply futur devra :

1. créer/mettre à jour le module via les API Moodle de module (`create_module()` / `update_module()`) ;
2. rendre les blocs neutres en HTML Moodle ;
3. créer les entrées de navigation Book selon la politique validée ;
4. déposer médias et fichiers via la File API Moodle dans la zone de chapitre ;
5. réécrire les références internes vers les chapitres Moodle ;
6. préserver le même CMID en UPDATE ;
7. vérifier absence de doublons et conservation des contenus lors d'un deuxième apply.

## Sécurité

`--phase=5 --apply` est volontairement refusé dans `0.9.0-alpha`. La première validation Moodle doit obligatoirement être un dry-run sur le package v4 réel.

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
- parent : dossier ILIAS `ref_id=230` (`quizz`) ;
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

- 7 paragraphes ;
- 1 média via `MediaAlias OriginId=il_0_mob_722` ;
- 1 tableau ;
- 1 section `Characteristic=Remark` ;
- 1 `FileList` avec deux PDF ;
- image JPEG `vince.jpg` ;
- aucun `internal_link` ;
- aucun composant `unsupported`.

## Représentation neutre validée

Le parseur produit `learning_module_structure` avec :

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

Validation réelle v4 effectuée sur ILIAS 10.8 :

- `learning_module_structures=1` ;
- `learning_module_media_files=1` ;
- `learning_module_files=2` ;
- `missing_count=0` ;
- 6 nœuds : 1 root, 2 chapitres, 3 pages ;
- 1 média : `vince.jpg` ;
- 2 PDF intégrés ;
- `unsupported_components=[]`.

## Phase 5 Moodle dry-run

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
3. vérifie que le `booktool_importhtml` core est disponible ;
4. résout l'action du Learning Module en `CREATE` ou `UPDATE` via la table de mapping ;
5. valide le chemin et le JSON `structure.json` ;
6. valide l'identité ILIAS, l'arbre, les parents et les pages ;
7. vérifie tous les médias et fichiers référencés ;
8. vérifie les références page -> média/fichier ;
9. bloque explicitement tout composant `unsupported` ;
10. produit une prévisualisation déterministe de la future navigation Moodle Book.

### Validation réelle Moodle 5.0.2 — export v4

Le dry-run réel est entièrement prêt :

- `book_available=true` ;
- `book_importhtml_available=true` ;
- Learning Module `ref_id=243` en `CREATE` avant le premier apply ;
- `structure_validation.status=OK` ;
- 6 nœuds, 2 chapitres, 3 pages ;
- 1 média, 2 fichiers, 3 assets vérifiés ;
- 0 composant non supporté ;
- 0 lien interne ;
- 5 chapitres/sous-chapitres Moodle prévus ;
- `phase3_package.ready=true` ;
- `phase4_package.ready=true` ;
- `phase5_prerequisites.ready=true` ;
- `phase5_package.ready=true`.

Le même export v4 a également validé la synchronisation des nouveaux prérequis : URL `244` et SCORM 2004 `242`.

## Politique de navigation POC

Moodle Book ne possède qu'un indicateur `subchapter` et ne reproduit pas un arbre arbitraire. Pour la structure POC validée :

- chaque chapitre ILIAS devient un marqueur de chapitre Moodle Book (`subchapter=0`) ;
- chaque page ILIAS du chapitre devient une entrée Moodle Book en sous-chapitre (`subchapter=1`).

Le POC produit exactement cinq entrées :

```text
1. Chapitre1          (chapter marker)
2. Nouvelle page      (subchapter)
3. page2              (subchapter)
4. Chapitre 2         (chapter marker)
5. page 1             (subchapter)
```

Cette politique conserve les titres de chapitres et les titres de pages dans la table des matières. Les profondeurs ILIAS supérieures devront être traitées par une politique de réduction/flattening explicite avant généralisation.

## Phase 5 apply — `0.10.2-alpha`

Commande :

```bash
php local/iliasmigration/cli/import.php \
  --source=/opt/ilias2moodle-data/course-128-v4/migration.json \
  --category=1 \
  --phase=5 \
  --apply
```

L'apply :

1. reconstruit et revalide les plans/packages Phase 3, 4 et 5 juste avant écriture ;
2. exige `phase5_package.ready=true` et aucun prérequis en attente ;
3. exige le `booktool_importhtml` core ;
4. refuse pour l'instant les `internal_link` tant que leur cible n'est pas normalisée/testée ;
5. crée l'activité `mod_book` via `create_module()` ;
6. rend les blocs neutres en HTML : paragraphes, image/média, listes de fichiers, tableaux et sections ;
7. construit un ZIP d'import déterministe avec les cinq fichiers HTML et les assets référencés ;
8. confie la création des chapitres au plugin Moodle core `booktool_importhtml` ;
9. laisse ainsi Moodle gérer `book_chapters`, les événements de création et la File API `mod_book/chapter` ;
10. vérifie le CMID/instance, la section, l'ordre, les titres, `subchapter`, les chemins `importsrc` et le nombre de fichiers File API ;
11. enregistre le mapping `targettype=book` sur le CMID Moodle.

Le plugin `local_iliasmigration` ne fait donc pas d'INSERT/UPDATE direct sur `book_chapters`. Le seul DML propre au plugin reste sa table de mapping.

## Validation réelle du premier apply Moodle 5.0.2

Le premier `--phase=5 --apply` réel est validé :

- `requested_action=CREATE` ;
- `action=CREATED` ;
- CMID Book `24` ;
- instance Book `2` ;
- section Moodle `1`, correspondant au dossier ILIAS `quizz` ;
- empreinte source `64835eb43e23443d23bfe7ba5fc71337340ef1133e2ccb92f7bb167848a55e61` ;
- `content_reimported=true` ;
- révision Book `1` ;
- `moodle_chapter_count=5` ;
- `moodle_subchapter_count=3` ;
- `moodle_chapter_file_count=3`.

La table des matières réellement créée est :

```text
1. Chapitre1       subchapter=0
2. Nouvelle page   subchapter=1
3. page2           subchapter=1
4. Chapitre 2      subchapter=0
5. page 1          subchapter=1
```

Les chemins `importsrc` des cinq chapitres contiennent tous la même empreinte source et restent déterministes.

### File API réellement vérifiée

La zone `mod_book/chapter` contient exactement trois fichiers :

```text
chapter=2 /assets/media/722/vince.jpg                         186497 octets
chapter=3 /assets/files/723/devoirs CP MON Petit CéP.pdf     379705 octets
chapter=3 /assets/files/724/dossier-31316547.pdf               60152 octets
```

Le diagnostic confirme donc :

- `vince.jpg` attaché au chapitre `Nouvelle page` ;
- les deux PDF attachés au chapitre `page2` ;
- aucun fichier File API supplémentaire créé au premier passage.

## Idempotence réelle validée

Chaque import Book reçoit une empreinte SHA-256 calculée à partir :

- des nœuds/pages ;
- des descripteurs média/fichier ;
- du contenu binaire réel de chaque asset.

Cette empreinte est intégrée aux chemins `importsrc` déterministes des chapitres.

Le deuxième apply réel, sans modification de la source, a confirmé :

- `requested_action=UPDATE` ;
- `action=UPDATED` ;
- même CMID `24` ;
- même instance Book `2` ;
- même section Moodle `1` ;
- même empreinte source ;
- `content_reimported=false` ;
- révision Book toujours `1` ;
- toujours 5 chapitres ;
- toujours 3 sous-chapitres ;
- toujours 3 fichiers File API ;
- aucun chapitre ni fichier dupliqué.

Un deuxième diagnostic DB/File API après cet UPDATE confirme encore exactement 5 chapitres et 3 fichiers.

Si la source pédagogique a changé, `0.10.2-alpha` refuse volontairement l'UPDATE des chapitres plutôt que de les dupliquer ou d'écrire directement dans `book_chapters`. Le remplacement sûr d'un Book déjà migré sera une évolution ultérieure.

Les changements limités au nom/à la description de l'activité peuvent être appliqués via `update_module()` lorsque le contenu des chapitres est inchangé.

## API Moodle utilisée

Moodle 5.0.2 fournit :

- `create_module()` / `update_module()` pour l'activité `mod_book` ;
- File API ;
- le plugin core `booktool_importhtml` pour importer les chapitres HTML et leurs fichiers.

Moodle Book ne fournit pas d'API publique CRUD de chapitre séparée dans cette version ; son écran core `mod/book/edit.php` manipule lui-même `book_chapters`. Pour respecter l'architecture du projet, l'exécuteur Phase 5 délègue donc la création des chapitres au propre outil d'import Moodle au lieu de reproduire ces écritures dans `local_iliasmigration`.

## Sécurité et limites actuelles

- aucun composant `unsupported` n'est accepté ;
- les liens internes ILIAS sont refusés en apply tant que leur réécriture n'a pas été validée ;
- les chemins assets sont revalidés dans le package juste avant utilisation ;
- un Book déjà mappé dont l'empreinte pédagogique diffère est refusé pour éviter les doublons ;
- le cours reste masqué pendant le POC ;
- la politique d'ordre des activités racine et les profondeurs Book supérieures restent des sujets distincts.

## Critère de sortie POC

Validé techniquement par CLI/DB/File API :

- [x] `CREATED` vers un `mod_book` Moodle ;
- [x] 5 entrées de table des matières dans l'ordre attendu ;
- [x] `vince.jpg` et les deux PDF présents via File API ;
- [x] deuxième apply en `UPDATED` avec le même CMID et la même instance ;
- [x] aucun chapitre ni fichier dupliqué.

Reste à confirmer dans l'interface Moodle :

- [ ] rendu visuel des paragraphes, image, tableau, section et FileList ;
- [ ] ouverture effective de l'image et des deux PDF par les URLs Moodle ;
- [ ] navigation Moodle Book utilisateur.

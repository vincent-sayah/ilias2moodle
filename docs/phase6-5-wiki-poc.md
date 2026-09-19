# Phase 6.5.3 — Wiki ILIAS vers Moodle Wiki

## Objectif

La Phase 6.5.3 migre un Wiki ILIAS vers `mod_wiki` en conservant l'état courant des pages, leur contenu, les liens entre pages et les médias/pièces jointes utiles.

La validation a été réalisée sur un POC réel ILIAS 10.8 puis sur Moodle 5.0.2.

## Format d'export ILIAS validé

Le Wiki est exporté dans un ExportSet de type `wiki`.

Les composants réellement observés sont notamment :

- `components/ILIAS/Wiki/set_0/export.xml` pour les métadonnées du Wiki et les pages `wpg` ;
- `components/ILIAS/COPage/set_0/export.xml` pour les contenus courants `wpg:<page_id>` ;
- `components/ILIAS/MediaObjects` pour les médias ;
- `components/ILIAS/File` pour les fichiers liés.

Les liens entre pages Wiki ne sont pas exportés comme des éléments `IntLink`. Ils restent dans les paragraphes sous la syntaxe Wiki native, par exemple :

- `[[page2]]` ;
- `lien vers [[page1]]`.

Le parseur convertit cette syntaxe en liens internes structurés et résout la cible par le titre vers l'identifiant source de la page.

Les liens externes observés peuvent utiliser un attribut limité au schéma, par exemple :

`<ExtLink Href="https://">www.google.com</ExtLink>`

Le parseur reconstruit alors l'URL complète, par exemple `https://www.google.com`.

## Politique historique

L'export XML courant ne transporte pas les anciennes révisions COPage ni leurs auteurs.

La migration applique donc explicitement la politique suivante :

- `history.migration_policy = current_pages_only` ;
- `history.authors_migrated = false`.

Les anciennes révisions ILIAS ne sont pas recréées dans Moodle.

Lors de la création d'une page Wiki, Moodle crée nativement une version 0 vide. Le premier contenu migré devient la version 1. Un second import identique ne crée pas de nouvelle version.

## POC réel ILIAS

Source :

- cours ILIAS : ref_id 128, obj_id 504 ;
- section parente : ref_id 271 ;
- Wiki : ref_id 273, obj_id 801 ;
- titre : `wiki test migration` ;
- page de départ : `page1`.

Export validé :

`/vagrant/1789802173__0__crs_504.zip`

Structure du Wiki :

- 3 pages : source 11 `page1`, source 12 `page2`, source 13 `page3` ;
- 4 liens internes :
  - page1 -> page2 ;
  - page1 -> page3 ;
  - page2 -> page1 ;
  - page2 -> page3 ;
- 1 média : `duo3.png` ;
- 3 fichiers :
  - `install niveau securite BASIC.docx` ;
  - `schema principe.pptx` ;
  - `1511574575_PLB CONSULTANT_DA_42397321_sign.pdf` ;
- aucun composant Page Editor non supporté.

Package normalisé :

`/opt/ilias2moodle-data/course-128-v11`

Le Wiki est stocké sous :

- `wikis/273/structure.json` ;
- `wikis/273/media/802/duo3.png` ;
- `wikis/273/files/803/...` ;
- `wikis/273/files/804/...` ;
- `wikis/273/files/805/...`.

## Politique incrémentale

Les exports de cours ILIAS successifs ne garantissent pas que tous les anciens binaires des phases précédentes soient à nouveau présents.

La Phase 6.5.3 valide donc les prérequis déjà terminés via l'état persistant Moodle et les mappings existants, sans exiger la réexportation des anciens binaires hors périmètre.

Pour le Wiki 273, le parent 271 doit néanmoins être résolu vers une section ou sous-section Moodle valide avant toute écriture.

POC validé :

- parent ILIAS 271 -> section Moodle id 30 ;
- numéro de section Moodle : 1.

## Dry-run réel Moodle

Version du plugin :

- release : `0.16.13-alpha` ;
- build : `2026091902`.

Dry-run initial :

- Wiki 273 : `CREATE` ;
- 3 pages : `CREATE` ;
- 4 liens internes ;
- 4 assets ;
- aucun blocage ;
- `ready=true` ;
- `apply_ready=true`.

Répartition des assets :

- page1 : 1 asset ;
- page2 : 3 assets ;
- page3 : 0 asset.

## Premier apply réel

Résultat :

- Wiki source 273 -> Moodle CMID 55 ;
- instance Wiki : 1 ;
- subwiki : 1 ;
- section Moodle : 1 ;
- 3 pages créées ;
- 4 fichiers stockés et vérifiés.

Mappings persistants :

- `273 -> 55` (`wiki`) ;
- `273:page:11 -> 1` (`wiki_page`) ;
- `273:page:12 -> 2` (`wiki_page`) ;
- `273:page:13 -> 3` (`wiki_page`).

Liens Moodle vérifiés :

- page1 -> page 2 et page 3 ;
- page2 -> page 1 et page 3 ;
- page3 : aucun lien interne.

Les 4 fichiers stockés dans `mod_wiki/attachments` ont été vérifiés avec leur taille et leur SHA1. Pour ce POC, les quatre correspondent exactement aux binaires source (`verification_mode=source`).

## Validation visuelle

La validation visuelle et fonctionnelle du Wiki Moodle CMID 55 est réussie :

- pages affichées correctement ;
- navigation inter-pages fonctionnelle ;
- image affichée ;
- fichiers accessibles ;
- liens externes fonctionnels.

## Idempotence

Un second dry-run retourne :

- Wiki 273 : `UPDATE` vers CMID 55 ;
- 0 page à créer ;
- 3 pages à mettre à jour ;
- 0 page bloquée ;
- mappings vers les mêmes pages Moodle.

Le second apply retourne :

- activité Wiki : `UPDATED` sur CMID 55 ;
- page1 : `UNCHANGED`, target 1, version 1 ;
- page2 : `UNCHANGED`, target 2, version 1 ;
- page3 : `UNCHANGED`, target 3, version 1 ;
- 3 pages au total ;
- 4 fichiers au total ;
- mappings toujours uniques.

Chaque page contient deux enregistrements dans `wiki_versions` : la version 0 vide créée nativement par Moodle lors de `wiki_create_page()`, puis la version 1 contenant le contenu migré. Le second apply identique ne crée aucune révision supplémentaire.

## Critère de sortie

Le critère de sortie de la Phase 6.5.3 est satisfait :

- pages courantes navigables dans Moodle ;
- liens internes conservés ;
- médias et pièces jointes conservés ;
- historique ILIAS non migré signalé explicitement ;
- CREATE validé ;
- UPDATE/idempotence validé ;
- validation visuelle réussie.

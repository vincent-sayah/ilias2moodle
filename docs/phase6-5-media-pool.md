# Phase 6.5.8 — Media Pool / galerie média ILIAS vers Moodle

## État

Phase validée sur le POC réel ILIAS 10.8 → Moodle 5.0.2.

Issue : #21.

Branche : `phase6-5-media-pool`.

## Objectif

Étudier puis migrer les médias utiles d’un Media Pool ILIAS ou d’une galerie média vers une représentation Moodle adaptée, sans recréer artificiellement les éléments purement techniques d’une bibliothèque d’auteur.

## Principes

La cible Moodle n’est pas figée avant l’analyse de l’export réel.

Les stratégies à comparer selon le POC sont notamment :

- ressources Moodle lorsque les médias sont des objets pédagogiques indépendants ;
- `mod_data` lorsqu’une collection/galerie structurée doit être conservée ;
- `mod_page` lorsqu’une restitution éditoriale unique est plus fidèle ;
- combinaison contrôlée lorsque le Media Pool sert de source à plusieurs objets visibles.

La migration doit conserver les médias réellement utiles aux apprenants, leur ordre, titres, descriptions et métadonnées pertinentes, sans reproduire une structure technique ILIAS sans équivalent fonctionnel Moodle.

## POC à produire

Le POC doit idéalement contenir :

- plusieurs images ;
- un fichier audio ;
- une vidéo ;
- des sous-dossiers ou regroupements si le Media Pool les autorise ;
- titres et descriptions distincts ;
- miniatures lorsque celles-ci sont générées par ILIAS ;
- au moins un média réellement utilisé depuis un autre objet de cours, si possible.

## Analyse attendue

Sur l’export natif ILIAS 10.8 :

1. confirmer le type ILIAS exact du Media Pool / de la galerie ;
2. identifier l’ExportSet correspondant ;
3. inventorier les composants XML et binaires ;
4. distinguer originaux, previews et miniatures ;
5. déterminer si les dossiers et l’ordre sont exportés ;
6. identifier les références vers les MediaObjects ;
7. qualifier ce qui est visible des apprenants et ce qui relève uniquement de la bibliothèque d’auteur ;
8. décider ensuite de la cible Moodle.

## Garde-fous

- aucun média nécessaire ne doit être perdu silencieusement ;
- les previews ne remplacent pas un original disponible ;
- les médias absents du ZIP doivent être signalés avant toute récupération complémentaire ;
- aucune structure technique ILIAS ne doit être recréée sans valeur fonctionnelle côté Moodle ;
- le dry-run devra rester strictement sans écriture ;
- l’apply final devra être idempotent.

## Critère de sortie

#21 sera validée lorsque la stratégie Moodle sera documentée à partir du POC réel, que les médias utiles seront présents et contrôlés dans le package neutre, que le dry-run et l’apply seront sûrs et que le second apply ne créera aucun doublon.


## Résultats du POC réel

Export complet analysé :

- `/vagrant/1789892867__0__crs_504.zip` ;
- taille observée : environ 840 Mo ;
- SHA-256 : `6a0aa2e93620b854555693db385d77b08f1b94381828c568f047297e86523527`.

Media Pool :

- type ILIAS confirmé : `mep` ;
- `ref_id=278` ;
- `obj_id=818` ;
- titre : `galerie de media` ;
- description : `test galerie pour migration moodle`.

Arbre `mep_tree` observé :

- child 1 : `dummy` racine technique ;
- child 2 : média `duo` → mob 819 ;
- child 3 : média `video5` → mob 820 ;
- child 4 : Page Editor `texte media` → COPage `mep:4` ;
- child 5 : dossier `dossier1` ;
- child 6 : média `femme_noire_robot.png` → mob 822, sous `dossier1`.

La COPage `mep:4` contient :

- le paragraphe `texte de contenu` ;
- le MediaObject `mob 821` → `trio.png`.

Les MediaObjects sont répartis sur plusieurs sets :

- `set_0` : mob 819, 820, 822 ;
- `set_1` : mob 821.

Les originaux observés sont :

- `du2.png` — image/png ;
- `vid5.mp4` — video/mp4 ;
- `trio.png` — image/png ;
- `femme_noire_robot.png` — image/png.

Les fichiers `mob_vpreview.png` sont des dérivés techniques et ne doivent pas être migrés comme contenu.

## Réutilisation depuis le Wiki

Le Wiki `ref_id=279`, `obj_id=823` réexporte directement les MediaObjects de la galerie qu'il utilise :

- mob 820 → `vid5.mp4` ;
- mob 822 → `femme_noire_robot.png`.

Les mêmes identifiants de MediaObjects sont donc présents dans le Media Pool et dans le Wiki. Le Wiki dispose également de ses propres binaires dans son ExportSet ; son import ne dépend pas d'un chemin physique vers l'ExportSet `mep`.

Cette duplication dans deux contextes d'export est considérée valide : Moodle pourra stocker les mêmes octets dans les zones de fichiers propres aux activités qui les consomment, sans créer de dépendance inter-composants fragile.

## Stratégie Moodle proposée après analyse réelle

La cible validée est `mod_data` :

- 1 Media Pool ILIAS → 1 activité Database ;
- 1 nœud de contenu `mob` ou `pg` → 1 record ;
- les nœuds `dummy` sont ignorés ;
- les dossiers sont conservés sous forme de `folder_path` sur les records descendants ;
- les médias sont rendus selon leur MIME (image/vidéo ; audio à valider sur un POC réel avant déclaration de support) ;
- les nœuds `pg` conservent leur contenu COPage ;
- les originaux sont stockés via Moodle Files API ;
- les previews ILIAS ne sont pas injectées dans Moodle.

Le POC actuel valide des images PNG et une vidéo MP4. Il ne contient aucun fichier audio : le support audio ne doit donc pas être déclaré validé à ce stade.

## Politique ZIP complet

La Phase 6.5.8 est testée sur le ZIP complet et non sur un fixture réduit.

Le pipeline doit :

1. parser l'ensemble du Container ;
2. préparer toutes les familles déjà supportées ;
3. préparer `mep` ;
4. conserver explicitement en `DEFER` les familles relevant d'une phase future, notamment `grp` jusqu'à la Phase 7 ;
5. ne jamais exiger de suppression d'ExportSets pour faire passer le package.


## Validation Moodle réelle

Dry-run réel sur le package complet `course-128-v10` :

- `MEDIA_POOL_READY` ;
- `ready=true` ;
- `blocked_media_pools=0` ;
- `record_count=4` ;
- `asset_count=4` ;
- `image_count=3` ;
- `video_count=1` ;
- `page_record_count=1` ;
- `folder_count=1` ;
- parent résolu vers la section Moodle `1`.

Premier apply :

- `CREATE` → `CREATED` ;
- `CMID=62` ;
- instance `4` ;
- section `1` ;
- 4 records créés : tree ids `2→5`, `3→6`, `4→7`, `6→8` ;
- 4 médias écrits ;
- 3 images ;
- 1 vidéo MP4.

Validation visuelle conforme :

- `duo` affiche `du2.png` et sa légende ;
- `video5` affiche un lecteur vidéo HTML5 sur `vid5.mp4` ;
- `texte media` affiche le texte et `trio.png` ;
- `femme_noire_robot.png` est visible avec `folder_path=dossier1` ;
- aucune preview ILIAS n’est affichée.

Second apply :

- `UPDATE` → `UPDATED` ;
- même `CMID=62` ;
- même instance `4` ;
- `records_created=0` ;
- `records_updated=4` ;
- mêmes records `5`, `6`, `7`, `8` ;
- exactement 4 fichiers finaux dans `mod_data/content`.

## Critère de sortie atteint

Les critères de sortie de #21 sont satisfaits :

- type `mep` confirmé ;
- parseur et package neutre fonctionnels sur le ZIP complet ;
- références croisées avec le Wiki 279 comprises et conservées ;
- dry-run sans écriture ;
- stratégie `mod_data` validée ;
- apply réel validé ;
- mapping persistant ;
- second apply idempotent ;
- aucune preview importée ;
- validation visuelle conforme.

La Phase 6.5 est ainsi terminée. La suite du projet bascule sur la Phase 7.

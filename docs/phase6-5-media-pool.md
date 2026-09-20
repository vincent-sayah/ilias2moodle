# Phase 6.5.8 — Media Pool / galerie média ILIAS vers Moodle

## État

Analyse du POC réel à démarrer.

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

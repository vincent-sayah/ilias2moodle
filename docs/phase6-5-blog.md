# Phase 6.5.7 — Blog ILIAS vers Moodle

## État

Phase validée sur le POC réel ILIAS 10.8 → Moodle 5.0.2.

Issue : #20.

PR : #32.

POC :

- cours ILIAS : `ref_id=128`, `obj_id=504` ;
- Blog : `ref_id=247`, `obj_id=732`, type `blog` ;
- titre : `blog` ;
- parent : `ref_id=246` ;
- cible Moodle : `mod_data`, `CMID=61`, instance `3`.

## Mapping retenu

Le Blog utilisateur natif Moodle n’est pas utilisé, car le Blog ILIAS est un objet pédagogique du repository/cours.

Le mapping validé est :

```text
1 Blog ILIAS
    ↓
1 activité Moodle mod_data

1 billet ILIAS
    ↓
1 record mod_data
```

Le POC contient deux billets :

| Posting ILIAS | Titre | Record Moodle |
|---:|---|---:|
| 12 | titre 1 | 3 |
| 13 | titre 2 | 4 |

## Export ILIAS

Le composant Blog exporte :

- l’entité `blog` pour les paramètres du Blog ;
- l’entité `blog_posting` pour les métadonnées de chaque billet.

Les billets conservent notamment :

- `Id` ;
- `BlogId` ;
- `Title` ;
- `Created` ;
- `Author` ;
- `Approved` ;
- `LastWithdrawn` ;
- les éventuels `KeywordN`.

Le contenu riche n’est pas stocké directement dans `blog_posting`. Chaque billet est associé à une COPage :

```text
blp:<posting_id>
```

Le parseur réutilise les mécanismes Page Editor déjà validés afin de reconstruire les paragraphes, médias, grilles et autres blocs supportés.

## POC réel

L’export natif du Blog contient :

- 2 billets ;
- 2 MediaObjects PNG locaux ;
- 0 fichier documentaire embarqué ;
- 0 composant Page Editor non supporté.

Médias :

- `mob 735` → `tous.png` — 2 207 605 octets ;
- `mob 736` → `trio.png` — 2 115 739 octets.

Le billet `13 / titre 2` contient :

- une image `tous.png` ;
- une Grid 3 colonnes ;
- du texte dans les colonnes gauche et droite ;
- `trio.png` dans la colonne centrale.

## Package neutre

Le package produit notamment :

```text
blogs/247/structure.json
blogs/247/media/735/tous.png
blogs/247/media/736/trio.png
```

Chaque fichier extrait reçoit une taille et un SHA-256. Le dry-run Moodle recalcule ces valeurs et bloque l’apply en cas d’écart.

## Champs Moodle

Le `mod_data` créé contient les champs :

- `source_posting_id` ;
- `position` ;
- `title` ;
- `created` ;
- `source_author` ;
- `keywords` ;
- `content`.

Le champ `content` contient le HTML déterministe reconstruit depuis le COPage. Les images sont stockées via Moodle Files API dans la zone `mod_data/content`.

## Auteurs

Le POC exporte l’auteur :

```text
il_0_usr_6
```

Cet identifiant est conservé dans `source_author`.

Aucun compte Moodle n’est attribué artificiellement au billet en Phase 6.5.7. Les records sont créés par le compte technique de migration et le rapprochement de l’auteur réel reste reporté à la Phase 7.

## Mots-clés

L’option Keywords est active dans le Blog source, mais le POC réel ne contient aucun élément `KeywordN` dans les billets.

Le parsing `Keyword0`, `Keyword1`, etc. est implémenté et testé sur fixture synthétique. La restitution de mots-clés réels reste à confirmer lorsqu’un export POC contenant effectivement des `KeywordN` sera disponible.

## Dry-run

Le dry-run réel a validé :

- `BLOG_READY` ;
- `blocked_blogs=0` ;
- `ready=true` ;
- `posting_count=2` ;
- `asset_count=2` ;
- parent `ref_id=246` résolu vers la sous-section Moodle 6 ;
- aucune écriture en mode dry-run.

Les Blog référencés par un Container mais absents d’un package incrémental ciblé sont différés explicitement au lieu de bloquer l’objet sélectionné.

## Apply et idempotence

Premier apply :

- action demandée : `CREATE` ;
- action réalisée : `CREATED` ;
- `CMID=61` ;
- instance `3` ;
- 2 records créés ;
- 2 images écrites via Files API.

Second apply :

- action demandée : `UPDATE` ;
- action réalisée : `UPDATED` ;
- même `CMID=61` ;
- même instance `3` ;
- `records_created=0` ;
- `records_updated=2` ;
- mêmes records `3` et `4` ;
- exactement 2 fichiers média finaux, sans doublon.

## Validation visuelle

La validation visuelle est conforme dans Moodle :

- `titre 1` affiche ses deux paragraphes ;
- `titre 2` affiche `tous.png`, puis la Grid 3 colonnes ;
- `trio.png` est rendu dans la colonne centrale ;
- les textes des colonnes sont conservés.

## Garde-fous

Le périmètre validé de Phase 6.5.7 couvre les médias image locaux observés dans le POC.

Les cas suivants restent bloqués tant qu’ils ne sont pas validés sur un POC dédié :

- liens internes ILIAS dans un billet ;
- fichiers documentaires embarqués dans le contenu du Blog ;
- types de média autres que ceux explicitement validés.

Aucune perte silencieuse n’est autorisée.

## Critère de sortie

Les critères de sortie de #20 sont satisfaits :

- parseur Blog fonctionnel ;
- package neutre complet ;
- dry-run sans écriture ;
- cible `mod_data` validée ;
- apply réel validé ;
- mapping persistant ;
- second apply idempotent ;
- aucun doublon ;
- auteur source conservé sans attribution Moodle inventée ;
- validation visuelle conforme.

La prochaine famille d’objets de la Phase 6.5 est #21 — Media Pool / galerie média.

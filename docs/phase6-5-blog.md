# Phase 6.5.7 — Blog ILIAS vers Moodle

## État

Analyse du POC réel en cours.

Issue : #20.

Branche : `phase6-5-blog`.

POC déjà référencé dans le cours ILIAS de référence :

- cours : `ref_id=128`, `obj_id=504` ;
- Blog : `ref_id=247`, `obj_id=732` ;
- titre : `blog` ;
- parent : `ref_id=246`.

## Principe

La cible Moodle `mod_data` est privilégiée, mais elle ne sera figée qu'après analyse de l'export réel.

La migration ne doit pas utiliser le blog utilisateur natif de Moodle comme équivalent automatique, car le Blog ILIAS est un objet pédagogique de dépôt et de publication dans le repository/cours.

## Structure d'export ILIAS observée dans le code ILIAS

Le composant Blog exporte deux entités de dataset :

- `blog` : paramètres du Blog ;
- `blog_posting` : métadonnées des billets.

Le schéma Blog courant est `8.0`.

Les paramètres Blog exportés comprennent notamment :

- Id ;
- Title ;
- Description ;
- Notes ;
- BgColor / FontColor ;
- image et options de présentation ;
- RSS ;
- Approval ;
- options d'aperçu ;
- navigation ;
- Keywords ;
- Authors ;
- Style ;
- ReadingTime.

Les billets exportent notamment :

- Id ;
- BlogId ;
- Title ;
- Created ;
- Author ;
- Approved ;
- LastWithdrawn ;
- mots-clés dynamiques `Keyword0...`.

## Contenu des billets

Le contenu riche d'un billet n'est pas stocké dans `blog_posting`.

Pour chaque billet, l'exporteur Blog ajoute une dépendance COPage avec un identifiant :

```text
blp:<posting_id>
```

La migration doit donc reconstruire le contenu de chaque billet à partir des exports `components/ILIAS/COPage`, puis réconcilier les médias et fichiers référencés selon les mécanismes déjà utilisés par les autres objets Page Editor.

## Auteurs

L'auteur est exporté sous forme d'identifiant utilisateur d'export ILIAS.

En Phase 6.5.7, cet identifiant source peut être conservé comme métadonnée, mais aucun compte Moodle ne doit être attribué artificiellement. Le rapprochement utilisateur reste du ressort de la Phase 7.

## Cible Moodle envisagée

Si le POC réel confirme cette structure :

- 1 Blog ILIAS → 1 `mod_data` ;
- 1 billet → 1 record ;
- champs pressentis : identifiant source, titre, date, contenu, mots-clés, auteur source ;
- médias/fichiers intégrés via Moodle Files API ;
- auteur Moodle différé en Phase 7 ;
- mapping persistant Blog et billets ;
- CREATE puis UPDATE idempotents.

Cette structure reste provisoire jusqu'à analyse du ZIP réel du Blog `ref_id=247`.

## Prochaine validation

Analyser dans l'export natif ILIAS 10.8 :

1. le manifeste Container pour `ref_id=247` / `obj_id=732` ;
2. l'export `components/ILIAS/Blog` ;
3. les billets `blog_posting` ;
4. les pages COPage `blp:<posting_id>` ;
5. les médias/fichiers dépendants ;
6. les mots-clés et les identifiants auteurs réellement présents ;
7. l'ordre et les dates des billets.

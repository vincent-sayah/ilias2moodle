# Phase 6.5 — Extension des objets pédagogiques ILIAS

## Objectif

La Phase 6.5 étend ILIAS2Moodle avant la Phase 7 afin de traiter des objets pédagogiques supplémentaires d’ILIAS 10 qui ne faisaient pas partie du périmètre initial des Phases 1 à 6.

Issue maître : #13.

Cette phase conserve les principes déjà appliqués au projet :

- export natif ILIAS comme source ;
- aucun accès direct à la base ILIAS pour reconstruire les contenus ;
- modèle neutre `migration.json` ;
- dry-run sans écriture ;
- apply via les API Moodle ;
- mapping persistant ILIAS ↔ Moodle ;
- idempotence ;
- blocage ou fallback explicite lorsque la conversion n’est pas sûre ;
- validation réelle sur le POC avant fusion des PR fonctionnelles.

## Périmètre retenu

| Ordre | Objet ILIAS | Cible Moodle envisagée | Issue | Mode visé |
|---:|---|---|---:|---|
| 1 | Content Page | `mod_page` | #14 | automatique |
| 2 | Glossaire | `mod_glossary` | #15 | automatique |
| 3 | Wiki | `mod_wiki` | #16 | automatique / semi-automatique |
| 4 | Exercice | `mod_assign` | #17 | semi-automatique |
| 5 | Forum | `mod_forum` | #18 | semi-automatique |
| 6 | Mediacast | `mod_data` | #19 | transformation contrôlée |
| 7 | Blog | `mod_data` | #20 | transformation contrôlée |
| 8 | Media Pool / galerie média | ressources / `mod_data` / `mod_page` | #21 | transformation contrôlée |

## Dépendance avec la Phase 7

L’objet ILIAS Groupe est maintenu dans la Phase 7 (#7), car il dépend du rapprochement des utilisateurs, des inscriptions, des membres et des groupements.

De même, certaines données des objets de la Phase 6.5 peuvent être reportées à la Phase 7 lorsqu’elles nécessitent une identité utilisateur fiable :

- auteurs de messages de forum ;
- auteurs de billets de blog ;
- auteurs ou historique de wiki ;
- remises d’exercices ;
- notes et feedbacks ;
- membres de groupes.

La Phase 6.5 peut créer la structure pédagogique sans inventer ces rattachements.

## Méthode commune pour chaque objet

1. Ajouter un objet POC réel dans le cours ILIAS 10.8 de référence.
2. Produire un nouvel export natif complet.
3. Identifier le type ILIAS exact et les composants XML/fichiers utiles.
4. Vérifier que les données nécessaires sont bien présentes dans l’export.
5. Étendre le parseur Python et le package builder.
6. Définir la représentation neutre dans `migration.json`.
7. Ajouter un plan/dry-run Moodle avec validations explicites.
8. Implémenter l’apply avec les API Moodle.
9. Enregistrer le mapping persistant.
10. Rejouer l’import pour vérifier l’idempotence.
11. Contrôler visuellement et fonctionnellement dans Moodle.
12. Fusionner la PR uniquement après validation réelle du POC.

## Stratégies par objet

### Content Page

Cible : `mod_page`.

À conserver en priorité :

- titre et description ;
- contenu Page Editor ;
- images et médias ;
- fichiers intégrés ;
- liens internes réécrits quand une cible Moodle existe.

État : validé et fusionné via #14 / PR #23.

### Glossaire

Cible : `mod_glossary`.

À étudier dans l’export :

- concepts ;
- définitions ;
- alias ;
- catégories ;
- médias et pièces jointes ;
- paramètres utiles du glossaire.

État : validé sur le POC réel via #15. Les termes, définitions riches et médias sont migrés vers `mod_glossary` avec mappings persistants et idempotence.

### Wiki

Cible : `mod_wiki`.

État : validé sur le POC réel via #16. Les pages courantes, liens internes, médias et fichiers sont migrés vers `mod_wiki`. L’historique des révisions et les auteurs restent explicitement hors périmètre lorsque leur rattachement n’est pas fiable.

### Exercice

Cible : `mod_assign`.

État : validé sur le POC réel ILIAS 10.8 / Moodle 5.0.2.

La stratégie retenue crée un `mod_assign` par unité ILIAS dans la section Moodle correspondant au parent de l’Exercise. Les types validés sont le dépôt de fichier individuel, le texte en ligne et le dépôt de fichier en équipe. Les fichiers d’instruction absents de l’export natif ILIAS 10.8 peuvent être récupérés en lecture seule via IRSS, puis contrôlés par taille et SHA-256 avant intégration au package.

Les remises, notes, feedbacks utilisateurs et appartenances aux équipes restent reportés à la Phase 7. Le second apply met à jour les mêmes activités sans créer de doublons.

### Forum

Cible : `mod_forum`.

État : validé sur le POC réel ILIAS 10.8 / Moodle 5.0.2.

POC validé :

- ILIAS Forum `ref_id=275`, `obj_id=807` ;
- 2 discussions ;
- 7 messages ;
- 3 pièces jointes ;
- auteurs source observés : `6`, `401`, `402` ;
- Moodle : `CMID=59`, instance `6`, type `general`.

La Phase 6.5.5 crée et met à jour uniquement le conteneur `mod_forum`. Les discussions, messages, hiérarchie `ParentId/Depth`, dates, auteurs source, pièces jointes et médias sont conservés dans `forums/<ref_id>/structure.json` et dans le package de migration, mais leur injection dans Moodle est reportée à la Phase 7 tant que les auteurs ne sont pas rapprochés de façon fiable.

Le dry-run applique une politique incrémentale : un autre Forum référencé par le Container mais absent du package ciblé est `DEFER / SKIPPED_INCREMENTAL` et ne bloque pas le Forum sélectionné.

L’apply réel a validé :

- `CREATE` puis `UPDATE` sur le même CMID 59 ;
- un mapping unique `275 -> forum -> 59` en statut `READY` ;
- aucun doublon ;
- aucune discussion ni aucun post créé artificiellement ;
- conservation du type Moodle `general` après UPDATE.

### Mediacast

Cible : `mod_data`.

État : validé sur le POC réel ILIAS 10.8 / Moodle 5.0.2.

POC validé :

- ILIAS Mediacast `ref_id=276`, `obj_id=809`, type `mcst` ;
- 2 entrées : 1 MP4 local et 1 URL externe YouTube ;
- récupération en lecture seule du MP4 via MediaObjects/IRSS lorsque le ZIP natif ne contient que la preview ;
- contrôle d’intégrité du MP4 par taille et SHA-256 ;
- 1 `mod_data` Moodle par Mediacast ;
- 1 record Moodle par entrée source ;
- Moodle `CMID=60`, instance `2` ;
- records `1` et `2` ;
- MP4 stocké via Moodle Files API et affiché avec un lecteur HTML5 intégré ;
- URL externe conservée dans un champ URL ;
- previews conservées dans le package neutre mais non importées dans Moodle pour ce POC.

Le dry-run ciblé peut rencontrer d’autres Mediacasts référencés par le Container mais absents du fixture ; ils sont alors `DEFER / SKIPPED_INCREMENTAL` sans bloquer l’objet sélectionné.

Le premier apply a créé le `mod_data` et ses 2 records. Le second apply a mis à jour le même CMID 60 et les mêmes 2 records, sans doublon. Le lecteur MP4 et l’URL externe ont été validés visuellement dans Moodle.

Le périmètre de cette validation est volontairement limité aux MP4 locaux et aux URL externes ; WebM, images et audio ne sont pas déclarés supportés par ce POC.

### Blog

Cible : `mod_data`.

État : validé sur le POC réel ILIAS 10.8 / Moodle 5.0.2.

POC validé :

- ILIAS Blog `ref_id=247`, `obj_id=732`, type `blog` ;
- 2 billets : `12 / titre 1` et `13 / titre 2` ;
- contenu riche des billets reconstruit depuis les COPage `blp:<posting_id>` ;
- 2 MediaObjects PNG locaux : `tous.png` et `trio.png` ;
- conservation des paragraphes et d’une Grid 3 colonnes ;
- 1 `mod_data` Moodle par Blog ;
- 1 record Moodle par billet ;
- Moodle `CMID=61`, instance `3` ;
- records `3` et `4` ;
- champs : `source_posting_id`, `position`, `title`, `created`, `source_author`, `keywords`, `content` ;
- images stockées via Moodle Files API dans `mod_data/content` ;
- identifiant auteur ILIAS `il_0_usr_6` conservé dans `source_author` ;
- propriétaire technique Moodle des records conservé jusqu’au rapprochement utilisateurs de la Phase 7.

Le dry-run réel a validé `BLOG_READY`, `blocked_blogs=0` et `ready=true`. Le premier apply a créé le `mod_data` et les 2 records ; le second apply a mis à jour le même CMID 61 et les mêmes records 3 et 4, sans doublon. Les deux images finales sont présentes une seule fois dans Moodle et l’affichage du billet `titre 2`, comprenant l’image initiale et la Grid 3 colonnes avec `trio.png` au centre, a été validé visuellement.

Le POC réel ne contient aucun `KeywordN` malgré l’option Keywords activée : le parsing est implémenté, mais les mots-clés restent à confirmer sur un export réel qui en contient. Les liens internes ILIAS et les fichiers embarqués hors médias image restent bloqués tant qu’ils n’ont pas été validés par un POC dédié.

### Media Pool / galerie média

Le Media Pool peut être une bibliothèque d’auteur plus qu’une activité destinée aux apprenants. La migration doit donc privilégier les médias réellement utiles plutôt que recréer artificiellement toute la structure technique ILIAS.

## Critères généraux de sortie

Une famille d’objets est considérée validée lorsque :

- le parser reconnaît l’objet et ses données utiles ;
- le package ne perd aucun fichier nécessaire ;
- le dry-run est complet et sûr ;
- l’apply crée ou met à jour la bonne cible Moodle ;
- le mapping est persistant ;
- un second import reste idempotent ;
- le contrôle visuel/fonctionnel est conforme ;
- les données volontairement non migrées sont documentées.

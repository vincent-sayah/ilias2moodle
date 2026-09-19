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
| 6 | Mediacast | `mod_data` ou ressources | #19 | transformation contrôlée |
| 7 | Blog | `mod_data` privilégié | #20 | transformation contrôlée |
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

État : analyse réelle en cours sur #15. Le premier POC exporté confirme `glo` + définitions `COPage` + `MediaObjects`; la taxonomie et les pièces jointes ne sont pas encore validées dans l’export réel observé.

### Wiki

Cible : `mod_wiki`.

Première cible fonctionnelle : pages actuelles, navigation, liens et médias. L’historique des révisions et les auteurs ne seront migrés que si cela peut être fait de façon fiable.

### Exercice

Cible : `mod_assign`.

État : validé sur le POC réel ILIAS 10.8 / Moodle 5.0.2.

La stratégie retenue crée un `mod_assign` par unité ILIAS dans la section Moodle correspondant au parent de l’Exercise. Les types validés sont le dépôt de fichier individuel, le texte en ligne et le dépôt de fichier en équipe. Les fichiers d’instruction absents de l’export natif ILIAS 10.8 peuvent être récupérés en lecture seule via IRSS, puis contrôlés par taille et SHA-256 avant intégration au package.

Les remises, notes, feedbacks utilisateurs et appartenances aux équipes restent reportés à la Phase 7. Le second apply met à jour les mêmes activités sans créer de doublons.

### Forum

Cible : `mod_forum`.

La structure du forum peut être migrée en Phase 6.5. Les discussions et messages seront migrés avec leurs auteurs uniquement si les identités sont disponibles de manière sûre ; sinon la politique sera explicitement reportée à la Phase 7.

### Mediacast

Pas d’équivalence stricte retenue avant POC.

Deux stratégies seront comparées :

- `mod_data` pour conserver une collection/galerie ;
- plusieurs `mod_resource` lorsque la fidélité média est prioritaire.

### Blog

Le blog Moodle natif n’est pas retenu par défaut car il est centré sur l’utilisateur plutôt que sur une activité de cours. `mod_data` est la cible privilégiée pour représenter une collection de billets.

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

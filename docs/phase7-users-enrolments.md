# Phase 7.1 — Rapprochement utilisateurs, inscriptions et rôles

## État

Analyse du POC réel à démarrer.

Parent : #7.

Sous-ticket : #34.

Branche : `phase7-users-enrolments`.

## Objectif

Construire une couche de résolution d'identité fiable entre ILIAS et Moodle avant toute migration dépendante des utilisateurs.

Le périmètre comprend :

- utilisateurs référencés par le cours ;
- inscriptions au cours ;
- rôles ;
- identifiants auteurs conservés par les objets des phases précédentes ;
- préparation du rattachement des membres de groupes (#22).

## Politique de sécurité

Aucune correspondance ne sera créée à partir d'un simple nom affiché.

Les clés candidates devront être évaluées dans cet ordre seulement après analyse du POC réel :

1. identifiant externe stable explicitement partagé entre ILIAS et Moodle ;
2. login strictement égal et unique ;
3. email normalisé strictement égal et unique ;
4. autre identifiant institutionnel stable si réellement exporté et présent côté Moodle.

Le nom/prénom seuls ne peuvent pas constituer une correspondance fiable.

Toute résolution devra être classée dans un état explicite :

- `MATCHED` ;
- `UNRESOLVED` ;
- `AMBIGUOUS` ;
- `NOT_IN_TARGET` ;
- éventuellement `CREATE_CANDIDATE`, sans création automatique tant que cette politique n'est pas validée.

## Étape 1 — analyse du ZIP complet

L'analyse doit déterminer :

- quels composants ILIAS exportent des utilisateurs ou références utilisateurs ;
- où sont stockés les membres du cours ;
- où sont stockés les rôles ;
- quels identifiants sont réellement disponibles ;
- si les données sont suffisantes pour rapprocher des comptes Moodle existants ;
- si les objets Forum, Blog, Wiki, Exercice et Groupe utilisent les mêmes identifiants.

Aucune écriture Moodle ne doit être effectuée pendant cette étape.

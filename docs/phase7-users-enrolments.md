# Phase 7.1 — Utilisateurs, inscriptions et rôles

## État

Phase 7.1 validée sur le POC réel.

Cette phase établit le rapprochement fiable des identités ILIAS vers Moodle avant les traitements dépendant d'un utilisateur : inscriptions, rôles, contributions Forum, auteurs Blog, auteurs Wiki et données de progression.

L'objet ILIAS Groupe complet n'est pas inclus dans ce périmètre. Sa migration reste différée.

## Périmètre de validation

Source :

- ILIAS 10.8 ;
- client `ilias10` ;
- cours `obj_id=504 / ref_id=128` ;
- titre `cours test migration`.

Cible finale de validation :

- Moodle 5.0.2 ;
- cours `id=2` ;
- shortname `ILIAS-128` ;
- six participants inscrits.

## Source des identités

Le ZIP natif ILIAS reste la source principale des contenus pédagogiques, mais il ne contient pas suffisamment d'informations pour reconstruire de manière fiable les participants et leurs rôles.

La Phase 7 utilise donc une extraction complémentaire en lecture seule réalisée via les services applicatifs ILIAS.

Cette extraction fournit notamment :

- `source_user_id` ;
- login ;
- email ;
- prénom et nom ;
- rôle dans le cours ;
- références nécessaires aux contributions historiques.

Aucune donnée ILIAS n'est modifiée pendant cette extraction.

## Politique de rapprochement

Aucune correspondance n'est réalisée à partir du seul nom affiché.

Une identité n'est considérée comme résolue que lorsqu'une correspondance suffisamment fiable et non ambiguë est disponible.

Les mappings utilisateurs sont enregistrés dans `local_iliasmigration_map` avec :

```text
sourcelms      = ILIAS
sourceinstance = ilias10
sourcecourse   = GLOBAL
sourceref      = <usr_id ILIAS>
targettype     = user
status         = READY
```

Les traitements des phases suivantes réutilisent ces mappings persistants plutôt que d'effectuer un nouveau rapprochement par login, email ou nom.

## Administrateur global ILIAS

L'identité `ILIAS usr_id=6 / root` est rapprochée explicitement avec `Moodle user id=2 / admin`.

Ce mapping n'est accepté que si la cible Moodle est effectivement administrateur du site.

```text
ILIAS GLOBAL 6 -> Moodle user 2/admin
```

Le compte ILIAS `root` n'est pas inscrit automatiquement au cours Moodle lorsqu'il n'est pas membre du cours source.

## Participants du cours

L'extraction ILIAS finale confirme six participants pour le cours `504 / 128`.

| ILIAS usr_id | Login | Moodle user_id | Rôle ILIAS | Rôle Moodle |
|---:|---|---:|---|---|
| 401 | `stagiaire.1` | 6 | member | student |
| 402 | `stagiaire.2` | 7 | member | student |
| 410 | `stagiaire.10` | 8 | member | student |
| 412 | `stagiaire.12` | 3 | admin | editingteacher |
| 413 | `stagiaire.13` | 4 | admin | editingteacher |
| 415 | `stagiaire.15` | 5 | tutor | teacher |

Les six utilisateurs partagent l'adresse source `vince.syh@free.fr`. La configuration de validation utilise `allowaccountssameemail=1` afin de conserver fidèlement cette caractéristique du jeu de données ILIAS.

## Mappings persistants validés

```text
ILIAS GLOBAL 6   -> Moodle user 2
ILIAS GLOBAL 401 -> Moodle user 6
ILIAS GLOBAL 402 -> Moodle user 7
ILIAS GLOBAL 410 -> Moodle user 8
ILIAS GLOBAL 412 -> Moodle user 3
ILIAS GLOBAL 413 -> Moodle user 4
ILIAS GLOBAL 415 -> Moodle user 5
```

Tous ces mappings sont au statut `READY`.

Les six participants sont également associés à leur inscription au cours via les mappings de membership Phase 7.1.

## Credentials

Les comptes créés utilisent l'authentification Moodle `manual`.

La migration ne conserve ni ne transporte de mot de passe ILIAS :

- un mot de passe initial aléatoire est généré ;
- le mot de passe n'est jamais écrit dans les rapports ;
- la remise ou réinitialisation des accès relève de l'administration Moodle selon la politique locale.

## Idempotence

Le second passage Phase 7.1 réutilise les six comptes existants et ne crée aucun utilisateur supplémentaire.

Les rôles restent :

```text
401 -> student
402 -> student
410 -> student
412 -> editingteacher
413 -> editingteacher
415 -> teacher
```

La Phase 7.1 est donc considérée idempotente sur le POC.

## Dépendances des phases suivantes

Les mappings utilisateurs validés sont réutilisés par :

```text
Phase 7.3 -> Learning Progress / résultats
Phase 7.4 -> auteurs et contributions Forum
Phase 7.5 -> auteurs Blog
Phase 7.6 -> auteurs et métadonnées Wiki
```

Aucun rapprochement indépendant par email ou nom ne doit être réalisé dans ces traitements.

## Objet ILIAS Groupe

L'objet ILIAS `obj_id=743 / ref_id=254 / type=grp` est un objet de dépôt indépendant et ne doit pas être assimilé à un simple Moodle Group.

Une tentative initiale de représentation sous forme de Moodle Group a uniquement servi à explorer les memberships. Elle n'est plus considérée comme une migration fonctionnellement correcte et l'artefact Moodle correspondant a été supprimé.

La migration de l'objet Groupe complet reste donc :

```text
DEFERRED
```

Une future prise en charge devra définir un mapping tenant compte du conteneur, des contenus enfants, des membres et des éventuelles restrictions d'accès.

La résolution des identités nécessaires à cette future migration est néanmoins disponible grâce à la Phase 7.1.

## Validation finale

```text
source course        = ILIAS obj_id 504 / ref_id 128
target course        = Moodle id 2 / ILIAS-128

source participants  = 6
mapped users         = 6
target users present = 6
target enrolled      = 6
identity issues      = 0

global admin mapping = ILIAS 6 -> Moodle 2/admin
```

La Phase 7.1 est clôturée pour le périmètre du POC.

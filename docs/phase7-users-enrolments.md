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


## Résultat du POC ZIP complet

Analyse du ZIP natif `1789892867__0__crs_504.zip` :

- aucun composant utilisateur/profil/membership n'est exporté avec le cours ;
- aucune liste d'inscrits du cours n'est présente ;
- aucune liste de membres du Groupe 743 n'est présente ;
- une seule référence au format `il_0_usr_<id>` est présente : `il_0_usr_6` ;
- cette identité est utilisée comme auteur de Blog, créateur de versions de fichiers et propriétaire du Groupe 743 ;
- les identités historiques observées dans d'autres objets ne disposent pas nécessairement d'un profil exporté dans le ZIP.

Conclusion : le ZIP natif reste la source de contenu, mais il est insuffisant pour la résolution des identités et inscriptions.

## Source complémentaire Phase 7

La Phase 7 autorise une extraction complémentaire **lecture seule au niveau applicatif ILIAS** pour les données d'identité et de membership absentes du ZIP.

Cette extraction doit :

- utiliser les classes/services ILIAS, pas des requêtes SQL directes du projet ;
- cibler explicitement le cours POC et les groupes concernés ;
- exporter uniquement les champs nécessaires au rapprochement ;
- produire un JSON auditable ;
- ne modifier aucune donnée ILIAS.

Champs candidats :

- `source_user_id` ;
- `login` ;
- `email` ;
- `firstname` ;
- `lastname` ;
- `matriculation` si disponible ;
- `external_account` si disponible ;
- statut actif ;
- rôle dans le cours ;
- appartenance/role dans les groupes.

## État Moodle POC

Cours cible `id=5 / ILIAS-128` :

- 0 utilisateur inscrit ;
- 3 comptes globaux non supprimés ;
- aucun `idnumber` renseigné sur ces comptes.

Aucun rapprochement automatique ne sera réalisé tant que les attributs ILIAS correspondants n'ont pas été récupérés.


## Dry-run Moodle réel

Le dry-run Phase 7.1 sur le cours Moodle `5 / ILIAS-128` est validé :

- `PHASE7_DRY_RUN_RC=0` ;
- `writes_performed=false` ;
- `ready_for_apply=false` ;
- `apply_implemented=false` ;
- `MATCHED=0` ;
- `AMBIGUOUS=3` ;
- `NOT_IN_TARGET=1` ;
- `ENROL=0` ;
- `UPDATE=0` ;
- `DEFER=3`.

Résolution réelle :

- ILIAS 6 / `root` / `ilias@yourserver.com` → `NOT_IN_TARGET` ;
- ILIAS 401 / `stagiaire.1` / `vince.syh@free.fr` → `AMBIGUOUS` ;
- ILIAS 402 / `stagiaire.2` / `vince.syh@free.fr` → `AMBIGUOUS` ;
- ILIAS 410 / `stagiaire.10` / `vince.syh@free.fr` → `AMBIGUOUS`.

L'ambiguïté est correcte : l'email source est partagé par trois comptes ILIAS et correspond au compte Moodle `admin` (id 2). Aucun rapprochement par email n'est donc autorisé.

La prochaine étape consiste à évaluer une création contrôlée de comptes Moodle dédiés en conservant les logins ILIAS uniques, puis à inscrire uniquement les comptes créés/résolus au rôle Moodle `student`. L'identité ILIAS 6 ne doit pas être créée ni inscrite automatiquement car elle n'est pas membre du cours.


## Validation finale Phase 7.1

La Phase 7.1 a été validée sur le POC réel le 20 septembre 2026.

### Configuration cible

- Moodle course `5 / ILIAS-128` ;
- authentification manuelle activée ;
- inscription manuelle activée, instance `id=10` ;
- rôle Moodle `student id=5` ;
- `allowaccountssameemail=1`, afin de conserver fidèlement les emails ILIAS partagés.

### Mapping administrateur global

Le compte ILIAS `usr_id=6 / root` est l'administrateur global ILIAS. Il est rapproché explicitement du compte administrateur global Moodle :

```text
ILIAS usr 6 / root
        ↓ explicit_override
Moodle user 2 / admin
        ↓
site administrator
```

Le mapping est accepté uniquement si la cible Moodle est réellement administrateur de site.

### Comptes de cours créés

Les membres réels du cours ILIAS `obj_id=504` sont :

| ILIAS | Login | Moodle | Rôle cours |
|---|---|---:|---|
| 401 | `stagiaire.1` | 5 | student |
| 402 | `stagiaire.2` | 6 | student |
| 410 | `stagiaire.10` | 7 | student |

Les trois comptes conservent l'adresse `vince.syh@free.fr`, autorisée par la configuration fonctionnelle Moodle.

Politique de credentials :

- auth Moodle `manual` ;
- mot de passe initial aléatoire généré côté Moodle ;
- mot de passe jamais écrit dans les rapports de migration ;
- changement forcé au premier accès ;
- état de remise des accès : `ADMIN_RESET_REQUIRED`.

### Mappings persistants validés

```text
ILIAS GLOBAL 6   -> Moodle user 2
ILIAS GLOBAL 401 -> Moodle user 5
ILIAS GLOBAL 402 -> Moodle user 6
ILIAS GLOBAL 410 -> Moodle user 7

ILIAS course 504 user:401 -> Moodle enrolment 8
ILIAS course 504 user:402 -> Moodle enrolment 9
ILIAS course 504 user:410 -> Moodle enrolment 10
```

Les sept mappings sont enregistrés avec le statut `READY`.

### Idempotence

Premier apply :

```text
created_user_count        = 3
reused_user_count         = 1
enrolled_membership_count = 3
updated_membership_count  = 0
```

Second apply :

```text
created_user_count        = 0
reused_user_count         = 4
enrolled_membership_count = 0
updated_membership_count  = 3
```

Le second passage ne crée donc aucun compte ni aucune inscription supplémentaire.

### Suite de Phase 7

La dépendance d'identité de l'issue #22 Groupe est désormais levée : les membres peuvent être reliés à Moodle lorsqu'ils existent dans les données ILIAS. Le Groupe POC `obj_id=743` ne possède actuellement aucun membre, mais son propriétaire `usr_id=6` est résolu vers l'administrateur global Moodle.

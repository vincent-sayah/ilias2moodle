# Changelog

## 0.22.0-beta9.2 — 2026-10-11

- Retour à un workflow opérateur **manuel et contrôlé** : export réalisé par l'utilisateur dans ILIAS, upload dans Moodle, préparation/analyse, récupération manuelle uniquement si nécessaire, upload du bundle, puis migration d'un package `READY`.
- Gestion depuis la console Moodle des exports ILIAS : upload, sélection et suppression.
- Gestion depuis la console Moodle des packages préparés : statut, sélection et suppression contrôlée.
- Suppression du workflow par défaut des queues recovery/identité et de la tâche Moodle de re-préparation automatique ; les outils historiques restent disponibles mais dormants.
- Sortie des extensions Phase 7.1/7.4 du pipeline opérateur standard beta9.
- Correction du recovery des médias : copie du contenu sans conservation des xattrs/SELinux, couvrant Wiki, MediaCast, Forum, Blog et Media Pool (#85).
- Correction Phase 6 des Quiz liés à une Question Pool : réutilisation du `mapping_ref` persistant exact de la banque au lieu de reconstruire la clé depuis `external_id` (#86).
- Correction Phase 3 des liens internes ILIAS vers des ressources créées plus tard dans la même phase : seconde passe de réconciliation et mise à jour de `mod_url.externalurl` (#87).
- Qualification end-to-end du run opérateur Moodle **#17** : état `COMPLETED`, toutes les familles présentes en `SUCCESS`, Item Groups `SKIPPED` car absent du package.
- Validation fonctionnelle finale du cours migré, y compris le lien interne `lien` vers le module HTML Moodle `chiimie`.
- Build Moodle : `2026101105`.

## 0.22.0-beta9.1 — 2026-10-11

- Correction des identités de mapping pour les questions de Quiz réutilisées depuis une Question Pool liée.
- Build Moodle : `2026101104`.

## 0.22.0-beta9 — 2026-10-11

- Simplification de la console opérateur vers le workflow manuel retenu.
- Désactivation des automatismes beta5→beta8 dans le workflow par défaut.
- Upload/suppression des exports et packages depuis l'interface.
- Commandes de récupération ILIAS affichées à l'opérateur lorsqu'un package contient des dépendances manquantes.
- Build Moodle : `2026101103`.

## 0.20.0-rc2 — 2026-09-26

- Correction bloquante de l'installation neuve Moodle 5.0.2 MySQL/MariaDB détectée pendant la validation sur VM vierge (#50).
- Réduction de `sourceinstance` de 191 à 128 caractères ; la clé unique `source_target_unique` passe de 371 à 308 caractères et respecte la limite XMLDB Moodle de 333 caractères.
- Ajout d'un chemin d'upgrade sûr pour les installations existantes, avec refus explicite si une valeur `sourceinstance` dépasse 128 caractères.
- Ajout d'un test CI de régression sur la longueur des clés/index XMLDB.
- RC2 : `0.20.0-rc2`, build Moodle `2026092605`.

## 0.20.0-rc1 — 2026-09-26

- Validation des phases 1 à 7 sur le POC ILIAS 10 -> Moodle.
- Phase 7.1 : utilisateurs, inscriptions, rôles et mappings persistants.
- Phase 7.3 : progression/résultats du POC inventoriés et classifiés.
- Phase 7.4 : Forum, auteurs, 2 discussions, 7 posts et 3 pièces jointes avec apply idempotent.
- Phase 7.5 : auteurs Blog validés sans réattribution nécessaire.
- Phase 7.6 : auteurs et dates des pages Wiki courantes réconciliés sans création de faux historique.
- Ajout de garde-fous sur les mappings d'identité, notifications Forum et historique Wiki.
- Objet ILIAS Groupe complet (#22) et POC avancé de progression (#41) explicitement différés.
- Validation finale end-to-end du POC (#48) : plugin synchronisé, upgrade Moodle validé, audit global cohérent et dry-runs finaux sans écriture.
- RC1 : `0.20.0-rc1`, build Moodle `2026092604`.

Toutes les évolutions importantes d’ILIAS2Moodle seront documentées dans ce fichier.

## [Unreleased]

### Added

- Initialisation du dépôt.
- Documentation de l’architecture et des 7 phases.
- CLI `ilias2moodle analyse`.
- Modèle intermédiaire `migration.json`.
- Client ILIAS de démonstration.
- Génération de rapports JSON et HTML.
- Tests unitaires initiaux.
- Pipeline CI GitHub Actions.
- Squelette du plugin Moodle `local_iliasmigration`.

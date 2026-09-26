# Changelog

## 0.20.0-alpha — 2026-09-26

- Validation des phases 1 à 7 sur le POC ILIAS 10 -> Moodle.
- Phase 7.1 : utilisateurs, inscriptions, rôles et mappings persistants.
- Phase 7.3 : progression/résultats du POC inventoriés et classifiés.
- Phase 7.4 : Forum, auteurs, 2 discussions, 7 posts et 3 pièces jointes avec apply idempotent.
- Phase 7.5 : auteurs Blog validés sans réattribution nécessaire.
- Phase 7.6 : auteurs et dates des pages Wiki courantes réconciliés sans création de faux historique.
- Ajout de garde-fous sur les mappings d'identité, notifications Forum et historique Wiki.
- Objet ILIAS Groupe complet (#22) et POC avancé de progression (#41) explicitement différés.
- Démarrage de la validation finale end-to-end / release candidate (#48).

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

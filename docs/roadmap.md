# Roadmap

## POC de référence — état au 27 septembre 2026

Le POC ILIAS 10 -> Moodle est validé sur les phases 1 à 7 pour le cours ILIAS `ref_id=128 / obj_id=504` vers la cible fraîche Moodle `course id=2 / ILIAS-128`.

## Phase 1 — Inventaire et analyse `[TERMINÉE]`

- [x] analyse d'un export ILIAS 10 réel ;
- [x] reconstruction de l'arborescence et des métadonnées ;
- [x] génération du modèle neutre `migration.json` ;
- [x] rapports et diagnostics exploitables ;
- [x] préparation reproductible des packages de migration.

## Phase 2 — Structure `[TERMINÉE]`

- [x] plugin Moodle installable ;
- [x] catégories et chemin de catégories ;
- [x] cours ;
- [x] sections et sous-sections ;
- [x] politique des dossiers profonds ;
- [x] ordre global ;
- [x] mappings persistants ;
- [x] dry-run et apply idempotents.

## Phase 3 — Ressources simples `[TERMINÉE]`

- [x] fichiers et médias ;
- [x] URL ;
- [x] HTML simple ;
- [x] liens internes ILIAS -> Moodle ;
- [x] placements déterministes ;
- [x] idempotence.

## Phase 4 — SCORM `[TERMINÉE]`

- [x] extraction des packages ;
- [x] import Moodle ;
- [x] mapping des paramètres compatibles ;
- [x] deux SCORM du POC migrés.

## Phase 5 — Learning Modules `[TERMINÉE]`

- [x] parsing du module ILIAS ;
- [x] chapitres/pages/médias ;
- [x] conversion vers Moodle Book ;
- [x] validation réelle du POC.

## Phase 6 — Tests et banques de questions `[TERMINÉE]`

- [x] inventaire des types de questions ;
- [x] parsing QTI ;
- [x] représentation neutre ;
- [x] génération Moodle XML ;
- [x] Question Bank ;
- [x] Quiz ;
- [x] 11 questions / 46 points ;
- [x] apply idempotent et validation visuelle.

## Phase 6.5 — Objets pédagogiques étendus `[TERMINÉE]`

- [x] Content Page ;
- [x] Glossaire ;
- [x] Wiki ;
- [x] Exercice ;
- [x] Forum ;
- [x] Mediacast ;
- [x] Blog ;
- [x] Media Pool.

## Phase 7 — Utilisateurs, contributions et progression `[TERMINÉE POUR LE POC]`

- [x] Phase 7.1 — 6 utilisateurs, inscriptions et rôles ;
- [x] mappings utilisateurs GLOBAL, dont `ILIAS 6/root -> Moodle 2/admin` ;
- [x] Phase 7.3 — progression/résultats : 0 `MIGRATE`, 2 `HISTORY_ONLY`, 21 `NO_DATA` ;
- [x] Phase 7.3 — mappings robustes `sourcecourse 504/128` et Exercise 1 -> 4 ;
- [x] Phase 7.4 — Forum : 3 auteurs, 2 discussions, 7 posts, 3 pièces jointes ;
- [x] Phase 7.5 — Blog : 2 auteurs/propriétaires déjà conformes, no-op ;
- [x] Phase 7.6 — deux Wikis : auteur courant et dates réconciliés ;
- [x] seconds passages idempotents / sans écriture.

## Validation de consolidation

- [x] cible fraîche Moodle 5.0.2 / course id 2 ;
- [x] PHP 8.3.35 ;
- [x] syntaxe des resolvers Phase 7 ;
- [x] tests PHP Phase 7.3 et 7.4 ;
- [x] suite PHP : 14 / 14 réussis ;
- [x] suite Python de référence : 57 tests réussis ;
- [x] Phase 7.3 dry-run sans écriture ;
- [x] Forum/Blog/Wiki en état final idempotent.

## Backlog non bloquant

- [ ] #22 — objet ILIAS Groupe complet : `DEFERRED` tant qu'un mapping conteneur + contenus + membres + restrictions n'est pas validé ;
- [ ] #41 — POC avancé de progression avec données réellement migrables et états multiples.

Ces sujets restent ouverts volontairement et ne remettent pas en cause la validation du périmètre actuel.

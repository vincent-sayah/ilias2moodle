# Roadmap

## POC de référence — état au 26 septembre 2026

Le POC ILIAS 10 -> Moodle est validé sur les phases 1 à 7. Les cases ci-dessous décrivent le périmètre effectivement démontré sur le cours de référence ILIAS `ref_id=128 / obj_id=504` vers Moodle `course id=5 / ILIAS-128`.

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
- [x] sections ;
- [x] sous-sections ;
- [x] politique des dossiers profonds ;
- [x] ordre global ;
- [x] mappings persistants ;
- [x] dry-run et apply idempotents.

## Phase 3 — Ressources simples `[TERMINÉE]`

- [x] fichiers et médias ;
- [x] URL ;
- [x] HTML simple ;
- [x] liens internes ILIAS -> Moodle ;
- [x] placements section/sous-section/section synthétique ;
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
- [x] transformations de scoring documentées ;
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

- [x] utilisateurs — Phase 7.1 ;
- [x] inscriptions — 6 utilisateurs du cours ;
- [x] rôles — student/editingteacher/teacher ;
- [x] mappings utilisateurs GLOBAL ;
- [x] progression/résultats du POC — classification MIGRATE/PARTIAL/HISTORY_ONLY/NO_DATA ;
- [x] Forum — 3 auteurs, 2 discussions, 7 posts, 3 pièces jointes ;
- [x] Blog — auteurs des 2 billets validés ;
- [x] Wiki — auteur courant et dates des 3 pages réconciliés ;
- [x] dry-runs finaux sans écriture et état avant/après identique.

## Validation finale / Release Candidate

- [x] plugin de référence synchronisé avec l'installation Moodle ;
- [x] upgrade Moodle vers le build plugin validé ;
- [x] syntaxe PHP complète ;
- [x] tests PHP de régression ;
- [x] Ruff ;
- [x] Pytest : 43 tests ;
- [x] audit global du cours cible ;
- [x] absence de doublons Forum / Question Bank issus de la migration ;
- [x] Forum/Blog/Wiki en état final idempotent ;
- [x] empreinte de base avant/après dry-runs inchangée (`STATE_DIFF_RC=0`).

## Backlog non bloquant pour la RC

- [ ] #22 — objet ILIAS Groupe complet : `DEFERRED / HISTORY_ONLY` tant qu'un mapping conteneur + contenus + restrictions n'est pas validé ;
- [ ] #41 — POC avancé de progression multi-utilisateurs et multi-états.

Ces deux sujets restent ouverts volontairement et ne remettent pas en cause la validation du périmètre POC couvert par la RC.

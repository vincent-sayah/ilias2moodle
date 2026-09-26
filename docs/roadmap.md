# Roadmap

## Phase 1 — Inventaire et analyse `[EN COURS]`

- [x] structure du dépôt ;
- [x] CLI initial ;
- [x] modèle intermédiaire initial ;
- [x] client ILIAS de démonstration ;
- [x] rapport JSON/HTML initial ;
- [x] tests et CI initiaux ;
- [ ] identifier précisément les services disponibles sur l’instance ILIAS 10 cible ;
- [ ] implémenter l’authentification ;
- [ ] implémenter la découverte d’un cours réel ;
- [ ] extraire l’arborescence réelle ;
- [ ] extraire les métadonnées ;
- [ ] analyser les exports ILIAS ;
- [ ] produire un `migration.json` à partir d’un cours réel ;
- [ ] valider le rapport sur le cours POC.

## Phase 2 — Structure

- [ ] plugin Moodle installable ;
- [ ] catégories ;
- [ ] cours ;
- [ ] sections ;
- [ ] sous-sections ;
- [ ] ordre ;
- [ ] table de mapping des identifiants ;
- [ ] idempotence.

## Phase 3 — Ressources simples

- [ ] fichiers ;
- [ ] URL ;
- [ ] pages ;
- [ ] médias ;
- [ ] liens internes.

## Phase 4 — SCORM

- [ ] extraction package ;
- [ ] import Moodle ;
- [ ] mapping des paramètres compatibles.

## Phase 5 — Learning Modules

- [ ] parser ;
- [ ] chapitres ;
- [ ] pages ;
- [ ] médias ;
- [ ] conversion Moodle Book.

## Phase 6 — Tests

- [ ] inventaire des types de questions ;
- [ ] parser QTI ;
- [ ] représentation neutre ;
- [ ] génération Moodle XML ;
- [ ] banque de questions ;
- [ ] quiz.

## Phase 7 — Utilisateurs et progression

- [x] utilisateurs — Phase 7.1 validée sur POC ;
- [x] inscriptions — Phase 7.1 validée sur POC ;
- [x] rôles — member/admin/tutor validés vers student/editingteacher/teacher ;
- [ ] groupes — objet ILIAS `grp` différé : un Moodle Group simple n'est pas un équivalent fonctionnel complet ;
- [x] achèvements — Phase 7.3 analysée sur POC : aucune donnée `completed/failed` migrable ;
- [x] historiques et tentatives — Test/SCORM/Exercice inventoriés ; 2 états cours `in_progress` conservés `HISTORY_ONLY`, 21 cas `NO_DATA` ;
- [x] contributions Forum — auteurs, 2 discussions, 7 posts et 3 pièces jointes migrés avec arbre préservé et apply idempotent ;
- [ ] contributions Blog — prochain chantier ;
- [ ] contributions Wiki — après Blog.

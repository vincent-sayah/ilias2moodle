# Roadmap

## POC de référence — état au 4 octobre 2026

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
- [x] Phase 7.3 — POC avancé cours `827/282` : 12 identités, 2 Tests, 1 SCORM 2004 et 1 Exercise ;
- [x] Phase 7.3 — politique finale : **rapport historique uniquement**, aucune écriture de notes, tentatives ou completion dans les objets Moodle natifs ;
- [x] Phase 7.4 — Forum : 3 auteurs, 2 discussions, 7 posts, 3 pièces jointes ;
- [x] Phase 7.5 — Blog : 2 auteurs/propriétaires déjà conformes, no-op ;
- [x] Phase 7.6 — deux Wikis : auteur courant et dates réconciliés ;
- [x] seconds passages idempotents / sans écriture.

## Phase 8 — Console opérateur et exploitation `[BETA1]`

- [x] interface Moodle pour créer/lancer un run ;
- [x] détection des familles présentes dans `migration.json` ;
- [x] pipeline automatique Structure → Item Groups ;
- [x] journal persistant des runs, étapes et événements ;
- [x] état `WAITING_DECISION` sur erreur ;
- [x] action `Réessayer` ;
- [x] action `Ignorer et continuer` ;
- [x] statut final `COMPLETED_WITH_SKIPS` ;
- [x] rapport HTML ;
- [x] export JSON du compte rendu ;
- [x] capabilities Moodle dédiées ;
- [x] guide opérateur ;
- [ ] intégrer les extensions Phase 7 utilisant des inventaires auxiliaires ;
- [ ] refactoriser/raccorder la réconciliation d'ordre V2 à la console ;
- [ ] granularité d'ignorance par `source_ref_id` à l'intérieur d'une même famille ;
- [x] validation end-to-end nominale de la beta1 sur VM Moodle 5.0.2 : run #2 `COMPLETED`, cours Moodle `id=4 / ILIAS-282`, 621 mappings ;
- [x] validation du scénario `FAILED -> Ignorer et continuer -> COMPLETED_WITH_SKIPS` : run #4 puis revalidation run #6.

## Suite Phase 8 — ordre de traitement

Avant d'étendre la console, terminer les issues déjà ouvertes dans cet ordre :

1. **#68 — Console opérateur beta1** — **TERMINÉE**
   - test volontaire d'échec Wiki validé ;
   - `Ignorer et continuer` validé ;
   - `COMPLETED_WITH_SKIPS` validé.

2. **#69 — Mappings orphelins après suppression d'un cours Moodle** — **TERMINÉE / beta2**
   - détection sûre des mappings orphelins ;
   - reset explicite depuis la console ;
   - aucune suppression automatique d'un mapping dont la cible existe encore ;
   - remigration possible après suppression d'un cours cible.

3. **#66 — Operator V1.1 : automatisation complète et isolation objet par objet**
   - accepter/préparer un export ILIAS natif depuis l'interface ou un worker ;
   - automatiser les récupérations complémentaires read-only ;
   - intégrer Phase 7.1, Forum 7.4 et Wiki 7.6 ;
   - conserver Phase 7.3 en rapport historique uniquement ;
   - isolation et transactions objet par objet ;
   - réconciliation d'ordre V2 comme service orchestré ;
   - progression, annulation, rétention et rapports enrichis.

L'objectif V1.1 est qu'un opérateur parte autant que possible de l'export ZIP ILIAS et non d'un `migration.json` préparé manuellement.

L'issue **#22 — objet ILIAS Groupe complet** reste volontairement `DEFERRED` et ne bloque pas la qualification de la console opérateur.

## Phase 8 beta2 — reset des mappings orphelins

Développement #69 :

- [x] détection du mapping de cours orphelin avant création d'un run ;
- [x] refus du reset si le cours Moodle cible existe encore ;
- [x] page de confirmation opérateur ;
- [x] snapshot JSON + SHA-256 avant suppression ;
- [x] table d'audit `local_iliasmigration_reset` ;
- [x] suppression transactionnelle du scope exact ;
- [x] relance automatique d'un nouveau run après reset ;
- [x] garde moteur `courseorphanedmapping` en dehors de l'UI ;
- [x] validation réelle Moodle 5.0.2 : audit #2 de 618 mappings (6 legacy + 612 instance canonique), relance automatique, run #6 `COMPLETED_WITH_SKIPS` sans `ERROR_STALE_MAPPING`.

Version de développement : `0.21.0-beta2`.

## Validation de consolidation

- [x] cible fraîche Moodle 5.0.2 / course id 2 ;
- [x] PHP 8.3.35 ;
- [x] syntaxe des resolvers Phase 7 ;
- [x] tests PHP Phase 7.3 et 7.4 ;
- [x] suite PHP : 14 / 14 réussis ;
- [x] suite Python de référence : 57 tests réussis ;
- [x] Phase 7.3 dry-run sans écriture ;
- [x] Forum/Blog/Wiki en état final idempotent ;
- [x] RC3 : 89 fichiers PHP lintés sans erreur ;
- [x] RC3 : copie installée strictement identique à la candidate ;
- [x] RC3 : aucun CRLF dans le plugin installé ;
- [x] RC3 : premier upgrade Moodle réussi ;
- [x] RC3 : second upgrade sans mise à jour nécessaire.

## Backlog non bloquant

- [ ] #22 — objet ILIAS Groupe complet : `DEFERRED` tant qu'un mapping conteneur + contenus + membres + restrictions n'est pas validé ;
- [x] #41 — POC avancé de progression multi-utilisateurs validé sur le cours `827/282` ; clôturé en rapport historique uniquement.

Le seul backlog fonctionnel restant dans cette section est l'objet ILIAS Groupe (#22). Le POC avancé de progression (#41) est clôturé.
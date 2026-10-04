# Roadmap

## POC de référence — état au 4 octobre 2026

Les phases 1 à 7 du POC ILIAS 10 → Moodle sont validées. La Phase 7.3 est clôturée en rapport historique uniquement.

## Couverture fonctionnelle

- [x] Phase 1 — inventaire/analyse ;
- [x] Phase 2 — cours, sections, sous-sections, catégories, mappings ;
- [x] Phase 3 — ressources simples ;
- [x] Phase 4 — SCORM ;
- [x] Phase 5 — Learning Module → Book ;
- [x] Phase 6 — Question Pool + Quiz ;
- [x] Phase 6.5 — Content Page ;
- [x] Phase 6.5 — Glossaire ;
- [x] Phase 6.5 — Wiki ;
- [x] Phase 6.5 — Exercice ;
- [x] Phase 6.5 — Forum ;
- [x] Phase 6.5 — Mediacast ;
- [x] Phase 6.5 — Blog ;
- [x] Phase 6.5 — Media Pool ;
- [x] Phase 6.5 — Item Groups + ordre V2 ;
- [x] Phase 7.1 — utilisateurs, inscriptions, rôles ;
- [x] Phase 7.3 / #41 — POC avancé progression ; politique historique uniquement ;
- [x] Phase 7.4 — contributions Forum ;
- [x] Phase 7.5 — auteurs Blog ;
- [x] Phase 7.6 — auteurs/dates Wiki.

## Première version exploitable — opérateur V1

Branche : `operator-v1`.

Cible : `0.21.0-rc1`.

- [x] modèle persistant job / étapes / logs ;
- [x] capability opérateur ;
- [x] upload sécurisé d'un package préparé ;
- [x] chemin serveur vers `migration.json` ;
- [x] pipeline généré selon les objets présents ;
- [x] tâche ad hoc Moodle ;
- [x] lock par job ;
- [x] pause sur erreur non critique ;
- [x] `Ignorer et continuer` ;
- [x] retry à partir d'une étape ;
- [x] politique auto-continue ;
- [x] rapport final JSON ;
- [x] documentation opérateur ;
- [ ] CI de la branche ;
- [ ] qualification réelle sur Moodle 5.0.2 ;
- [ ] test d'échec volontaire d'une famille + reprise ;
- [ ] validation du second passage/idempotence de la console ;
- [ ] promotion sur `main` puis tag V1.

## Backlog fonctionnel

- [ ] #22 — objet ILIAS Groupe complet : modèle conteneur + membres + restrictions + contenus.

## V1.1 / améliorations opérateur

- [ ] préparation d'un export ILIAS brut directement depuis l'interface ou via worker de préparation ;
- [ ] automatiser Phase 7.1 dans le pipeline ;
- [ ] automatiser les enrichissements Phase 7.4/7.6 lorsque leurs sidecars sont disponibles ;
- [ ] isolation transactionnelle **objet par objet** à l'intérieur d'une famille ;
- [ ] transformer la réconciliation d'ordre V2 en service réutilisable par l'orchestrateur ;
- [ ] bouton d'annulation contrôlée ;
- [ ] politique de rétention/purge des packages et rapports ;
- [ ] vue de progression dynamique/polling ;
- [ ] export HTML/PDF du compte rendu en complément du JSON.

## Règle de promotion

Une candidate opérateur ne devient version exploitable publiée qu'après :

1. CI verte ;
2. upgrade Moodle réel ;
3. migration complète d'un package de référence ;
4. test pause/ignore/retry ;
5. contrôle du rapport ;
6. second passage sans doublon.

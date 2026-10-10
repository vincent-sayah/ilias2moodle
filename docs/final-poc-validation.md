# Validation finale du POC — 0.20.0-rc3

Date de consolidation : 27 septembre 2026

## Périmètre

Cours source :

- ILIAS 10.8 ;
- cours `obj_id=504 / ref_id=128` ;
- titre `cours test migration`.

Cible fraîche de validation :

- Moodle 5.0.2 ;
- cours `id=2` ;
- shortname `ILIAS-128`.

Plugin :

- `local_iliasmigration` ;
- release candidate courante `0.20.0-rc3` ;
- build `2026092701` ;
- compatibilité minimale déclarée : Moodle 4.5.

## Validation du code

La validation de consolidation est exécutée sur la cible Moodle avec PHP 8.3.35.

Résultat de la suite PHP autonome :

```text
TOTAL=14
PASSED=14
FAILED=0
PHP_SUITE_RC=0
```

Les deux tests ajoutés pour la consolidation Phase 7 valident :

- le flag Forum `apply_implemented=true` ;
- la résolution des mappings Phase 7.3, y compris le fallback unique et l'Exercise 1 -> N.

La suite Python de référence de la Phase 6.5 reste à 57 tests réussis.

Le PHP 7.4.29 installé localement sous Windows n'est pas un environnement de qualification : il ne supporte pas plusieurs constructions PHP 8 déjà utilisées par le projet.

## Qualification d'installation rc3

Qualification réalisée le 3 octobre 2026 sur la cible fraîche Moodle 5.0.2 / PHP 8.3.35.

Contrôles avant upgrade :

```text
PHP_FILES   = 89
PHP_LINT_RC = 0
COPY_DIFF_RC = 0
CRLF installés = 0
```

Le répertoire installé `/var/www/moodle/local/iliasmigration` est strictement identique à la candidate rc3 normalisée utilisée pour la qualification.

Premier upgrade Moodle :

```text
local_iliasmigration -> Succès
UPGRADE1_RC=0
```

Après purge des caches, la version installée reste :

```text
0.20.0-rc3
2026092701
```

Second upgrade :

```text
Aucune mise à jour nécessaire
UPGRADE2_RC=0
```

La promotion rc3 est donc validée à la fois sur le code, l'installation réelle et l'idempotence de l'upgrade Moodle.



### Paquet canonique rc3

Le paquet de distribution retenu est :

```text
local_iliasmigration-0.20.0-rc3.tar.gz
SHA256 06705a2162b2f01584a4f6adb584c2278d99d3988eec0309ccce1d1b7e66397a
```

Validation du paquet :

```text
version = 0.20.0-rc3
build = 2026092701
CRLF = 0
FINAL_PACKAGE_DIFF_RC=0
```

Le paquet doit être construit depuis `HEAD` à la racine du dépôt, en sélectionnant `moodle/local_iliasmigration`, puis repacké avec `local_iliasmigration/` comme racine d'archive. Il ne faut pas utiliser `git archive HEAD:moodle/local_iliasmigration`, car ce mode de sous-arbre ne tient pas compte du `.gitattributes` racine lors de la production de l'archive sous Git for Windows.

## Objets cibles de référence

La migration fraîche du cours `128` vers le cours Moodle `2` a produit les cibles de référence suivantes :

| Source ILIAS | Cible Moodle |
|---|---|
| SCORM ref 241 | CMID 18 |
| SCORM ref 242 | CMID 19 |
| Learning Module ref 243 | CMID 20 |
| Question Pool ref 235 | CMID 21 |
| Test ref 236 | CMID 22 |
| Content Page ref 270 | CMID 23 |
| Glossaire ref 272 | CMID 24 |
| Wiki ref 273 | CMID 25 |
| Exercice ref 274 | CMID 26 à 29 |
| Forum ref 275 | CMID 30 |
| Mediacast ref 276 | CMID 31 |
| Blog ref 277 | CMID 32 |
| Media Pool ref 278 | CMID 33 |
| Wiki ref 279 | CMID 34 |

L'Exercice est volontairement un mapping 1 -> N : les quatre unités ILIAS deviennent quatre activités `mod_assign`.

## Phase 7.1 — utilisateurs, inscriptions et rôles

Six participants source sont résolus et inscrits :

| ILIAS | Moodle | Rôle Moodle |
|---:|---:|---|
| 401 | 6 | student |
| 402 | 7 | student |
| 410 | 8 | student |
| 412 | 3 | editingteacher |
| 413 | 4 | editingteacher |
| 415 | 5 | teacher |

Le compte global `ILIAS 6/root` est mappé explicitement vers `Moodle 2/admin` sans inscription automatique au cours.

Le second passage réutilise les mêmes comptes, inscriptions et rôles.

## Phase 7.3 — progression et résultats

Le dry-run consolidé est strictement sans écriture :

```text
writes_performed = false
ready_for_apply  = false
apply_reason     = POC_HAS_NO_MIGRATABLE_PHASE73_DATA

MIGRATE      = 0
PARTIAL      = 0
HISTORY_ONLY = 2
UNSUPPORTED  = 0
NO_DATA      = 21
```

Les mappings d'objets sont désormais résolus même lorsque les mappings persistants utilisent `sourcecourse=128` alors que l'inventaire source porte `obj_id=504`.

Pour l'Exercice `obj_id=806 / ref_id=274`, les quatre mappings enfants sont résolus explicitement :

```text
274:assignment:1 -> CMID 26
274:assignment:2 -> CMID 27
274:assignment:3 -> CMID 28
274:assignment:4 -> CMID 29
```

Le resolver accepte ce fallback 1 -> N uniquement lorsqu'un seul couple `sourceinstance/sourcecourse` est possible ; un résultat ambigu est bloqué.

## Phase 7.4 — Forum

Forum ILIAS `obj_id=807 / ref_id=275` :

- Moodle CMID 30 / instance 2 ;
- 3 auteurs source résolus ;
- 2 discussions ;
- 7 posts ;
- 3 pièces jointes ;
- second dry-run : 2 discussions `KEEP`, 7 posts `KEEP`, 3 assets vérifiés ;
- second apply : aucune création supplémentaire, `writes_performed=false`.

Le resolver annonce désormais correctement que l'apply Forum est implémenté.

## Phase 7.5 — Blog

Blog ILIAS `obj_id=812 / ref_id=277` :

- Moodle `mod_data` CMID 32 / instance 2 ;
- 2 billets ;
- auteur source ILIAS `usr_id=6/root` ;
- cible Moodle `user 2/admin`.

Les deux records possèdent déjà le bon propriétaire. Le dry-run final retourne donc 0 changement, `apply_required=false` et `writes_performed=false`.

Un executor générique de réattribution restera nécessaire pour un futur POC où le resolver retournerait `REASSIGN_OWNER`.

## Phase 7.6 — Wiki

Deux Wikis ont été validés :

- `obj_id=801 / ref_id=273` -> Moodle CMID 25 / instance 1 / subwiki 1 ;
- `obj_id=823 / ref_id=279` -> Moodle CMID 34 / instance 2 / subwiki 2.

Pour les deux objets :

- auteur courant résolu vers `Moodle 2/admin` ;
- dates source réconciliées ;
- version courante Moodle mise à jour ;
- version 0 technique conservée ;
- contenu et assets inchangés ;
- second apply : `writes_performed=false`.

## Objet Groupe

L'objet ILIAS `obj_id=743 / ref_id=254` reste volontairement différé.

Un simple Moodle Group n'est pas un équivalent fonctionnel d'un objet ILIAS `grp`, qui peut être un conteneur de dépôt avec membres, contenus et restrictions.

La tentative initiale de Moodle Group a été supprimée et ne fait pas partie de l'état final validé.

## Conclusion

Le périmètre POC couvert par les phases 1 à 7 est reproductible et idempotent sur la cible fraîche Moodle 5.0.2.

L'élément fonctionnel volontairement hors périmètre reste :

- #22 — migration complète de l'objet ILIAS Groupe.

Le POC enrichi de progression #41 a depuis été validé et clôturé ; voir la validation complémentaire ci-dessous.

La release candidate courante est `0.20.0-rc3`. Ce build formalise la consolidation Phase 7 validée sur la cible fraîche ; aucun changement de schéma n'est associé à cette promotion.

## Validation complémentaire Phase 7.3 — 4 octobre 2026

Le backlog #41 a été rejoué sur un POC enrichi distinct du POC de référence :

- source ILIAS : cours `obj_id=827 / ref_id=282`, `Cours test RC 0.20` ;
- cible Moodle : cours `id=3 / ILIAS-282` ;
- 12 identités source résolues, 12 cibles présentes et inscrites, 0 anomalie d'identité ;
- inventaires détaillés : 2 Tests, 1 SCORM 2004 et 1 Exercise ;
- dry-run final : `MIGRATE=0`, `PARTIAL=6`, `HISTORY_ONLY=1`, `UNSUPPORTED=5`, `NO_DATA=32` ;
- `writes_performed=false`, `ready_for_apply=false`, `apply_implemented=false` ;
- politique finale : `PHASE73_HISTORICAL_REPORT_ONLY`.

Le Test `ref=357` contient deux résultats finaux fiables (1/1 passed et 0/1 failed, seuil ILIAS 50 %) mais les tentatives Quiz Moodle ne sont pas reconstructibles fidèlement. Il reste donc `HISTORY_ONLY`.

Le SCORM `ref=347` fournit l'état final agrégé du SCO et un compteur global de tentatives, mais pas le détail de chaque tentative. Moodle 5 matérialisant chaque tentative séparément, aucune tentative SCORM native n'est créée afin d'éviter d'inventer des données.

La Phase 7.3 est donc clôturée en **rapport historique uniquement**. Aucun `quiz_attempt`, `scorm_attempt`, gradebook ou état de completion Moodle n'est écrit par cette phase. Le ticket #41 n'est plus un backlog ouvert.

## Suite d'exploitation — Phase 8

La consolidation POC 0.20.0-rc3 reste la référence historique des phases 1 à 7.

Le développement suivant est la console opérateur `0.21.0-beta1`, suivie séparément dans la Phase 8. Elle ajoute orchestration, journal persistant, reprise/ignore et rapport de migration sans modifier les conclusions de validation de ce document.

Voir :

- `docs/operator-console.md` ;
- `docs/roadmap.md` ;
- PR #67.


## Validation complémentaire Phase 8 / Operator V1.1 — 10 octobre 2026

Une qualification complète de la console opérateur et du workflow de préparation natif a été réalisée sur un cours enrichi.

### Environnement

- source : ILIAS `10.8.0` ;
- cours : `obj_id=827 / ref_id=282`, `Cours test RC 0.20` ;
- cible : Moodle `5.0.2` ;
- Python : `3.11.13` ;
- plugin : `local_iliasmigration 0.22.0-beta1`, build `2026101001` ;
- package final : `course827_v13_ui`.

### Préparation du package

Le package final a été produit avec :

```text
total_items = 39
missing_count = 0
exercise_irss_collections_recovered = 2
exercise_irss_files_recovered = 0
```

Les deux collections IRSS de l'Exercise `356` étaient des collections valides et vides. Elles ont donc été traitées comme un succès fonctionnel et non comme une dépendance bloquante.

### Phase 6 — Kprim

La question réelle :

```text
external_id = 6214d3a5600970.08414694
ILIAS type  = assKprimChoice
title       = Formation Générale (53)
```

est normalisée comme :

```text
type = kprim
max_score = 1.0
kprim_scoring_supported = true
combination_count = 16
```

Le dry-run Phase 6 final a retourné :

```text
blocked_tests = 0
blocked_question_pools = 0
package_checks_ready = true
ready = true
apply_ready = true
scoring_policy_ready = true
```

### Item Groups

Trois Item Groups ILIAS étaient présents dans l'export natif :

| ref_id | obj_id | Titre | Cible Moodle |
|---:|---:|---|---|
| 349 | 996 | Module de formation | section |
| 350 | 997 | Médias | section |
| 355 | 1004 | Informations | `mod_subsection` |

Le parseur préserve désormais le schéma ItemGroup, les membres `obj_id/ref_id`, `HideTitle`, `Behaviour` et les références non résolues.

Dry-run final :

```text
discovered_item_groups = 3
checked_item_groups = 3
blocked_item_groups = 0
root_section_count = 2
subsection_count = 1
member_count = 7
root_structure_ready = true
ready = true
apply_ready = true
```

### Reset des mappings orphelins

La qualification a volontairement supprimé plusieurs cours Moodle cibles pendant les itérations de test. La console a détecté les mappings `ORPHANED`, produit un snapshot d'audit avec SHA-256, supprimé le périmètre logique concerné puis relancé la migration.

Le scénario couvre également les mappings historiques `sourceinstance=''`.

### Run final

Le run final V13 a terminé avec succès :

| Étape | Résultat |
|---|---|
| Structure du cours | SUCCESS |
| Ressources simples | SUCCESS |
| SCORM | SUCCESS |
| Learning Modules vers Book | SUCCESS |
| Banques de questions et Quiz | SUCCESS |
| Content Pages | SUCCESS |
| Glossaires | SKIPPED — aucun objet source |
| Wikis | SUCCESS |
| Exercices | SUCCESS |
| Forums | SKIPPED — aucun objet source |
| MediaCasts | SUCCESS |
| Blogs | SUCCESS |
| Media Pools | SUCCESS |
| Item Groups | SUCCESS |

Le contrôle fonctionnel final dans Moodle a été validé après la migration.

### Conclusion Phase 8 V1.1

Le workflow `export ZIP ILIAS -> préparation -> récupération IRSS -> validation -> reset audité si nécessaire -> migration opérateur -> contrôle fonctionnel Moodle` est validé de bout en bout sur ILIAS 10.8.0 et Moodle 5.0.2 pour le cours `827/282`.

Cette qualification constitue la référence fonctionnelle de la branche opérateur intégrée à `main`.

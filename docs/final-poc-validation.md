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

Les éléments volontairement hors périmètre restent :

- #22 — migration complète de l'objet ILIAS Groupe ;
- #41 — POC enrichi de progression avec états réellement migrables.

La release candidate courante est `0.20.0-rc3`. Ce build formalise la consolidation Phase 7 validée sur la cible fraîche ; aucun changement de schéma n'est associé à cette promotion.
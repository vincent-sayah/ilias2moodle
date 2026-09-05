# Phase 6 — Questions ILIAS et Quiz Moodle

## POC de référence

Export ILIAS 10.8 v5 :

- cours `ref_id=128`, `obj_id=504`
- banque de questions `ref_id=235`, `obj_id=712`, titre `bdq_sayah`
- test `ref_id=236`, `obj_id=713`, titre `test`
- archive `1788628522__0__crs_504.zip`

Le sous-export `qpl_712` ne contient pas de QTI de questions dans ce v5. Il contient le
conteneur `TestQuestionPool` et ses métadonnées. Le QTI pédagogique complet est présent
dans le sous-export du test `tst_713`.

Le test contient 11 questions couvrant 8 types ILIAS :

| Type ILIAS | Type neutre | Moodle qtype candidat |
| --- | --- | --- |
| `assSingleChoice` | `single_choice` | `multichoice` |
| `assMultipleChoice` | `multiple_choice` | `multichoice` ou transformation si scoring non natif |
| `assNumeric` | `numeric` | `numerical` |
| `assMatchingQuestion` | `matching` | `match` ou transformation si poids inégaux |
| `assTextQuestion` | `essay` | `essay` |
| `assTextSubset` | `short_answer` | `shortanswer` |
| `assClozeTest` | `cloze` | `multianswer` |
| `assOrderingQuestion` | `ordering` | `ordering` |

Observations du POC réel :

- Matching : trois associations scorées séparément (`4 + 2 + 5 = 11` points) ;
- Multiple Choice `maths10` et `pairs` : ILIAS attribue aussi `1` point lorsque les
  options incorrectes `1` et `3` ne sont pas cochées ;
- Numeric : intervalle accepté `[4, 6]` pour `3` points ;
- Essay : notation manuelle avec `WritingScore maxvalue=5` ;
- TextSubset : réponse `paris`, `3` points, comparaison insensible à la casse ;
- Cloze : gap `gap_0`, réponse `paris`, `5` points ;
- Ordering : trois positions à `1.6666666666667`, avec `points=5` déclaré par ILIAS.

## Identifiants

`ident` peut changer lorsqu'une question de banque est insérée dans un test. Le champ
QTI `externalId` est donc conservé séparément comme identifiant source stable potentiel.
La relation définitive banque → question Moodle devra être validée sur plusieurs exports
avant d'être considérée comme universelle.

## Modèle neutre

Le module `ilias2moodle.ilias.qti` produit deux fichiers :

- `questions.json` : contenu pédagogique normalisé et règles de scoring ILIAS conservées ;
- `quiz.json` : ordre du test, métadonnées d'assessment et barème total.

Le modèle ne convertit pas silencieusement les scores ILIAS en fractions Moodle. Il conserve :

- les scores sélectionné / non sélectionné des QCM ;
- les bornes numériques ;
- les paires et points du Matching ;
- le barème manuel de la rédaction ;
- les réponses acceptées des réponses courtes et Cloze ;
- l'ordre correct et les points de l'Ordering ;
- les règles QTI brutes sous `scoring_rules`.

Le sens des paires Matching est déterminé à partir de `match_group`. Sur le POC réel,
ILIAS stocke les conditions de scoring sous la forme cible,source ; la représentation
neutre expose volontairement source → cible, par exemple `france → paris`.

Pour l'Ordering, le champ ILIAS `points` est utilisé comme note maximale lorsqu'il est
présent. Les points détaillés des positions restent conservés tels quels dans les règles
brutes. Cela évite de transformer `5` en `5.0000000000001` à cause des flottants.

## Intégration à prepare-export

`prepare-export` génère automatiquement les quatre fichiers suivants pour chaque test
ayant un QTI :

```text
tests/<ref_id>/questions.xml
tests/<ref_id>/test-structure.xml
tests/<ref_id>/questions.json
tests/<ref_id>/quiz.json
```

Le package expose également les compteurs :

- `test_files` : XML bruts extraits ;
- `test_normalizations` : tests normalisés avec succès ;
- `normalized_questions` : nombre total de questions normalisées.

Les métadonnées de l'objet test dans `migration.json` contiennent notamment :

- `migration_questions_path` ;
- `migration_quiz_path` ;
- `normalized_question_count` ;
- `normalized_unsupported_count` ;
- `normalized_total_max_score`.

Le fichier `test-structure.xml` est utilisé pour l'ordre via `QRef`. S'il est absent,
le parseur peut utiliser l'ordre du QTI comme repli.

## Validation réelle du package v5

Validation exécutée sur ILIAS 10.8 après intégration automatique :

- `question_count = 11` ;
- `ordered_question_count = 11` ;
- `unsupported_count = 0` ;
- `unresolved_question_refs = []` ;
- 8 types neutres distincts ;
- `total_max_score = 46.0` ;
- Matching : `france → paris`, `italie → rome`, `espagne → madrid` ;
- Ordering : `max_score = 5.0` ;
- `missing_count = 0` ;
- `test_normalizations = 1` ;
- `normalized_questions = 11`.

Les quatre fichiers attendus sont présents directement sous `tests/236/`.

L'outil `tools/normalize-phase6.py` reste disponible pour le diagnostic standalone, mais
il n'est plus nécessaire dans le flux normal de préparation du package.

## Moodle 5.0 — stratégie de banque

Moodle 5.0 distingue les banques de questions partagées du cours et la banque privée de
chaque Quiz.

Pour le POC v5 :

- l'objet ILIAS `question_pool` `ref_id=235` est planifié vers `mod_qbank` ;
- comme son sous-export ne contient aucun QTI de questions, la banque Moodle est traitée
  comme un conteneur vide : aucune question n'est inventée ;
- les 11 questions réellement exportées dans le test `ref_id=236` sont planifiées dans
  la banque privée du futur `mod_quiz` correspondant au test.

Cette politique évite d'attribuer artificiellement au pool des questions dont l'export
ILIAS ne prouve pas l'appartenance.

## Dry-run Moodle Phase 6

Le plugin Moodle `local_iliasmigration` `0.11.1-alpha` fournit un dry-run Phase 6 :

```bash
php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --phase=6 \
  --dry-run
```

Le dry-run :

- revalide les packages Phases 3, 4 et 5 ;
- exige que les objets Phases 2 à 5 soient déjà synchronisés (`UPDATE`) ;
- vérifie `mod_qbank`, `mod_quiz`, `mod/quiz/locallib.php`, le helper Question Bank 5.0
  et le format XML core ;
- vérifie que les qtypes Moodle `multichoice`, `numerical`, `match`, `essay`,
  `shortanswer`, `multianswer` et `ordering` sont utilisables ;
- valide les identités source et les schémas 1.0 de `questions.json` et `quiz.json` ;
- valide les comptes, identifiants uniques, types, ordre, QRef et barème total ;
- produit `quiz_preview` avec l'ordre futur des 11 questions et leur qtype Moodle ;
- signale la banque ILIAS sans contenu exporté sans la bloquer ;
- signale les règles de notation qui nécessitent une politique de conversion explicite.

Le premier dry-run réel Moodle 5.0.2 a confirmé :

- toutes les API/prérequis Phase 6 disponibles ;
- tous les qtypes nécessaires disponibles ;
- `checked_tests = 1` ;
- `blocked_tests = 0` ;
- `phase6_prerequisites.ready = true` ;
- `phase6_package.ready = true` ;
- aucune écriture Moodle (`writes_performed = false`).

## Revues de fidélité pédagogique

Quatre questions du POC nécessitent une politique explicite avant l'apply :

1. Matching `capital` : poids des paires `4`, `2`, `5`. Le qtype Moodle `match`
   standard ne reproduit pas ces poids individuels tels quels. Une transformation
   pondérée conservant un seul slot Quiz doit être utilisée si l'on veut conserver le
   barème exact de `11` points.
2. Multiple Choice `maths10` : ILIAS accorde un point pour sélectionner les bonnes
   options mais aussi un point pour laisser les mauvaises options non sélectionnées.
3. Multiple Choice `pairs` : même règle sélection/non-sélection que `maths10`.
4. Ordering `vertical` : le scoring ILIAS est par position absolue. Moodle Ordering
   fournit précisément `GRADING_ABSOLUTE_POSITION`; cette stratégie est la candidate
   native pour reproduire le POC.

Depuis `0.11.1-alpha`, le validateur ajoute
`MULTICHOICE_UNSELECTED_SCORING_REVIEW` pour chaque QCM concerné. Il conserve
`phase6_package.ready=true`, car le package est valide, mais l'apply reste désactivé tant
que ces politiques ne sont pas implémentées et vérifiées.

Pour les Matching/QCM dont les règles ne rentrent pas dans les qtypes natifs sans perte,
la piste privilégiée est une transformation Moodle `multianswer`/Cloze pondérée : elle
permet de conserver une seule question/slot et les poids source tout en rendant la
transformation explicite dans le rapport de migration.

## État du code

Le dry-run Moodle Phase 6 est présent sur `main` :

- `classes/phase6_plan_builder.php` ;
- `classes/phase6_package_validator.php` ;
- `classes/phase6_scoring_policy_validator.php` ;
- routage `--phase=6 --dry-run` dans `classes/importer.php` ;
- option CLI dans `cli/import.php` ;
- version plugin `0.11.1-alpha`.

L'apply Phase 6 est explicitement refusé. Le code n'écrit donc encore aucune banque,
question, activité Quiz ou slot de question Moodle.

## Périmètre actuel

La prochaine étape est de revalider le dry-run avec les quatre revues de scoring, puis
d'implémenter le chemin d'apply via les API/outils core Question Bank et Quiz, sans
INSERT/UPDATE direct dans les tables pédagogiques Moodle.

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

| Type ILIAS | Type neutre |
| --- | --- |
| `assSingleChoice` | `single_choice` |
| `assMultipleChoice` | `multiple_choice` |
| `assNumeric` | `numeric` |
| `assMatchingQuestion` | `matching` |
| `assTextQuestion` | `essay` |
| `assTextSubset` | `short_answer` |
| `assClozeTest` | `cloze` |
| `assOrderingQuestion` | `ordering` |

## Identifiants

`ident` peut changer lorsqu'une question de banque est insérée dans un test. Le champ
QTI `externalId` est donc conservé séparément comme identifiant source stable potentiel.
La relation définitive banque → question Moodle devra être validée sur plusieurs exports
avant d'être considérée comme universelle.

## Modèle neutre

Le module `ilias2moodle.ilias.qti` produit deux fichiers :

- `questions.json` : contenu pédagogique normalisé et règles de scoring ILIAS conservées ;
- `quiz.json` : ordre du test, métadonnées d'assessment et barème total.

Le modèle ne convertit pas encore les scores ILIAS en fractions Moodle. Il conserve :

- les scores sélectionné / non sélectionné des QCM ;
- les bornes numériques ;
- les paires et points du Matching ;
- le barème manuel de la rédaction ;
- les réponses acceptées des réponses courtes et Cloze ;
- l'ordre correct et les points de l'Ordering ;
- les règles QTI brutes sous `scoring_rules`.

## Validation locale sur le v5

Après `prepare-export` :

```bash
python3.11 tools/normalize-phase6.py \
  --qti /opt/ilias2moodle-data/results/course-128-v5/tests/236/questions.xml \
  --structure /opt/ilias2moodle-data/results/course-128-v5/tests/236/test-structure.xml \
  --output /opt/ilias2moodle-data/results/course-128-v5/tests/236/normalized \
  --source-ref-id 236 \
  --source-obj-id 713 \
  --title test
```

Résultats attendus sur le POC :

- `question_count = 11`
- `ordered_question_count = 11`
- `unsupported_count = 0`
- `unresolved_question_refs = []`
- 8 types neutres distincts.

## Périmètre actuel

Cette étape est volontairement limitée à la normalisation ILIAS → JSON neutre.
Aucune écriture Moodle Question Bank / Quiz n'est encore effectuée.

La prochaine étape est de valider les JSON du v5 puis de définir, type par type, les
mappings Moodle et les règles d'arrondi / normalisation des scores.

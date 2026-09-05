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

Observations du POC réel :

- Matching : trois associations scorées séparément (`4 + 2 + 5 = 11` points) ;
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

Le modèle ne convertit pas encore les scores ILIAS en fractions Moodle. Il conserve :

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

`prepare-export` génère maintenant automatiquement les quatre fichiers suivants pour
chaque test ayant un QTI :

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

## Résultat attendu sur le v5

Après `prepare-export` sur l'archive de référence :

- `question_count = 11` ;
- `ordered_question_count = 11` ;
- `unsupported_count = 0` ;
- `unresolved_question_refs = []` ;
- 8 types neutres distincts ;
- `total_max_score = 46.0` ;
- Matching : `france → paris`, `italie → rome`, `espagne → madrid` ;
- Ordering : `max_score = 5.0`.

L'outil `tools/normalize-phase6.py` reste disponible pour le diagnostic standalone, mais
il n'est plus nécessaire dans le flux normal de préparation du package.

## Périmètre actuel

Cette étape reste limitée à la normalisation ILIAS → JSON neutre. Aucune écriture Moodle
Question Bank / Quiz n'est encore effectuée.

La prochaine étape est la validation du package v5 généré automatiquement, puis la
construction du dry-run Moodle Question Bank + Quiz.

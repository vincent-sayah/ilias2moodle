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

## Kprim ILIAS

Le POC RC4 sur le cours ILIAS `ref_id=282` a rencontré un type supplémentaire :
`assKprimChoice`.

Le cas réel `il_0_qst_1194` contient quatre propositions et un barème QTI
tout-ou-rien : la combinaison `1,1,0,1` vaut 1 point et toute autre
combinaison vaut 0.

À partir du correctif RC4, le parseur :

- normalise `assKprimChoice` en type neutre `kprim` ;
- évalue les conditions QTI booléennes `and/or/not/varequal` pour toutes les
  combinaisons binaires ;
- conserve ainsi la table de score complète plutôt qu'une approximation.

L'apply Moodle transforme ensuite une question Kprim en une seule question
`multichoice` à réponse unique contenant toutes les combinaisons possibles.
Pour quatre propositions, cela produit 16 réponses. Chaque réponse reçoit
exactement la fraction correspondant au score QTI source.

Cette représentation modifie l'interaction visuelle, mais conserve :

- un seul slot Moodle pour une question ILIAS ;
- la note maximale ;
- le score de chaque combinaison, y compris le tout-ou-rien ;
- l'identité source et l'idempotence des mappings.

## Cloze ILIAS avec trous numériques

Le cours RC4 `ref_id=282` a révélé un second cas réel de
`assClozeTest` : la question `il_0_qst_1432` contient quatre
`response_num` et non des `response_str`.

Le barème source est explicite :

- `gap_0 = 18` : 0,5 point ;
- `gap_1 = 15` : 0,5 point ;
- `gap_2 = 112` : 0,5 point ;
- `gap_3 = 114` : 0,5 point ;
- note maximale : 2 points.

Le parseur RC4 reconnaît désormais, dans un Cloze, les réponses
`response_str` et `response_num` dans leur ordre de présentation.
Chaque trou normalisé indique `input_type=text|numeric`.

Pour les trous numériques dont ILIAS exprime la correction avec
`varequal`, le générateur Moodle produit une sous-question Cloze
`NUMERICAL` avec tolérance 0. La note du trou est conservée comme
poids de la sous-question. Les autres comparateurs numériques restent
volontairement refusés tant qu'une politique de tolérance n'a pas été
validée sur un export réel.

## Poids Cloze Moodle : normalisation entière et préflight core

Le premier apply RC4 sur le cours `ref_id=282` a révélé une contrainte du
parseur Moodle `qtype_multianswer` : le poids placé avant le premier `:`
dans une réponse intégrée doit être constitué de chiffres entiers.

Exemple refusé par Moodle :

```text
{0.5:NUMERICAL:=18:0}
```

Le générateur RC4 normalise désormais tous les poids positifs d'une même
question Cloze vers le plus petit rapport entier équivalent. Ainsi quatre
trous ILIAS à `0,5` point deviennent quatre normes Moodle `1`. Le slot
Quiz conserve la note maximale ILIAS de 2 points, donc chaque trou reste
pondéré à 0,5 point dans le Quiz.

La normalisation est appliquée aux trois chemins Cloze du projet :

- Cloze ILIAS natif ;
- Matching pondéré transformé en Cloze ;
- QCM sélection/non-sélection transformé en Cloze.

Pour éviter un nouveau faux positif de dry-run, le préflight Phase 6 fait
désormais passer chaque question `multianswer` générée dans
`qtype_multianswer_extract_question()` puis
`qtype_multianswer_validate_question()`, sans écriture en base. Une syntaxe
Cloze que Moodle refuserait à l'import bloque donc désormais le dry-run avant
tout `--apply`.

## Fractions QCM non standards Moodle

Le premier apply Phase 6 du cours RC4 `ref_id=282` a révélé cinq
`assMultipleChoice` dont les scores positifs ILIAS sont répartis exactement
en `0,34 / 0,33 / 0,33` sur 1 point, ou
`0,68 / 0,66 / 0,66` sur 2 points.

Le qformat XML Moodle, lorsqu'il est utilisé avec `matchgrades=error`, refuse
ces fractions car elles ne figurent pas dans
`question_bank::fraction_options_full()`. Les arrondir à des fractions Moodle
standards modifierait le barème ILIAS et n'est donc pas accepté.

Lorsque les conditions suivantes sont réunies :

- type neutre `multiple_choice` ;
- aucun score pour une option non sélectionnée ;
- au moins une fraction sélectionnée non standard pour Moodle ;
- somme exacte des scores positifs = `max_score` ILIAS ;

la Phase 6 applique désormais la politique
`MULTICHOICE_NONSTANDARD_FRACTIONS_TO_CLOZE`.

La question devient une seule question Moodle Cloze contenant une interaction
`MULTIRESPONSE`. Les pourcentages ILIAS sont conservés tels quels, par
exemple `%34%`, `%33%`, `%33%`. Le slot du Quiz conserve le
`max_score` ILIAS, donc le score absolu reste inchangé.

Le préflight vérifie également chaque `multichoice` restant avec
`match_grade_options(..., 'error')`, afin qu'une future fraction native
incompatible soit bloquée au dry-run au lieu d'échouer pendant l'import réel.

## Matching ILIAS contenant des images

Le troisième apply RC4 du cours `ref_id=282` a révélé un cas que le modèle
neutre ne conservait pas encore : certains `assMatchingQuestion` utilisent
des `response_label` contenant des `matimage embedded="base64"` au lieu
d'un `mattext`.

Dans le cours de validation :

- `il_0_qst_1169` et `il_0_qst_1170` utilisent des images côté source ;
- `il_0_qst_1574` utilise cinq logos côté cible.

L'ancien parseur ne lisait que `mattext`, ce qui produisait des
`source_text=""` ou `target_text=""`. Moodle pouvait alors créer des stems
vides ou refuser l'import avec `qtype_match::nomatchinganswer`.

Le modèle neutre conserve désormais, pour chaque `response_label`, les
médias embarqués base64 (nom, type, encodage et payload validé). Les paires
Matching exposent également leurs médias source/cible.

La Phase 6 applique `IMAGE_MATCHING_TO_CLOZE` dès qu'un Matching contient
un média :

- image source + cible texte : l'image est affichée directement à côté d'un
  menu Cloze textuel ;
- cible image : les images sont affichées dans une légende A/B/C... et les
  menus utilisent ces jetons, car les choix du qtype Moodle Matching natif
  sont rendus avec `format_string()` et ne peuvent pas afficher de façon
  fiable des images dans un `<option>`.

Les images sont importées avec le mécanisme Moodle XML natif
`@@PLUGINFILE@@` + `<file encoding="base64">`. Le score de chaque paire
et le `max_score` ILIAS sont conservés.

Le préflight rejette également tout Matching natif restant qui contient un
stem ou une réponse vide, afin que le cas `nomatchinganswer` soit détecté au
dry-run avant toute écriture.

## Préflight des contenus de banques de questions

Avant cette correction RC4, le préflight Moodle XML de la Phase 6 ne parcourait
que les opérations `test`. Les Question Pools étaient validés comme
conteneurs, mais les questions réellement exportées des pools n'étaient pas
soumises au même builder ni aux mêmes parseurs Moodle que les questions de
quiz.

Ce comportement était insuffisant : `phase6_executor` importe bien le contenu
des Question Pools via `phase6_moodle_xml_builder` puis le qformat XML Moodle.
Un pool pouvait donc être déclaré prêt au dry-run et échouer seulement pendant
`--apply`.

La Phase 6 valide désormais les pools à deux niveaux :

- validation structurelle de leur `questions.json` : identité ILIAS du pool,
  schéma, compteurs, identifiants uniques, types pris en charge et
  `max_score > 0` ;
- préflight Moodle XML complet des pools contenant du contenu exporté, avec les
  mêmes contrôles que les tests : parser Cloze natif, fractions multichoice
  strictes, Matching natif complet et transformations score-préservantes.

Les pools `CONTAINER_ONLY` restent non bloquants et ne reçoivent aucun
contenu inventé.

Les métriques Phase 6 exposent séparément les pools de contenu vérifiés,
les pools bloqués, les préflights XML de qbank bloqués et le nombre de
transformations score-préservantes utilisées par les Question Pools.

# Phase 7.3 — Progression et résultats historiques

## État

La Phase 7.3 est clôturée en mode **rapport historique uniquement**.

Aucune donnée de progression ou de résultat ILIAS n'est injectée dans les structures natives Moodle lorsque la sémantique source ne peut pas être reconstruite fidèlement. Cette décision évite de fabriquer des tentatives, notes ou états de completion.

## POC avancé de validation

Source :

- ILIAS 10 ;
- client `ilias10` ;
- cours `obj_id=827 / ref_id=282` ;
- titre `Cours test RC 0.20`.

Cible :

- Moodle 5.0.2 ;
- cours `id=3` ;
- shortname `ILIAS-282`.

Identités :

- 12 participants source ;
- 12 mappings utilisateurs ;
- 12 utilisateurs cibles présents ;
- 12 utilisateurs inscrits ;
- 0 anomalie d'identité.

## Politique d'écriture

La Phase 7.3 est strictement read-only.

Elle ne crée ni ne modifie :

- `quiz_attempts` ;
- résultats natifs Quiz ;
- notes du gradebook ;
- `scorm_attempt` ;
- tracking SCORM Moodle ;
- completion d'activité ;
- completion de cours ;
- mappings supplémentaires liés aux résultats.

Les JSON d'extraction et le rapport du dry-run constituent la trace historique.

Le rapport annonce explicitement :

```text
historical_reporting_only = true
native_moodle_writes      = false
automatic_apply           = false
ready_for_apply           = false
apply_implemented         = false
apply_reason              = PHASE73_HISTORICAL_REPORT_ONLY
```

## Test ref 331 — Test global

Aucune tentative ou note source exploitable.

```text
classification = NO_DATA
reason = NO_TEST_ATTEMPTS_OR_SCORES
```

Aucune migration.

## Test ref 357 — test validation du cours

Deux résultats finaux ILIAS sont disponibles :

```text
ILIAS 401 / stagiaire.1
score       = 1 / 1
pourcentage = 100 %
résultat    = passed

ILIAS 402 / stagiaire.2
score       = 0 / 1
pourcentage = 0 %
résultat    = failed
```

Le barème ILIAS possède deux niveaux :

```text
0 %  -> failed
50 % -> passed
```

Le Quiz Moodle cible possède également un maximum de 1 point et ne contient aucun attempt pour les deux utilisateurs lors de la validation.

Les réponses individuelles permettant de reconstruire une tentative Moodle fidèle ne sont cependant pas disponibles. Une note native sans attempt créerait également un état susceptible d'être recalculé par le module Quiz.

```text
classification = HISTORY_ONLY
reason = TEST_FINAL_RESULTS_AVAILABLE_WITHOUT_RECONSTRUCTIBLE_QUIZ_ATTEMPTS
```

Aucune écriture Moodle.

## SCORM ref 347 — Gestion des incidents

Source :

```text
obj_id  = 994
ref_id  = 347
SCORM   = 2004
SCO     = cp_node_id 76
titre   = Gestion des incidents
```

État final disponible :

```text
ILIAS 401
package_attempts  = 1
completion_status = completed
success_status    = passed
total_time        = 152.69 s

ILIAS 402
package_attempts  = 2
completion_status = incomplete
success_status    = unknown
total_time        = 23.12 s
```

Cible Moodle :

```text
scorm id   = 3
sco id     = 6
identifier = Gestion_des_incidents_SCO
launch     = index_lms.html
titre      = Gestion des incidents
```

ILIAS conserve le compteur global `package_attempts`, mais les données `cmi_node` disponibles représentent l'état agrégé courant du SCO. Le détail des deux tentatives de l'utilisateur 402 n'est donc pas reconstructible.

Moodle 5 matérialise chaque tentative par une ligne distincte dans `scorm_attempt`. Créer deux tentatives nécessiterait d'inventer le contenu de la première tentative.

```text
classification = PARTIAL
reason = SCORM_FINAL_RUNTIME_STATE_AVAILABLE_BUT_ATTEMPT_HISTORY_INCOMPLETE
```

Aucune écriture Moodle.

## Exercise ref 356

Aucune soumission, note, marque ou commentaire source exploitable.

```text
classification = NO_DATA
reason = NO_EXERCISE_SUBMISSIONS_GRADES_MARKS_OR_COMMENTS
```

## Learning Progress

Les statuts ILIAS `completed`, `failed` et `in_progress` restent des informations historiques lorsqu'aucune sémantique Moodle équivalente n'est démontrée.

Sur la cible de validation, la completion du cours et des activités concernées n'était pas activée. La migration n'active pas ces mécanismes et n'écrit aucun état de completion.

## Dry-run de référence

```text
MIGRATE      = 0
PARTIAL      = 6
HISTORY_ONLY = 1
UNSUPPORTED  = 5
NO_DATA      = 32

writes_performed = false
ready_for_apply = false
apply_implemented = false
apply_reason = PHASE73_HISTORICAL_REPORT_ONLY
```

## Conclusion

La Phase 7.3 fournit une extraction read-only, la résolution des identités et des objets, une classification explicite et un rapport historique vérifiable.

Elle ne cherche volontairement pas à reconstruire des données Moodle natives lorsque la source ILIAS ne permet pas de le faire sans approximation. Cette politique privilégie la fidélité et évite de polluer les objets Moodle migrés.

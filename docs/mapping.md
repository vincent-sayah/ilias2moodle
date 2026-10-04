# Mapping ILIAS 10 → Moodle 4.5+

Cette matrice constitue le contrat fonctionnel courant du projet. La cible de qualification principale est Moodle 5.0.2.

| Objet ILIAS | Code | Cible Moodle | Phase | État |
|---|---|---|---:|---|
| Catégorie | `cat` | Catégorie Moodle | 2 | validé |
| Cours | `crs` | Cours | 2 | validé |
| Dossier niveau 1 | `fold` | Section | 2 | validé |
| Dossier niveau 2 | `fold` | `mod_subsection` | 2 | validé |
| Fichier | `file` | `mod_resource` | 3 | validé |
| URL / WebResource | `webr` | `mod_url` | 3 | validé |
| Module HTML | `htlm` | `mod_resource` | 3 | validé |
| SCORM | `sahs` | `mod_scorm` | 4 | validé |
| Learning Module | `lm` | `mod_book` | 5 | validé |
| Test | `tst` | `mod_quiz` | 6 | validé |
| Question Pool | `qpl` | `mod_qbank` | 6 | validé |
| Content Page | `copa` | `mod_page` | 6.5 | validé |
| Glossaire | `glo` | `mod_glossary` | 6.5 | validé |
| Wiki | `wiki` | `mod_wiki` | 6.5 | validé |
| Exercice | `exc` | un ou plusieurs `mod_assign` | 6.5 | validé |
| Forum | `frm` | `mod_forum` | 6.5 + 7 | structure + contributions validées |
| Mediacast | `mcst` | `mod_data` | 6.5 | validé (MP4 local + URL externe) |
| Blog | `blog` | `mod_data` | 6.5 + 7 | validé |
| Media Pool | `mep` | `mod_data` | 6.5 | validé |
| Item Group | `itgr` | Section / `mod_subsection` structurelle | 6.5 | validé |
| Utilisateurs / inscriptions / rôles | — | Users / enrolments / rôles | 7.1 | validé |
| Auteurs/contributions Forum | — | discussions/posts/assets | 7.4 | validé |
| Auteur/date Wiki courants | — | métadonnées Wiki | 7.6 | validé |
| Progression / résultats | — | rapport historique | 7.3 | validé, aucune écriture native |
| Groupe ILIAS complet | `grp` | aucun équivalent automatique validé | 7 | différé #22 |

## Dossiers et ordre

- niveau 1 → section ;
- niveau 2 → `mod_subsection` ;
- profondeur supérieure : politique d'aplatissement/synthèse contrôlée, jamais de nesting Moodle inventé ;
- activités Moodle non gérées par ILIAS2Moodle : préservées ;
- `mod_qbank` reste en section 0 lorsque Moodle l'exige ;
- Item Group racine → section ;
- Item Group sous un dossier de niveau 1 → `mod_subsection` ;
- les membres d'un Item Group sont déplacés, pas recréés.

## Ressources et liens internes

Les liens internes ILIAS ne sont réécrits que si une cible Moodle unique est résolue par mapping persistant. Sinon le lien source est conservé ou l'opération est bloquée selon la famille.

## Limites fonctionnelles explicites

### Exercice

La structure et les unités deviennent des `mod_assign`. Les remises, notes, feedbacks et appartenances d'équipe ne sont pas injectés automatiquement dans le chemin validé actuel.

### Test

Les questions, la banque et le Quiz sont migrables. Les **tentatives historiques** ne sont pas recréées lorsqu'ILIAS ne fournit pas assez de détail pour reproduire honnêtement les réponses et la sémantique Moodle.

### SCORM

Le package SCORM est migrable. Le suivi futur appartient à Moodle. Les tentatives historiques ILIAS ne sont pas reconstruites lorsque seul un état agrégé est disponible.

### Wiki

Les pages courantes, liens et assets sont migrables. Les métadonnées courantes d'auteur/date peuvent être réconciliées en Phase 7.6. L'historique complet des révisions reste `HISTORY_ONLY`.

### Groupe

`grp` n'est pas automatiquement converti en Moodle Group : un Groupe ILIAS peut être un véritable conteneur de dépôt avec contenus et restrictions. Le ticket #22 reste ouvert.

## Phase 7.3 — politique finale

Le POC avancé du 4 octobre 2026 a utilisé :

- ILIAS `obj_id=827 / ref_id=282` ;
- Moodle `course id=3 / ILIAS-282` ;
- 12 identités mappées, 0 anomalie ;
- 2 Tests, 1 SCORM 2004 et 1 Exercise.

Dry-run :

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

Le Test ref 357 fournit des résultats finaux fiables mais pas de tentative Quiz reconstructible. Le SCORM ref 347 fournit un état final agrégé et un compteur de tentatives, pas l'historique détaillé des tentatives. La décision est donc de conserver un **rapport historique** sans polluer les objets natifs Moodle.

Voir `docs/phase7-progress-results.md`.

## Console opérateur V1

La console `0.21.0-rc1` orchestre automatiquement les familles de contenus ci-dessus à partir d'un package préparé. L'isolation des erreurs est actuellement **par famille d'objets**, pas encore par instance individuelle au sein d'une même famille.

Voir `docs/operator-console.md`.

# Mapping ILIAS 10 → Moodle 4.5

Cette matrice constitue le contrat fonctionnel de la migration. Les mappings sont validés progressivement sur le cours POC réel ILIAS 10.8 → Moodle 5.0.2, tout en conservant une compatibilité minimale du plugin avec Moodle 4.5.

| Type ILIAS | Code indicatif ILIAS | Cible Moodle | Phase | Statut |
|---|---|---|---:|---|
| Catégorie | `cat` | Catégorie | 2 | validé |
| Cours | `crs` | Cours | 2 | validé |
| Dossier | `fold` | Section / Sous-section | 2 | validé |
| Fichier | `file` | `mod_resource` | 3 | validé |
| URL | `webr` | `mod_url` | 3 | validé |
| Module HTML | `htlm` | `mod_resource` | 3 | validé |
| SCORM | `sahs` | `mod_scorm` | 4 | validé |
| Learning Module ILIAS | `lm` | `mod_book` | 5 | validé |
| Test | `tst` | `mod_quiz` | 6 | validé |
| Question pool | `qpl` | Banque de questions / `qbank` | 6 | validé |
| Content Page | `copa` | `mod_page` | 6.5 | validé — #14 |
| Glossaire | `glo` | `mod_glossary` | 6.5 | validé — #15 |
| Wiki | `wiki` | `mod_wiki` | 6.5 | validé — #16 |
| Exercice | `exc` | `mod_assign` | 6.5 | validé — #17 |
| Forum | `frm` | `mod_forum` | 6.5 + 7 | validé structure + contributions/auteurs/assets — #18, #42 |
| Mediacast | `mcst` | `mod_data` | 6.5 | validé — #19 ; MP4 local + URL externe |
| Blog | `blog` | `mod_data` | 6.5 + 7 | validé — #20, #44 ; billets + images + auteurs |
| Media Pool / galerie média | `mep` | `mod_data` | 6.5 | validé — #21 ; images + MP4 + COPage + dossiers |
| Item Group | `itgr` | Section / `mod_subsection` + repositionnement | 6.5.9 | validé |
| Groupe | `grp` | Non migré automatiquement pour l’instant ; nécessite conteneur + membres + restrictions + contenus | 7 | différé — #22 |
| Learning Progress / résultats | — | Rapport historique uniquement | 7.3 | validé sur POC avancé : aucune écriture native Moodle |

\* Les codes encore marqués d’un astérisque restent indicatifs tant qu’ils n’ont pas été confirmés sur leur POC réel.

## Disponibilité dans la console opérateur beta1

| Famille | Console beta1 | Remarque |
|---|---|---|
| Structure | automatique | cours, sections, sous-sections |
| Fichier / URL / HTML | automatique | Phase 3 |
| SCORM | automatique | Phase 4 |
| Learning Module / Book | automatique | Phase 5 |
| Question Pool / Test | automatique | Phase 6 |
| Content Page | automatique | Phase 6.5 |
| Glossaire | automatique | famille transactionnelle |
| Wiki | automatique | famille transactionnelle |
| Exercice | automatique | structure Assign ; données utilisateur non incluses |
| Forum | automatique | conteneur ; contributions historiques en extension Phase 7 |
| MediaCast | automatique | mod_data |
| Blog | automatique | mod_data ; auteur historique Phase 7 séparé |
| Media Pool | automatique | mod_data |
| Item Group | automatique | après création des activités |
| Utilisateurs / inscriptions / rôles | assisté hors console beta1 | inventaire auxiliaire Phase 7 |
| Contributions Forum | assisté hors console beta1 | inventaire auxiliaire |
| Auteur/date Wiki | assisté hors console beta1 | inventaire auxiliaire |
| Progression / résultats | rapport historique | pas d'apply |
| Groupe ILIAS | non supporté | #22 |

Une famille absente du package est marquée `SKIPPED_NOT_APPLICABLE`.

Une famille en échec met le run en `WAITING_DECISION`; elle peut être réessayée ou explicitement ignorée avant de poursuivre les familles suivantes.

## Règle pour les dossiers

Un dossier ILIAS n’est pas systématiquement converti en ressource « Folder » Moodle.

- dossier structurant un cours → section ou sous-section ;
- dossier servant uniquement de dépôt de fichiers → ressource Folder possible ;
- cas ambigu → signalé dans le rapport.

## Liens internes

La Phase 3 valide désormais la réécriture des liens internes ILIAS lorsqu’une cible Moodle unique et sûre existe.

```text
ILIAS type|ref_id
      ↓
mapping persistant
      ↓
Moodle component + course_module id
```

Si aucune cible Moodle n’existe encore, ou si plusieurs cibles distinctes sont possibles, le lien permanent ILIAS est conservé comme fallback au lieu de produire une réécriture approximative.

## Phase 6.5 — extension des objets pédagogiques

La Phase 6.5 est suivie par l’issue #13 et par les tickets #14 à #21.

Ordre de développement retenu :

1. Content Page → `mod_page` ;
2. Glossaire → `mod_glossary` ;
3. Wiki → `mod_wiki` ;
4. Exercice → `mod_assign` ;
5. Forum → `mod_forum` ;
6. Mediacast → `mod_data` ;
7. Blog → `mod_data` ;
8. Media Pool / galerie média → `mod_data`.

Pour les objets dépendants des identités utilisateurs, la structure pédagogique peut être traitée en Phase 6.5, tandis que les auteurs, membres, remises, notes ou contributions sont reportés à la Phase 7 lorsqu’un rapprochement utilisateur fiable est nécessaire. C’est la politique validée pour le Forum : le `mod_forum` est créé/mis à jour en Phase 6.5.5, tandis que les threads, posts et assets de contributions restent conservés dans le package jusqu’au rapprochement des auteurs.

Pour le Mediacast, le mapping validé est `mcst` → `mod_data` : un `mod_data` par Mediacast et un record par entrée. Le périmètre POC validé couvre `video/mp4` local et les références externes HTTP/HTTPS. Les MP4 absents du ZIP natif peuvent être récupérés en lecture seule via MediaObjects/IRSS, puis contrôlés par taille et SHA-256 avant packaging. Le fichier est stocké via Moodle Files API et rendu dans un lecteur HTML5 ; les previews restent conservées dans le package sans être injectées dans Moodle.

Pour le Blog, le mapping validé est `blog` → `mod_data` : un `mod_data` par Blog et un record par billet. Le contenu riche est reconstruit depuis les COPage `blp:<posting_id>`, avec conservation des paragraphes, grilles et images locales validées sur le POC. Les images sont stockées via Moodle Files API dans la zone `mod_data/content`. L’identifiant auteur ILIAS est conservé dans le champ `source_author` ; l’attribution à un utilisateur Moodle est reportée à la Phase 7. Le second apply met à jour le même CMID et les mêmes records sans doublon.

Pour le Media Pool, le mapping validé est `mep` → `mod_data` : un `mod_data` par Media Pool et un record par nœud de contenu `mob` ou `pg`. Les nœuds `dummy` sont ignorés, les dossiers sont conservés sous forme de `folder_path`, les contenus COPage sont rendus dans le record, et les originaux image/MP4 sont stockés via Moodle Files API dans `mod_data/content`. Les previews `mob_vpreview.png` ne sont pas importées. Le POC réel valide PNG + MP4 ; l’audio reste non déclaré supporté faute de POC réel audio.

## Objet Groupe

Le type ILIAS Groupe est confirmé : `grp`.

La migration automatique de cet objet est **différée**. Un objet ILIAS Groupe est un conteneur de dépôt à part entière : il possède des membres et peut contenir des ressources/activités visibles dans son propre contexte. Un Moodle Group ne représente qu'un ensemble de participants et ne constitue donc pas un équivalent fonctionnel complet.

Le POC `obj_id=743 / ref_id=254` ne contient aucun objet enfant. Il a permis de valider techniquement la lecture des memberships, mais la création d'un simple Moodle Group n'est plus considérée comme une migration valide de l'objet ILIAS `grp`.

Politique retenue :

```text
ILIAS grp
   ↓
DEFERRED / HISTORY_ONLY
```

Aucun Moodle Group ne doit être créé automatiquement comme substitut de l'objet ILIAS Groupe. Une future prise en charge devra définir et valider une combinaison complète, par exemple section/sous-section + Groupe/Grouping + restrictions d'accès + migration des contenus enfants.


## Phase 7.3 — Learning Progress, résultats et tentatives

Deux POC ont été utilisés pour la Phase 7.3. Le POC initial `cours 504 / ref 128` a validé le pipeline read-only. Le POC avancé `obj_id=827 / ref_id=282 -> Moodle course id=3` a ensuite validé des états `completed`, `failed`, `in_progress`, des résultats Test et du tracking SCORM.

Résultat du dry-run consolidé :

```text
Identités source       = 6
Mappings utilisateurs  = 6
Cibles Moodle valides  = 6
Utilisateurs inscrits  = 6
Problèmes identité     = 0

MIGRATE                = 0
PARTIAL                = 0
HISTORY_ONLY           = 2
UNSUPPORTED            = 0
NO_DATA                = 21
```

Les deux entrées `HISTORY_ONLY` correspondent aux utilisateurs ILIAS `401/stagiaire.1` et `402/stagiaire.2`, dont le cours est en état `in_progress` avec le mode `LP_MODE_MANUAL_BY_TUTOR`. Cet état n'est pas converti en achèvement Moodle, car aucune équivalence sûre n'est validée.

Les données détaillées ont également été contrôlées :

- Test `713/ref 236` -> Moodle CMID 22 : aucune tentative ni score ;
- SCORM `719/ref 241` -> Moodle CMID 18 : aucun tracking, tentative, SCO ou score ;
- SCORM `720/ref 242` -> Moodle CMID 19 : aucun tracking, tentative, SCO ou score ;
- Exercice `806/ref 274` -> Moodle CMID 26 à 29 : aucune remise, note, marque, commentaire ou feedback utilisateur.

Les mappings d'objets supportent la différence historique entre `sourcecourse=obj_id 504` dans l'inventaire Phase 7.3 et `sourcecourse=ref_id 128` dans les mappings persistants. Un fallback n'est accepté que s'il est non ambigu.

L'Exercice utilise un mapping 1 -> N explicite :

```text
274:assignment:1 -> CMID 26
274:assignment:2 -> CMID 27
274:assignment:3 -> CMID 28
274:assignment:4 -> CMID 29
```

Le fallback par préfixe enfant est limité à l'Exercice, exige le même `sourceobj` et refuse les candidats répartis sur plusieurs couples `sourceinstance/sourcecourse`.

Politique validée :

```text
not_attempted                 -> NO_DATA
in_progress manuel du cours   -> HISTORY_ONLY
aucune tentative/résultat     -> NO_DATA
completed/failed futur        -> conversion seulement après validation sémantique
```

La décision finale est `PHASE73_HISTORICAL_REPORT_ONLY` : aucun `quiz_attempt`, `scorm_attempt`, gradebook ou état de completion n'est créé. Les résultats Test finaux fiables restent `HISTORY_ONLY` lorsque les tentatives ne sont pas reconstructibles ; le SCORM reste `PARTIAL` lorsque seul l'état final agrégé est disponible. Voir `docs/phase7-progress-results.md`.

## Phase 7.4 — Forum : auteurs et contributions

Le POC réel `Forum ILIAS obj_id=807/ref_id=275 -> Moodle CMID=30 / instance=2` est validé de bout en bout.

Résultat :

- 3 auteurs source résolus uniquement par mappings persistants : `6 -> Moodle 2`, `401 -> Moodle 6`, `402 -> Moodle 7` ;
- 2 discussions ;
- 7 posts ;
- arbre parent/enfant conservé ;
- dates source conservées ;
- 3 pièces jointes présentes dans `mod_forum/attachment` ;
- 9 mappings persistants de contribution : 2 `forumdiscussion` + 7 `forumpost` ;
- second dry-run : toutes les contributions sont `KEEP` et les 3 assets sont vérifiés ;
- second apply strictement idempotent : aucune création, aucun fichier, aucun mapping, `writes_performed=false`.

Le resolver expose `apply_implemented=true`, conformément à l'executor Phase 7.4 validé.

Le package natif peut contenir `source.instance=unknown-ilias-instance`. Cette valeur est traitée comme un placeholder ; l'instance canonique des mappings Phase 7 est alors résolue uniquement à partir d'un mapping utilisateur GLOBAL unique, sans fallback par login, email ou nom.

## Phase 7.5 — Blog : rattachement des auteurs

Le POC réel `Blog ILIAS obj_id=812/ref_id=277 -> Moodle mod_data CMID=32 / instance=2` ne nécessite aucune écriture supplémentaire.

Les deux billets exposent l'auteur source ILIAS `usr_id=6/root`, résolu vers l'unique mapping GLOBAL `ILIAS 6 -> Moodle 2/admin`.

Les deux records Moodle existants ont déjà `userid=2`. Le dry-run final retourne donc :

```text
owner_matches=2
owner_changes_required=0
apply_required=false
writes_performed=false
```

Aucun rapprochement par login, email ou nom n'est autorisé.

Le POC courant est un no-op ; un executor générique de réattribution restera nécessaire pour un futur cas où le resolver retournerait `REASSIGN_OWNER`.

## Phase 7.6 — Wiki : auteurs et métadonnées des pages courantes

Deux Wikis du POC ont été validés :

- `obj_id=801/ref_id=273 -> Moodle CMID=25 / instance=1 / subwiki=1` ;
- `obj_id=823/ref_id=279 -> Moodle CMID=34 / instance=2 / subwiki=2`.

Le créateur et dernier éditeur observés sur les pages source sont résolus via le mapping GLOBAL `ILIAS 6/root -> Moodle 2/admin`.

Politique appliquée :

- `wiki_pages.userid` <- dernier éditeur source mappé ;
- `wiki_pages.timecreated` <- date de création source ;
- `wiki_pages.timemodified` <- dernière modification source ;
- version Moodle courante : auteur/date source ;
- version 0 technique Moodle : inchangée ;
- contenu, liens et assets : inchangés ;
- identité du créateur distincte et historique complet des révisions ILIAS : `HISTORY_ONLY`.

Validation :

- Wiki 273 : premier apply réconcilie 3 pages, second apply `writes_performed=false` ;
- Wiki 279 : premier apply réconcilie 2 pages, second apply `writes_performed=false`.


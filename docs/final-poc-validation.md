# Validation finale du POC — 0.20.0-rc1

Date : 26 septembre 2026

## Périmètre

Cours source :
- ILIAS 10.8 ;
- cours `obj_id=504`, `ref_id=128` ;
- titre `cours test migration`.

Cible :
- Moodle 5.0.2 ;
- cours `id=5` ;
- shortname `ILIAS-128`.

Plugin :
- `local_iliasmigration` ;
- release candidate `0.20.0-rc1` ;
- build `2026092604` ;
- compatibilité minimale déclarée : Moodle 4.5.

## Validation du code

Validation réalisée sur la branche `final-poc-validation` :
- syntaxe PHP de l'ensemble du plugin : OK ;
- tests PHP de régression : OK ;
- Ruff : OK ;
- Pytest : 43 tests réussis.

## Installation Moodle

Avant synchronisation, le plugin installé était en `0.17.0-alpha / 2026092003`.

La version de référence `0.20.0-alpha / 2026092603` a été synchronisée vers `/var/www/moodle/local/iliasmigration` :
- sauvegarde préalable : `/tmp/local_iliasmigration_before_rc_20260926.tar.gz` ;
- SHA-256 : `3ee732697f259db356909562e0d4362c8bfd22b947797bd32e1f32bd17be432a` ;
- `diff -qr` après synchronisation : aucune différence ;
- upgrade Moodle : succès ;
- second upgrade : aucune mise à jour nécessaire.

Le build RC `2026092604` ne contient ensuite qu'une promotion de maturité/version et de la documentation ; aucun changement de schéma n'est associé à ce build.

## Audit du cours cible

État observé :
- 6 utilisateurs inscrits ;
- 3 rôles `student` ;
- 2 rôles `editingteacher` ;
- 1 rôle `teacher` ;
- 0 Moodle Group issu du faux mapping initial ;
- 67 mappings persistants associés à `sourcecourse=128` ;
- 7 mappings utilisateurs GLOBAL.

Tous les mappings observés sont au statut `READY`.

Le total de 32 `course_modules` inclut des objets Moodle locaux/préexistants et ne représente donc pas le nombre d'objets migrés.

Objets locaux explicitement qualifiés :
- Forum `Annonces`, CMID 13, type `news` ;
- Question Bank `bdq_moodle`, CMID 37 ;
- label `gfhfgh`, CMID 50 ;
- Glossaire `test`, CMID 54.

Ces objets n'ont pas été créés par la migration ILIAS.

## Forum

Forum ILIAS `ref_id=275 / obj_id=807` :
- Moodle CMID 59 / instance 6 ;
- type `general` ;
- 2 discussions ;
- 7 posts ;
- 3 auteurs source résolus ;
- 3 pièces jointes physiques :
  - `user et clef ssh.docx` ;
  - `all_corrections.pdf` ;
  - `handout.pdf`.

Dry-run final :
- 2 discussions `KEEP` ;
- 7 posts `KEEP` ;
- 3 assets vérifiés ;
- 0 asset manquant ;
- 0 item bloqué ;
- `writes_performed=false`.

## Blog

Blog ILIAS `ref_id=247 / obj_id=732` :
- Moodle `mod_data` CMID 61 / instance 3 ;
- 2 billets ;
- auteur source `il_0_usr_6` ;
- mapping `ILIAS 6 -> Moodle 2/admin` ;
- les deux records sont déjà propriétaires `userid=2` ;
- 2 images physiques : `tous.png`, `trio.png`.

Dry-run final :
- 2 propriétaires conformes ;
- 0 réattribution nécessaire ;
- 0 item bloqué ;
- `apply_required=false` ;
- `writes_performed=false`.

## Wiki

Wiki ILIAS `ref_id=273 / obj_id=801` :
- Moodle CMID 55 / instance 1 / subwiki 1 ;
- 3 pages ;
- auteur courant source : ILIAS user 6/root -> Moodle 2/admin ;
- dates de création et dernière modification source restaurées ;
- version courante Moodle 1 réconciliée ;
- version 0 technique Moodle conservée.

Dry-run final :
- 3 pages `KEEP` ;
- 0 métadonnée à réconcilier ;
- 0 problème d'historique technique ;
- historique complet ILIAS : `HISTORY_ONLY` ;
- `apply_required=false` ;
- `writes_performed=false`.

## Idempotence finale

Empreinte avant les dry-runs finaux :
- `course_modules=32` ;
- `mappings=80` ;
- `course128_mappings=67` ;
- `global_users=7` ;
- `enrolments=6` ;
- `forum_discussions=2` ;
- `forum_posts=7` ;
- `blog_records=2` ;
- `wiki_pages=3`.

Après les dry-runs Forum, Blog et Wiki, les valeurs sont strictement identiques.

Résultat :

```text
STATE_DIFF_RC=0
```

## Données volontairement non migrées

Deux extensions restent hors du périmètre de cette release candidate :
- #22 — objet ILIAS Groupe complet : `DEFERRED / HISTORY_ONLY` ;
- #41 — progression avancée multi-utilisateurs / multi-états.

La Phase 7.3 du POC courant a également conservé explicitement les cas sans équivalent sûr en `HISTORY_ONLY` ou `NO_DATA`, plutôt que de produire des données approximatives.

## Conclusion

Le périmètre POC couvert par les phases 1 à 7 est reproductible, audité et idempotent sur la cible de validation.

La release candidate `0.20.0-rc1` peut être utilisée pour figer ce périmètre avant toute extension fonctionnelle supplémentaire.

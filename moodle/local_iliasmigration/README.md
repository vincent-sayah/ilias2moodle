# local_iliasmigration

Plugin local Moodle utilisé par ILIAS2Moodle pour reconstruire dans Moodle les structures et ressources extraites d'ILIAS.

## Compatibilité

- Moodle 4.5 minimum ;
- POC de développement actuellement ciblé et validé sur Moodle 5.0.2 ;
- PHP 8.3 sur le POC Moodle.

## État actuel

Le plugin sait maintenant :

- valider `migration.json` ;
- vérifier la catégorie Moodle cible ;
- vérifier que `mod_subsection` est disponible et actif ;
- produire un plan `CREATE / UPDATE / CONFLICT / DEFER` sans écriture ;
- créer ou mettre à jour un cours Moodle masqué ;
- créer, renommer et repositionner les dossiers ILIAS de niveau 1 comme sections Moodle ;
- créer ou mettre à jour les dossiers ILIAS de niveau 2 comme activités `mod_subsection` ;
- conserver un mapping persistant ILIAS ↔ Moodle ;
- rejouer la migration sans créer de doublons ;
- refuser l'écriture si un dossier de profondeur supérieure à 2 exige un aplatissement ;
- valider et importer les ressources simples Phase 3 : URL, fichiers et modules HTML exportés ;
- conserver provisoirement les WebResource internes ILIAS sous forme de liens permanents vers l'instance source ;
- valider les packages SCORM Phase 4 sans les extraire pendant le dry-run ;
- créer ou mettre à jour les activités `mod_scorm`, stocker le ZIP par File API et laisser Moodle parser les SCO ;
- valider les Learning Modules natifs ILIAS et produire une prévisualisation Moodle Book ;
- créer un Moodle Book à partir du Learning Module via `create_module()` et le `booktool_importhtml` core ;
- importer les assets de chapitre via la File API `mod_book/chapter` ;
- rejouer un Book inchangé sans dupliquer ses chapitres ou fichiers ;
- valider en dry-run les questions normalisées Phase 6 et produire une prévisualisation Question Bank + Quiz ;
- vérifier les modules Moodle `qbank` / `quiz` et les qtypes core nécessaires avant toute écriture Phase 6.

Le cours reste masqué tant que les phases de migration suivantes n'ont pas été validées.

## Table de mapping

L'installation crée `local_iliasmigration_map`. Elle associe durablement les identifiants ILIAS aux objets Moodle pour rendre la migration rejouable et idempotente.

À partir des développements Phase 3, les nouveaux mappings incluent `sourceinstance` afin de distinguer plusieurs installations ILIAS. Les anciens mappings structurels sans instance restent pris en charge en compatibilité.

Pour une sous-section, `targetid` correspond à l'ID du `course_module` Moodle de type `subsection`. La section déléguée associée est résolue par les API Moodle lors de l'import des ressources qui y sont contenues.

## Lister les catégories

Depuis la racine Moodle :

```bash
php local/iliasmigration/cli/categories.php
```

## Phase 2 — dry-run

```bash
php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --phase=2 \
  --dry-run
```

## Phase 2 — écriture réelle de la structure

```bash
php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --phase=2 \
  --apply
```

L'écriture réelle Phase 2 prend en charge :

- cours ILIAS → cours Moodle masqué ;
- dossier ILIAS niveau 1 → section Moodle ;
- dossier ILIAS niveau 2 → `mod_subsection` Moodle.

Les dossiers ILIAS de niveau supérieur à 2 sont refusés en `--apply` jusqu'à définition de la politique d'aplatissement.

## Phase 3 — dry-run ressources simples

```bash
php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --phase=3 \
  --dry-run
```

Le dry-run ne modifie jamais les contenus Moodle. Il contrôle le package complet, les URL et les chemins avant l'apply.

## Phase 3 — écriture réelle

Précondition : la Phase 2 doit déjà être appliquée et le dry-run Phase 3 doit retourner `blocked_resources=0` et `ready=true`.

```bash
php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --phase=3 \
  --apply
```

La Phase 3 prend en charge :

- WebResource/URL ILIAS → `mod_url` Moodle ;
- fichier ILIAS → `mod_resource` Moodle ;
- module HTML exporté → `mod_resource` Moodle avec conservation des sous-répertoires et du fichier de démarrage ;
- ressource racine → section Moodle 0 ;
- ressource d'un dossier → section Moodle correspondante ;
- ressource d'une sous-section → section déléguée `mod_subsection`.

L'exécution est idempotente : premier passage `CREATED`, passages suivants `UPDATED` à partir du mapping persistant.

## Phase 4 — SCORM

Depuis `0.8.0-alpha`, la Phase 4 prend en charge le dry-run et l'apply réel :

```bash
php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --phase=4 \
  --dry-run

php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --phase=4 \
  --apply
```

Le dry-run Phase 4 :

- vérifie que `mod_scorm` existe et est actif ;
- retrouve un éventuel mapping SCORM pour produire `CREATE` ou `UPDATE` ;
- vérifie que les Phases 2/3 de ce même export sont déjà synchronisées ;
- valide `migration_package_path` ;
- ouvre le ZIP via l'API archive Moodle sans l'extraire ;
- bloque les chemins ZIP absolus/traversants et collisions de chemins ;
- exige `imsmanifest.xml` à la racine ;
- parse le manifeste XML sans accès réseau ;
- expose taille du ZIP, SHA-256, nombre d'entrées/fichiers, taille décompressée et ratio de compression ;
- détecte le standard SCORM 1.2/2004 à partir de `schemaversion`, y compris `CAM 1.3` ;
- avertit sur les gros packages et sur un manque potentiel d'espace dans `dataroot`.

L'apply Phase 4 :

- revalide les prérequis et le package juste avant écriture ;
- crée un draft File API du ZIP ;
- utilise `create_module()` / `update_module()` sur `mod_scorm` ;
- stocke le ZIP dans `mod_scorm/package` via les API Moodle ;
- laisse `scorm_parse()` extraire le contenu et construire les SCO ;
- exige au moins un SCO lançable ;
- conserve un mapping persistant `targettype=scorm` sur le CMID.

### Tracking SCORM Moodle 5.0

Les diagnostics Moodle 5.0 ne doivent pas utiliser l'ancienne table `scorm_scoes_track`. Le suivi est normalisé dans :

- `scorm_attempt` ;
- `scorm_element` ;
- `scorm_scoes_value`.

Le POC réel a validé le lancement, le suivi, les scores/statuts, les tentatives, un deuxième apply en `UPDATE`, le même CMID/instance, aucun doublon et aucune perte des 106 valeurs suivies.

## Phase 5 — Learning Module natif -> Moodle Book

Depuis `0.10.1-alpha`, la Phase 5 prend en charge le dry-run et le premier chemin d'apply réel. La version courante validée de la Phase 5 est `0.10.2-alpha`.

```bash
php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --phase=5 \
  --dry-run

php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --phase=5 \
  --apply
```

Le dry-run Phase 5 :

- vérifie que `mod_book` est installé et actif ;
- vérifie que le `booktool_importhtml` core est disponible ;
- résout le Learning Module en `CREATE` ou `UPDATE` avec `targettype=book` ;
- revalide les objets et packages Phase 2/3/4 du même export ;
- valide `learning_modules/<ref_id>/structure.json` ;
- contrôle l'identité source, l'arbre, les pages, les parents et l'ordre ;
- vérifie tous les médias/fichiers extraits et leurs références ;
- bloque tout composant `unsupported` ;
- produit `book_preview` avec la future table des matières Moodle Book.

Le POC ILIAS 10.8 `ref_id=243` contient 2 chapitres, 3 pages, 1 image JPEG et 2 PDF embarqués. Le dry-run réel Moodle 5.0.2 est entièrement prêt (`phase5_package.ready=true`).

L'apply Phase 5 :

- revalide les Phases 3/4/5 avant toute écriture ;
- crée `mod_book` avec `create_module()` ;
- rend les blocs `paragraph`, `media`, `file_list`, `table` et `section` en HTML ;
- construit un ZIP HTML déterministe ;
- délègue la création des chapitres au `booktool_importhtml` fourni par Moodle ;
- laisse Moodle gérer la File API `mod_book/chapter` et les événements Book ;
- vérifie l'ordre, les titres, les sous-chapitres et les assets ;
- enregistre le mapping `targettype=book` sur le CMID.

Le plugin n'effectue aucun INSERT/UPDATE direct sur `book_chapters`.

### Validation réelle Moodle 5.0.2

Le POC réel est validé de bout en bout.

Premier apply du Learning Module `ref_id=243` :

- `CREATED` ;
- CMID `24` ;
- instance Book `2` ;
- section Moodle `1` (`quizz`) ;
- 5 chapitres dont 3 sous-chapitres ;
- 3 fichiers File API ;
- `content_reimported=true`.

Fichiers vérifiés dans `mod_book/chapter` :

- `vince.jpg` — 186497 octets ;
- `devoirs CP MON Petit CéP.pdf` — 379705 octets ;
- `dossier-31316547.pdf` — 60152 octets.

Deuxième apply identique :

- `UPDATED` ;
- même CMID `24` ;
- même instance `2` ;
- même section `1` ;
- `content_reimported=false` ;
- toujours 5 chapitres ;
- toujours 3 fichiers File API ;
- aucun chapitre ni fichier dupliqué.

La validation visuelle dans Moodle est également acquise : navigation correcte, image affichée, deux PDF ouvrables, tableau et section `Remark` correctement rendus.

### Idempotence Book actuelle

Une empreinte SHA-256 du contenu neutre et des fichiers binaires est intégrée aux chemins d'import déterministes. Un second apply identique retrouve donc le même Book et n'ajoute aucun contenu.

Si le contenu pédagogique source a changé, `0.10.2-alpha` refuse l'UPDATE des chapitres afin d'éviter toute duplication ou écriture directe dans les tables Book. Le remplacement sûr d'un Book déjà migré reste à implémenter.

Les `internal_link` sont également refusés en apply tant que leur cible n'est pas normalisée et testée. Le POC actuel n'en contient aucun.

Voir `docs/phase5-learning-modules.md`.

## Phase 6 — Questions et Quiz

Depuis `0.11.0-alpha`, la Phase 6 possède un dry-run Moodle. L'apply reste volontairement désactivé.

```bash
php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --phase=6 \
  --dry-run
```

Le dry-run Phase 6 :

- revalide les packages Phases 3/4/5 et exige que les objets Phases 2 à 5 soient synchronisés ;
- vérifie `mod_qbank`, `mod_quiz`, le helper Question Bank Moodle 5.0, `mod/quiz/locallib.php` et le format XML core ;
- vérifie les qtypes `multichoice`, `numerical`, `match`, `essay`, `shortanswer`, `multianswer` et `ordering` ;
- valide `tests/<ref_id>/questions.json` et `quiz.json` sans écriture ;
- vérifie identité source, schéma, nombres de questions, identifiants uniques, types, ordre, QRef et barème total ;
- produit `quiz_preview` avec le futur ordre et le qtype Moodle de chaque question ;
- planifie `question_pool -> mod_qbank` et `test -> mod_quiz` ;
- traite un pool sans QTI exporté comme conteneur vide au lieu d'inventer des questions ;
- place les questions réellement exportées par le test dans la banque privée du futur Quiz ;
- signale les différences de sémantique de notation qui exigent une décision avant l'apply.

Le POC v5 validé côté ILIAS contient 11 questions, 8 types, aucun QRef absent et un total de `46.0` points.

Deux points restent à arbitrer avant l'écriture réelle :

- Matching : ILIAS utilise des poids de paires `4/2/5` ;
- Ordering : Moodle propose plusieurs stratégies de notation et celle correspondant au scoring ILIAS par position doit être sélectionnée.

`--phase=6 --apply` est refusé tant que ces mappings et l'écriture via les API/outils core Question Bank + Quiz ne sont pas implémentés et validés.

Voir `docs/phase6-questions.md`.

## Principes d'écriture

Les écritures de contenu passent par les API Moodle du module cible, les outils core du module et la File API. Le plugin n'écrit pas directement les contenus pédagogiques dans les tables cœur Moodle en contournant les API/outils du module.

Phases POC validées :

- Phase 2 : structure ;
- Phase 3 : ressources simples ;
- Phase 4 : SCORM ;
- Phase 5 : Learning Module natif → Moodle Book ;
- Phase 6 : normalisation ILIAS et dry-run Moodle Question Bank + Quiz implémentés, validation Moodle réelle à exécuter.

Restent notamment à réaliser :

- Phase 6 : choix final des mappings de notation puis apply Question Bank + Quiz ;
- remplacement sûr d'un Book dont le contenu source a changé ;
- réécriture/validation des liens internes de Learning Module ;
- politique globale d'ordre des objets racine et de flattening des profondeurs non représentables.

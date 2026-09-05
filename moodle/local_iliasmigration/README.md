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
- valider en dry-run les Learning Modules natifs ILIAS et produire une prévisualisation Moodle Book ;
- laisser l'apply Moodle Book, les tests et banques de questions pour les étapes suivantes.

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
- détecte le standard SCORM à partir de `schemaversion` lorsque celui-ci est explicite ;
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

Depuis `0.9.0-alpha`, la Phase 5 est disponible en **dry-run uniquement** :

```bash
php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --phase=5 \
  --dry-run
```

Le dry-run Phase 5 :

- vérifie que `mod_book` est installé et actif ;
- résout le Learning Module en `CREATE` ou `UPDATE` avec `targettype=book` ;
- revalide les objets et packages Phase 2/3/4 du même export ;
- valide `learning_modules/<ref_id>/structure.json` ;
- contrôle l'identité source, l'arbre, les pages, les parents et l'ordre ;
- vérifie tous les médias/fichiers extraits et leurs références ;
- bloque tout composant `unsupported` ;
- produit `book_preview` avec la future table des matières Moodle Book.

Pour le POC ILIAS 10.8 `ref_id=243`, la représentation validée contient 2 chapitres, 3 pages, 1 image JPEG et 2 PDF embarqués. La politique de prévisualisation crée un marqueur Book pour chaque chapitre puis place les pages ILIAS en sous-chapitres.

`--phase=5 --apply` est volontairement refusé dans `0.9.0-alpha`. Le rendu HTML, la File API `mod_book/chapter`, la réécriture des liens internes et l'idempotence Book doivent être validés avant activation de l'écriture réelle.

Voir `docs/phase5-learning-modules.md`.

## Principes d'écriture

Les écritures de contenu passent par les API Moodle du module cible et la File API. Le plugin n'écrit pas directement les contenus pédagogiques dans les tables cœur Moodle en contournant les API de module.

Restent à réaliser :

- rendu HTML + apply Moodle Book → Phase 5 ;
- test et banque de questions → Phase 6.

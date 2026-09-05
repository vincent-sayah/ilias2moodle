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
- laisser les modules d'apprentissage natifs, tests et banques de questions pour les phases suivantes.

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

Les rapports Moodle eux-mêmes joignent ces tables pour retrouver utilisateur, numéro de tentative, SCO, élément CMI et valeur.

Restent à réaliser :

- validation finale de l'idempotence SCORM et conservation des traces → Phase 4 ;
- module d'apprentissage natif → Phase 5 ;
- test et banque de questions → Phase 6.

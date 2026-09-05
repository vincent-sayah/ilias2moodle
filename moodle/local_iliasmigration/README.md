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

Pour un SCORM, `targetid` correspond à l'ID du `course_module` Moodle de type `scorm`.

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

Le dry-run Phase 4 :

```bash
php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --phase=4 \
  --dry-run
```

Il :

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

À partir de `0.8.0-alpha`, l'apply SCORM est disponible lorsque `phase4_package.ready=true` :

```bash
php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --phase=4 \
  --apply
```

L'apply :

- refait les validations Phase 3/4 juste avant toute écriture ;
- crée un draft utilisateur contenant le ZIP ;
- crée/met à jour `mod_scorm` via `create_module()` / `update_module()` ;
- laisse `scorm_add_instance()` / `scorm_update_instance()` stocker le package dans `mod_scorm/package` ;
- laisse `scorm_parse()` extraire le contenu dans `mod_scorm/content` et construire les SCO ;
- vérifie après écriture le package, `imsmanifest.xml` et au moins un SCO lançable ;
- mappe `tries` ILIAS vers `maxattempt` Moodle quand la valeur est compatible (1 à 6) ;
- conserve largeur/hauteur exportées ;
- écrit le mapping `targettype=scorm` pour permettre le second passage `UPDATED` sans doublon.

Le POC réel doit encore valider en exploitation : lancement du package, suivi SCORM/attempts et idempotence complète du second apply.

Restent à réaliser :

- validation fonctionnelle finale SCORM → Phase 4 ;
- module d'apprentissage natif → Phase 5 ;
- test et banque de questions → Phase 6.

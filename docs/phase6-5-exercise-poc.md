# Phase 6.5.4 — Exercice ILIAS vers Moodle Assignment

## Contrat source préparatoire

Le type ILIAS est `exc`. L'export natif ILIAS 10 utilise le composant
`components/ILIAS/Exercise/set_0/export.xml` avec un DataSet comprenant
notamment :

- `exc` : paramètres globaux de l'Exercise ;
- `exc_assignment` : unités de travail ;
- `exc_crit_cat` / `exc_crit` : critères de peer review ;
- `exc_ass_file_order` : ordre des fichiers d'instruction ;
- `exc_ass_reminders` : rappels.

Depuis le schéma 9.0, les fichiers d'instruction sont exportés par
`InstructionCollection` de type `rscollection`, dans un répertoire
`components/ILIAS/Exercise/.../dsDir_N`.

## Types d'unité identifiés dans ILIAS

- 1 : dépôt de fichier individuel ;
- 2 : blog ;
- 3 : portfolio ;
- 4 : dépôt de fichier en équipe ;
- 5 : texte en ligne ;
- 6 : wiki d'équipe.

La première cible automatique est :

- type 1 -> `mod_assign` + `assignsubmission_file` ;
- type 5 -> `mod_assign` + `assignsubmission_onlinetext`.

Le type 4 est structurellement compatible avec `mod_assign` en mode équipe,
mais la constitution réelle des groupes/membres est reportée à la Phase 7.

Les types Blog, Portfolio et Wiki d'équipe ne doivent pas être convertis
silencieusement en Assignment standard.

## Stratégie multi-unités

Un objet Exercise ILIAS peut contenir plusieurs `exc_assignment`.
La stratégie validée crée un `mod_assign` Moodle par unité, dans la section
Moodle correspondant au parent de l’Exercise ILIAS.

Le POC réel n’a pas retenu la création d’une sous-section supplémentaire :
la structure reste plus simple et l’idempotence repose sur un mapping persistant
par unité sous la forme `<ref_id_exercise>:assignment:<id_unite>`.

## Données utilisateurs

La Phase 6.5.4 ne migre pas :

- remises utilisateurs ;
- notes ;
- feedback tuteur lié à un utilisateur ;
- peer feedback utilisateur ;
- appartenance des équipes.

Ces données restent explicitement reportées à la Phase 7.

## Garde-fous

Le POC réel ILIAS 10.8 / Moodle 5.0.2 a confirmé les noms/valeurs XML utiles,
les échéances, les types de remise retenus et la stratégie multi-unités.
L’apply Moodle est activé uniquement lorsque le validateur confirme :

- un Exercise reconnu et un parent Moodle valide ;
- aucune unité bloquée ;
- la présence des plugins de remise requis ;
- des fichiers d’instruction présents et validés ;
- une action unitaire `CREATE` ou `UPDATE` sûre ;
- le report explicite des appartenances aux équipes en Phase 7.

L’exécuteur applique l’ensemble dans une transaction Moodle et conserve un
mapping persistant pour empêcher la création de doublons lors des rejouages.


## Anomalie apparente du chemin d’export sur ILIAS 10.8

Le POC réel sur ILIAS 10.8 a mis en évidence un comportement d’export qui laisse les fichiers d’instruction des Exercise hors du ZIP natif dans le cas observé.

`ilExerciseExporter::getValidSchemaVersions()` borne le schéma Exercise `9.0` à `max = 9.99`, alors que `ilXmlExporter::determineSchemaVersion()` compare les contraintes avec `ILIAS_VERSION_NUMERIC` (10.8). L’export sélectionne donc le schéma `5.3.0`.

Conséquence observée :

- `getXmlRecord()` ajoute encore `InstructionCollection` sous forme d’UUID de collection Resource Storage ;
- le schéma 5.3.0 ne déclare pas ce champ comme `rscollection` ;
- `ilDataSet::addRecordsXml()` ne remplace donc pas l’UUID par `dsDir_N` ;
- `writeFilesByResourceCollection()` n’est jamais appelé ;
- les fichiers existent dans le Resource Storage ILIAS mais sont absents du ZIP.

Ilias2Moodle détecte désormais ce cas par `instruction_collection_kind = resource_collection_uuid` et ajoute la contrainte `instruction_collection_not_embedded`. Aucun apply ne doit être autorisé tant que la collection n’a pas été résolue par une extraction read-only depuis l’instance ILIAS source.

## Remédiation read-only par IRSS

Lorsque `InstructionCollection` contient un UUID de Resource Storage au lieu
d'un chemin `dsDir_N`, le ZIP natif ILIAS est considéré comme incomplet pour
les fichiers d'instruction de l'unité.

Ilias2Moodle applique alors la stratégie suivante :

1. le parser conserve l'UUID et signale
   `instruction_collection_not_embedded` ;
2. aucune migration automatique de l'unité n'est autorisée à ce stade ;
3. `tools/ilias_irss_extract.php` est exécuté sur l'instance ILIAS source ;
4. l'outil utilise exclusivement l'API officielle ILIAS Resource Storage
   Service pour lire la collection ;
5. chaque fichier récupéré est accompagné de ses métadonnées, de sa taille
   et de son SHA-256 dans `manifest.json` ;
6. `prepare-export --exercise-irss-recovery` vérifie le manifest, la taille
   et le SHA-256 avant d'intégrer les ressources au package ;
7. la contrainte `instruction_collection_not_embedded` n'est supprimée que
   lorsque toute la collection a été validée.

Aucune écriture n'est effectuée dans la base de données, le Resource Storage
ou le File Storage ILIAS.

### Extraction sur le serveur ILIAS

Exemple de commande :

    php tools/ilias_irss_extract.php \
      --collection=91b53716-8ec4-45ad-ada7-0f714c76901f \
      --ilias-root=/var/www/ilias \
      --client=ilias10 \
      --output=/tmp/ilias2moodle-irss

Les paramètres peuvent aussi être fournis avec les variables d'environnement :

- `ILIAS_ROOT`
- `ILIAS_CLIENT_ID`
- `ILIAS_IRSS_OUTPUT`

La priorité de configuration est :

    option CLI > variable d'environnement > valeur par défaut

L'ancienne syntaxe avec l'UUID comme argument positionnel reste compatible.

### Préparation du package Ilias2Moodle

Après transfert des collections IRSS vers le serveur Ilias2Moodle :

    ./tools/run-ilias2moodle.sh prepare-export \
      --zip=/chemin/export-ilias.zip \
      --output=/chemin/package \
      --ilias-version=10.8 \
      --exercise-irss-recovery=/chemin/irss_recovery

Pour chaque fichier récupéré, le package conserve notamment :

- l'UUID de collection ;
- l'identifiant de ressource IRSS ;
- le nom d'origine ;
- le type MIME ;
- la taille ;
- le SHA-256 ;
- la provenance `ilias_irss` ;
- le chemin final dans le package.

Un manifest absent ou invalide, un `resource_count` incohérent, un fichier
absent, un lien symbolique, une taille incohérente ou un SHA-256 incorrect
laisse l'unité bloquée. Aucun fichier IRSS non vérifié n'est accepté
silencieusement.

### Validation du POC réel

Le POC réalisé sur ILIAS 10.8 avec l'Exercise `806` a validé trois collections
IRSS contenant quatre fichiers d'instruction.

Après récupération :

- `tache1` : 2 fichiers récupérés, unité automatiquement prête ;
- `tache2` : 1 fichier récupéré, unité automatiquement prête ;
- `Dépôt équipe` : 1 fichier récupéré, avec conservation de la dépendance
  `team_membership_phase7_dependency`.

Le package final contient les quatre fichiers et ne signale aucune ressource
manquante.


## Apply Moodle validé

Le POC final a été exécuté sur le cours Moodle `id=5` à partir de l’Exercise
ILIAS `ref_id=274`, `obj_id=806`, intitulé `test migration exercice`.

Le dry-run final a donné :

- `ready = true` ;
- `apply_implemented = true` ;
- `apply_ready = true` ;
- 3 unités ;
- 3 créations prévues ;
- 0 unité bloquée ;
- 1 dépendance Phase 7 ;
- 4 fichiers d’instruction.

Le premier apply a créé :

- `tache1` → `mod_assign`, CMID 56, remise fichier, 2 fichiers d’instruction ;
- `tache2` → `mod_assign`, CMID 57, remise texte en ligne, 1 fichier d’instruction ;
- `Dépôt équipe` → `mod_assign`, CMID 58, remise fichier en équipe, 1 fichier d’instruction.

Les quatre fichiers récupérés via IRSS ont été retrouvés dans le File API
Moodle avec les tailles attendues.

Un second apply sur le même package a produit uniquement des `UPDATE` sur les
mêmes CMID 56, 57 et 58. Aucun doublon n’a été créé.

Les mappings persistants validés sont :

```text
274:assignment:1 -> assign CMID=56 STATUS=READY
274:assignment:2 -> assign CMID=57 STATUS=READY
274:assignment:3 -> assign CMID=58 STATUS=READY
```

La migration structurelle de l’objet Exercise est donc considérée comme
validée pour la Phase 6.5.4. Les remises utilisateurs, notes, feedbacks et
appartenances effectives aux équipes restent hors périmètre et sont reportés à
la Phase 7.

### Commandes Moodle

Dry-run :

```bash
php local/iliasmigration/cli/exercise_dry_run.php \
  --source=/path/to/migration.json \
  --category=ID
```

Apply :

```bash
php local/iliasmigration/cli/exercise_apply.php \
  --source=/path/to/migration.json \
  --category=ID
```

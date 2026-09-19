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
Le modèle neutre prévoit donc un `mod_assign` Moodle par unité.

Le regroupement dans une sous-section Moodle dédiée est un candidat de
conservation de structure et sera confirmé par le POC réel avant apply.

## Données utilisateurs

La Phase 6.5.4 ne migre pas :

- remises utilisateurs ;
- notes ;
- feedback tuteur lié à un utilisateur ;
- peer feedback utilisateur ;
- appartenance des équipes.

Ces données restent explicitement reportées à la Phase 7.

## Garde-fous

L'apply Moodle reste désactivé tant que le POC réel ILIAS 10.8 n'a pas confirmé :

- les noms/valeurs XML réels ;
- la sérialisation des fichiers d'instruction ;
- les échéances ;
- les types de remise ;
- la stratégie multi-unités.


## Anomalie confirmée sur ILIAS 10.8

Le POC réel sur ILIAS 10.8 a mis en évidence un défaut d’export des fichiers d’instruction des Exercise.

`ilExerciseExporter::getValidSchemaVersions()` borne le schéma Exercise `9.0` à `max = 9.99`, alors que `ilXmlExporter::determineSchemaVersion()` compare les contraintes avec `ILIAS_VERSION_NUMERIC` (10.8). L’export sélectionne donc le schéma `5.3.0`.

Conséquence observée :

- `getXmlRecord()` ajoute encore `InstructionCollection` sous forme d’UUID de collection Resource Storage ;
- le schéma 5.3.0 ne déclare pas ce champ comme `rscollection` ;
- `ilDataSet::addRecordsXml()` ne remplace donc pas l’UUID par `dsDir_N` ;
- `writeFilesByResourceCollection()` n’est jamais appelé ;
- les fichiers existent dans le Resource Storage ILIAS mais sont absents du ZIP.

Ilias2Moodle détecte désormais ce cas par `instruction_collection_kind = resource_collection_uuid` et ajoute la contrainte `instruction_collection_not_embedded`. Aucun apply ne doit être autorisé tant que la collection n’a pas été résolue par une extraction read-only depuis l’instance ILIAS source.

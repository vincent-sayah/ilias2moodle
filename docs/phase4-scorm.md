# Phase 4 — SCORM Moodle

## Objectif

Migrer les objets SCORM ILIAS vers `mod_scorm` Moodle en conservant le package, le placement et les paramètres compatibles, avec mapping et idempotence.

## POC source ILIAS 10.8

Export v3 du cours `ref_id=128`, `obj_id=504` :

- archive ILIAS : `1788603269__0__crs_504.zip` ;
- 11 objets ;
- 1 objet `scorm` ;
- `ref_id=241`, `obj_id=719` ;
- titre : `Animation de séance` ;
- package normalisé : `scorm/241/content.zip` ;
- taille observée côté source : `340014061` octets ;
- `missing_count=0` ;
- paramètres ILIAS exportés : `tries=3`, `width=950`, `height=650`.

Le même export contient également un deuxième `html_module` par rapport au POC v2. La Phase 4 vérifie donc les prérequis Phase 3 sur le package v3 au lieu de supposer que l'état Moodle est déjà synchronisé.

## Version plugin

`0.7.0-alpha` introduit le dry-run Phase 4 uniquement.

```bash
php local/iliasmigration/cli/import.php \
  --source=/opt/ilias2moodle-data/course-128-v3/migration.json \
  --category=1 \
  --phase=4 \
  --dry-run
```

## Contrôles réalisés

Le dry-run Phase 4 :

1. construit le plan Phase 3 correspondant au même export ;
2. signale toute structure ou ressource simple encore en `CREATE`, `BLOCKED`, `CONFLICT` ou mapping périmé ;
3. vérifie que `mod_scorm` est installé et actif ;
4. résout `CREATE` / `UPDATE` pour le mapping SCORM ;
5. valide `migration_package_path` et maintient le chemin dans le package normalisé ;
6. ouvre le ZIP via l'API archive Moodle sans extraction ;
7. bloque les chemins absolus, traversants et collisions après normalisation ;
8. exige `imsmanifest.xml` à la racine ;
9. parse le manifeste avec `LIBXML_NONET` ;
10. relève `schema`, `schemaversion`, nombre d'organisations et ressources ;
11. calcule le SHA-256 ;
12. relève taille compressée, taille décompressée et ratio de compression ;
13. avertit pour un package >= 250 MiB ;
14. avertit si l'espace libre `dataroot` paraît inférieur au ZIP + sa taille décompressée.

## Sorties attendues

Pour le SCORM du POC avant création Moodle :

```text
kind = scorm
action = CREATE
source_ref_id = 241
source_obj_id = 719
moodle_module = scorm
migration_package_path = scorm/241/content.zip
```

Le rapport ajoute :

```json
"phase4_package": {
  "checked_scorm_packages": 1,
  "blocked_scorm_packages": 0,
  "archive_checks_ready": true,
  "prerequisites_ready": true,
  "ready": true
}
```

`prerequisites_ready` peut être `false` si le nouvel export contient une ressource Phase 2/3 qui n'est pas encore synchronisée dans Moodle. Dans ce cas, il faut appliquer les phases antérieures sur ce même package avant de continuer.

## Limitation d'ordre

Comme les ressources racine, un SCORM racine situé après un dossier ILIAS ne peut pas être intercalé exactement avec le modèle actuel : les activités racine sont placées dans la section Moodle 0, avant les sections régulières.

Le dry-run émet donc `ROOT_SCORM_ORDER_APPROXIMATION` lorsqu'un tel cas est détecté.

## Apply

`--phase=4 --apply` reste désactivé dans `0.7.0-alpha`.

Son activation nécessitera la validation du POC suivant :

- création `mod_scorm` via les API Moodle ;
- stockage du ZIP par File API dans `mod_scorm/package` ;
- parsing Moodle du manifeste ;
- lancement du SCORM ;
- contrôle des SCO créés ;
- contrôle du suivi/attempt ;
- deuxième apply en `UPDATED` avec le même CMID ;
- aucun doublon.

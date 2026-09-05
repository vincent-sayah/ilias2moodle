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

Le même export contient également un deuxième `html_module` par rapport au POC v2. Il a été synchronisé en Phase 3 (`ref_id=240`, CMID Moodle `20`) avant activation de l'apply SCORM.

## Versions plugin

`0.7.0-alpha` introduit le dry-run Phase 4.

`0.7.1-alpha` corrige un faux positif du contrôle de chemins ZIP observé avec le package POC : la vérification native `ZipArchive` a confirmé 302 entrées et 0 chemin absolu/traversant/NUL, tandis que la couche `zip_archive` Moodle pouvait exposer une entrée de répertoire racine neutre (`.` / `./`). Ces marqueurs de répertoire sont ignorés explicitement, sans assouplir le blocage de `..`, `/...`, chemins Windows absolus ou octets nuls.

`0.8.0-alpha` active l'apply Phase 4 réel via les API Moodle.

## Dry-run validé sur le POC

```bash
php local/iliasmigration/cli/import.php \
  --source=/opt/ilias2moodle-data/course-128-v3/migration.json \
  --category=1 \
  --phase=4 \
  --dry-run
```

Résultat validé :

- `phase4_prerequisites.pending_count=0` ;
- `phase4_prerequisites.ready=true` ;
- `phase4_package.checked_scorm_packages=1` ;
- `phase4_package.blocked_scorm_packages=0` ;
- `archive_checks_ready=true` ;
- `prerequisites_ready=true` ;
- `ready=true` ;
- standard détecté : `SCORM_1_2` ;
- `entry_count=302` ;
- `file_count=293` ;
- `uncompressed_size=361643882` ;
- `compression_ratio=1.06` ;
- manifeste : 1 organisation, 1 ressource.

## Contrôles dry-run

Le dry-run Phase 4 :

1. construit le plan Phase 3 correspondant au même export ;
2. signale toute structure ou ressource simple encore en `CREATE`, `BLOCKED`, `CONFLICT` ou mapping périmé ;
3. vérifie que `mod_scorm` est installé et actif ;
4. résout `CREATE` / `UPDATE` pour le mapping SCORM ;
5. valide `migration_package_path` ;
6. ouvre le ZIP via l'API archive Moodle sans extraction ;
7. bloque les chemins absolus, traversants et collisions après normalisation ;
8. exige `imsmanifest.xml` à la racine ;
9. parse le manifeste avec `LIBXML_NONET` ;
10. relève `schema`, `schemaversion`, nombre d'organisations et ressources ;
11. calcule le SHA-256 ;
12. relève taille compressée, taille décompressée et ratio de compression ;
13. avertit pour un package >= 250 MiB ;
14. avertit si l'espace libre `dataroot` paraît inférieur au ZIP + sa taille décompressée.

## Apply Phase 4

Précondition : le dry-run du même package doit retourner `phase4_package.ready=true`.

```bash
php local/iliasmigration/cli/import.php \
  --source=/opt/ilias2moodle-data/course-128-v3/migration.json \
  --category=1 \
  --phase=4 \
  --apply
```

L'exécuteur :

1. reconstruit et revalide le plan Phase 4 immédiatement avant l'écriture ;
2. refuse l'apply si une Phase 2/3 n'est plus synchronisée ;
3. reconfirme le chemin package dans le répertoire normalisé ;
4. crée un draft utilisateur contenant le ZIP ;
5. appelle `create_module()` ou `update_module()` pour `mod_scorm` ;
6. laisse `scorm_add_instance()` / `scorm_update_instance()` déplacer le ZIP vers `mod_scorm/package` ;
7. laisse `scorm_parse()` extraire le package dans `mod_scorm/content` et construire `scorm_scoes` ;
8. vérifie le package stocké, sa taille, `imsmanifest.xml`, le nombre de fichiers de contenu et la présence d'au moins un SCO lançable ;
9. enregistre le mapping `targettype=scorm`, `targetid=<CMID>` ;
10. retourne dans le rapport le CMID, l'instance SCORM, la version détectée par Moodle, la révision, les SCO et les paramètres migrés.

### Paramètres ILIAS compatibles

- `tries` de 1 à 6 → `maxattempt` Moodle identique ;
- `tries > 6` → approximation documentée en tentatives illimitées (`SCORM_TRIES_APPROXIMATED`) ;
- `width` → largeur Moodle ;
- `height` → hauteur Moodle ;
- les autres réglages SCORM utilisent les valeurs de configuration `mod_scorm` du Moodle cible faute de sémantique ILIAS équivalente exportée.

Pour le POC courant :

- `tries=3` → `maxattempt=3` ;
- `width=950` ;
- `height=650`.

## Limitation d'ordre

Comme les ressources racine, un SCORM racine situé après un dossier ILIAS ne peut pas être intercalé exactement avec le modèle actuel : les activités racine sont placées dans la section Moodle 0, avant les sections régulières.

Le rapport émet donc `ROOT_SCORM_ORDER_APPROXIMATION` pour `ref_id=241`.

## Critères de validation finale Phase 4

Le POC doit maintenant confirmer :

- premier apply en `CREATED` ;
- ZIP présent dans `mod_scorm/package` ;
- contenu extrait dans `mod_scorm/content` ;
- version Moodle différente de `ERROR` ;
- au moins un SCO lançable ;
- lancement réel dans l'interface Moodle ;
- suivi / attempts ;
- second apply en `UPDATED` avec le même CMID ;
- aucun doublon.

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

`0.7.1-alpha` corrige un faux positif du contrôle de chemins ZIP observé avec le package POC : la vérification native `ZipArchive` a confirmé 302 entrées et 0 chemin absolu/traversant/NUL, tandis que la couche `zip_archive` Moodle pouvait exposer une entrée de répertoire racine neutre (`.` / `./`). Ces marqueurs de répertoire sont désormais ignorés explicitement, sans assouplir le blocage de `..`, `/...`, chemins Windows absolus ou octets nuls. Les erreurs de chemin incluent maintenant le chemin fautif et sa représentation hexadécimale.

`0.8.0-alpha` active l'apply réel SCORM : draft File API, `create_module()` / `update_module()`, stockage `mod_scorm/package`, parsing Moodle, extraction `mod_scorm/content`, contrôle des SCO et mapping idempotent.

`0.8.1-alpha` documente le schéma de tracking Moodle 5.0 utilisé pour les contrôles d'idempotence.

```bash
php local/iliasmigration/cli/import.php \
  --source=/opt/ilias2moodle-data/course-128-v3/migration.json \
  --category=1 \
  --phase=4 \
  --dry-run

php local/iliasmigration/cli/import.php \
  --source=/opt/ilias2moodle-data/course-128-v3/migration.json \
  --category=1 \
  --phase=4 \
  --apply
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

## Validation réelle Moodle 5.0.2

Premier apply réussi sur le POC :

- CMID Moodle : `21` ;
- instance `scorm` : `2` ;
- section Moodle : `0` ;
- package File API : `content.zip` ;
- taille stockée : `340014061` octets ;
- contenthash Moodle : `76669871d7102b4e73826c2b8daabdcef8c61a41` ;
- fichiers extraits dans `mod_scorm/content` : `293` ;
- version parsée : `SCORM_1.2` ;
- révision : `1` ;
- SCO créés : `2` ;
- SCO lançables : `1` ;
- SCO lançable : `Salle1_2_3_SCO`, `launch=index_lms.html` ;
- `tries=3` → Moodle `maxattempt=3` ;
- largeur `950`, hauteur `650`.

Les logs Moodle confirment plusieurs événements `sco_launched`, `scoreraw_submitted` et au moins un `status_submitted` pour le SCO `4`.

### Tracking Moodle 5.0

Important : Moodle 5.0 n'utilise plus la table historique `scorm_scoes_track` pour le suivi SCORM. Les données sont normalisées dans :

- `scorm_attempt` : utilisateur, SCORM, numéro de tentative ;
- `scorm_element` : nom de l'élément CMI (`cmi.core.lesson_status`, `cmi.core.score.raw`, etc.) ;
- `scorm_scoes_value` : valeur, SCO, tentative et date de modification.

Les requêtes de diagnostic doivent donc joindre ces trois tables.

## Limitation d'ordre

Comme les ressources racine, un SCORM racine situé après un dossier ILIAS ne peut pas être intercalé exactement avec le modèle actuel : les activités racine sont placées dans la section Moodle 0, avant les sections régulières.

Le rapport émet donc `ROOT_SCORM_ORDER_APPROXIMATION` lorsqu'un tel cas est détecté.

## Restent à valider

- lecture directe des valeurs de suivi dans les nouvelles tables Moodle 5.0 ;
- deuxième apply en `UPDATED` avec le même CMID ;
- vérification qu'aucune tentative ni valeur de suivi existante n'est perdue ;
- aucun doublon ;
- politique d'ordre racine.

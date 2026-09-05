# Phase 3 — Ressources simples Moodle

## Objectif

Importer dans Moodle 5.0.2 les ressources simples issues du package normalisé ILIAS2Moodle, sans toucher aux objets complexes des phases suivantes.

Types Phase 3 :

- `url` → activité Moodle `mod_url` ;
- `file` → ressource Moodle `mod_resource` ;
- `html_module` → ressource Moodle `mod_resource` contenant le paquet HTML et son fichier de démarrage.

Les types suivants restent différés :

- SCORM → Phase 4 ;
- module d'apprentissage ILIAS natif → Phase 5 ;
- test et banque de questions → Phase 6.

## Sécurité du premier POC

La version `0.5.0-alpha` active uniquement le dry-run Phase 3 :

```bash
php local/iliasmigration/cli/import.php \
  --source=/opt/ilias2moodle-data/course-128-v2/migration.json \
  --category=1 \
  --phase=3 \
  --dry-run
```

`--phase=3 --apply` est volontairement refusé tant que ce dry-run n'a pas été validé sur le POC.

Le dry-run vérifie :

- présence du package complet à côté de `migration.json` ;
- chemins relatifs sûrs ;
- présence des fichiers ;
- URL `http`/`https` valide ;
- répertoire et fichier de démarrage des modules HTML ;
- absence de liens symboliques dans un paquet HTML ;
- état `CREATE`/`UPDATE` à partir de la table de mapping.

## Instance ILIAS source

À partir de `0.5.0-alpha`, les mappings sont séparés par instance ILIAS avec `sourceinstance`, dérivé en priorité de `course.metadata.installation_url` dans `migration.json`.

Les mappings créés avant cette évolution restent lisibles comme mappings historiques (`sourceinstance=''`) afin de ne pas casser le POC existant.

## POC ILIAS 10.8 actuel

Ressources simples attendues :

- `ref_id=132` — URL `lien` ;
- `ref_id=134` — module HTML `html` ;
- `ref_id=138` — fichier `missing_bios_report.txt` ;
- `ref_id=238` — fichier situé dans `sous dossier test` ;
- `ref_id=234` — PDF racine.

Le dry-run Phase 3 doit donc contrôler 5 ressources simples.

## Limitation d'ordre racine à résoudre

Le POC contient une ressource racine (`ref_id=234`) après le dossier racine `quizz`.

Avec le mapping actuel :

- les dossiers racine ILIAS deviennent des sections Moodle ;
- les ressources racine Moodle doivent être placées dans la section 0.

Une ressource Moodle de section 0 s'affiche avant les sections régulières. L'ordre ILIAS `... → quizz → PDF` ne peut donc pas être reproduit exactement sans une politique complémentaire, par exemple une section synthétique ou un changement de stratégie de représentation des dossiers racine.

Le dry-run émet `ROOT_RESOURCE_ORDER_APPROXIMATION` afin que ce cas ne soit jamais silencieux.

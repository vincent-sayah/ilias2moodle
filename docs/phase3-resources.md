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

## Dry-run

```bash
php local/iliasmigration/cli/import.php \
  --source=/opt/ilias2moodle-data/course-128-v2/migration.json \
  --category=1 \
  --phase=3 \
  --dry-run
```

Le dry-run vérifie :

- présence du package complet à côté de `migration.json` ;
- chemins relatifs sûrs ;
- présence des fichiers ;
- URL externe `http`/`https` valide ;
- liens WebResource internes ILIAS au format `type|ref_id` ;
- répertoire et fichier de démarrage des modules HTML ;
- absence de liens symboliques dans un paquet HTML ;
- état `CREATE`/`UPDATE` à partir de la table de mapping.

## Validation POC du 5 septembre 2026

Le POC ILIAS 10.8 / Moodle 5.0.2 a validé les cinq ressources Phase 3 :

```json
{
  "checked_resources": 5,
  "blocked_resources": 0,
  "ready": true
}
```

Le WebResource interne `blog|131` a également été testé depuis le serveur Moodle : le lien permanent ILIAS renvoie `302` vers l'authentification puis `200` sur la page de connexion. La préservation de ce lien est donc considérée fonctionnelle pour le POC.

À partir de `0.6.0-alpha`, `--phase=3 --apply` est activé.

## Apply Phase 3

Précondition : la structure Phase 2 doit déjà exister. Le plan doit présenter le cours, les sections et les sous-sections en `UPDATE` avec un `target_id` valide.

```bash
php local/iliasmigration/cli/import.php \
  --source=/opt/ilias2moodle-data/course-128-v2/migration.json \
  --category=1 \
  --phase=3 \
  --apply
```

L'exécuteur :

- relance le plan et la validation du package avant toute écriture ;
- refuse l'apply si une ressource est `BLOCKED`, si le package n'est pas `ready`, ou si la structure Phase 2 n'existe pas ;
- exécute la migration sous l'administrateur Moodle, comme la Phase 2 ;
- utilise `create_module()` / `update_module()` ;
- utilise exclusivement l'API fichiers Moodle pour les contenus ;
- conserve les sous-répertoires d'un module HTML ;
- place une ressource racine dans la section Moodle 0 ;
- place une ressource d'un dossier de niveau 1 dans la section Moodle correspondante ;
- place une ressource d'une sous-section dans la section déléguée de `mod_subsection` ;
- enregistre le mapping avec `sourceinstance` ;
- produit `CREATED` au premier passage et `UPDATED` aux passages suivants.

Le cours reste masqué après la Phase 3.

## Liens WebResource internes ILIAS

ILIAS 10.8 peut exporter un lien WebResource interne avec un `Target` comme :

```text
blog|131
```

Ce n'est pas une URL corrompue : ILIAS encode ici le type de l'objet et son `ref_id`. À partir de `0.5.1-alpha`, le validateur Phase 3 reconnaît ce format.

Pour le POC, avec l'instance source `http://192.168.56.50`, le lien est résolu provisoirement vers le lien permanent ILIAS :

```text
http://192.168.56.50/goto.php?target=blog_131
```

Le dry-run ajoute :

- `package_validation.code = ILIAS_INTERNAL_LINK` ;
- `resolved_url` ;
- les composantes `type` et `ref_id` ;
- un warning `ILIAS_INTERNAL_LINK_PRESERVED`.

Cette stratégie préserve le fonctionnement du lien sans inventer une destination Moodle. Une phase ultérieure de réécriture des liens pourra remplacer ce lien vers ILIAS par l'URL Moodle cible lorsque l'objet référencé a lui-même été migré.

## Instance ILIAS source

Les mappings sont séparés par instance ILIAS avec `sourceinstance`, dérivé en priorité de `course.metadata.installation_url` dans `migration.json`.

Les mappings structurels créés avant cette évolution restent lisibles comme mappings historiques (`sourceinstance=''`) afin de ne pas casser le POC existant. Les nouveaux mappings Phase 3 sont enregistrés avec l'instance source courante.

## POC ILIAS 10.8 actuel

Ressources simples attendues :

- `ref_id=132` — WebResource `lien`, target interne `blog|131` ;
- `ref_id=134` — module HTML `html` ;
- `ref_id=138` — fichier `missing_bios_report.txt` ;
- `ref_id=238` — fichier situé dans `sous dossier test` ;
- `ref_id=234` — PDF racine.

## Limitation d'ordre racine

Le POC contient une ressource racine (`ref_id=234`) après le dossier racine `quizz`.

Avec le mapping actuel :

- les dossiers racine ILIAS deviennent des sections Moodle ;
- les ressources racine Moodle sont placées dans la section 0.

Une ressource Moodle de section 0 s'affiche avant les sections régulières. L'ordre ILIAS `... → quizz → PDF` ne peut donc pas être reproduit exactement sans une politique complémentaire, par exemple une section synthétique ou un changement de stratégie de représentation des dossiers racine.

Le plan émet `ROOT_RESOURCE_ORDER_APPROXIMATION` afin que ce cas ne soit jamais silencieux.

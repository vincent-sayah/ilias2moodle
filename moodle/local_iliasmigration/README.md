# local_iliasmigration

Plugin local Moodle utilisé par ILIAS2Moodle pour reconstruire dans Moodle les structures extraites d'ILIAS.

## Compatibilité

- Moodle 4.5 minimum ;
- POC de développement validé sur Moodle 5.0.2 ;
- PHP 8.3 sur le POC Moodle.

## État actuel — Phase 2 alpha

Le plugin sait maintenant :

- valider `migration.json` ;
- vérifier la catégorie Moodle cible ;
- vérifier que `mod_subsection` est disponible et actif ;
- produire un plan `CREATE / UPDATE / CONFLICT / DEFER` ;
- détecter les mappings déjà présents ;
- refuser toute exécution sans `--dry-run` ;
- lister les catégories Moodle en lecture seule.

Le plugin ne crée encore aucun cours, section, sous-section ou ressource. Cette activation sera faite après validation du dry-run sur le cours POC.

## Table de mapping

L'installation crée `local_iliasmigration_map`. Elle permettra d'associer durablement les identifiants ILIAS aux objets Moodle pour rendre la migration rejouable et idempotente.

## Lister les catégories

Depuis la racine Moodle :

```bash
php local/iliasmigration/cli/categories.php
```

## Dry-run Phase 2

```bash
php local/iliasmigration/cli/import.php \
  --source=/chemin/vers/migration.json \
  --category=ID \
  --dry-run
```

Le dry-run doit notamment proposer pour le POC :

- cours `Test migration` → `CREATE` ;
- dossier `Dossier test` → section Moodle ;
- dossier `sous dossier` → sous-section Moodle ;
- PDF, URL, module HTML → `DEFER` Phase 3 ;
- SCORM → `DEFER` Phase 4 ;
- test et banque → `DEFER` Phase 6.

Toute tentative sans `--dry-run` est actuellement refusée volontairement.

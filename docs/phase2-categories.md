# Phase 2 — Politique des catégories Moodle

## Objectif

La Phase 2 peut cibler soit une catégorie Moodle déjà connue par son identifiant, soit une hiérarchie de catégories/sous-catégories décrite par un chemin lisible.

Le comportement historique reste disponible :

```bash
php local/iliasmigration/cli/import.php \
  --source=/path/to/migration.json \
  --category=1 \
  --phase=2 \
  --dry-run
```

La nouvelle politique ajoute :

```bash
php local/iliasmigration/cli/import.php \
  --source=/path/to/migration.json \
  --category-path="Marine > Formation > PEM" \
  --phase=2 \
  --dry-run
```

## Règles de résolution

Pour chaque segment du chemin :

1. le résolveur cherche une catégorie portant exactement ce nom sous le parent attendu ;
2. s'il existe exactement une correspondance, elle est sélectionnée ;
3. s'il n'existe aucune correspondance, le segment est planifié en `CREATE` ;
4. s'il existe plusieurs catégories sœurs portant ce nom, le chemin est jugé ambigu et l'import est bloqué avant écriture.

Le séparateur recommandé est `>` ; `/` reste accepté pour les chemins simples.

## Sécurité

- `--category=ID` et `--category-path` sont mutuellement exclusifs ;
- la création automatique est limitée à la Phase 2 ;
- les Phases 3 à 6 continuent d'exiger un `--category=ID` explicite ;
- une catégorie existante sélectionnée n'est ni renommée, ni déplacée, ni masquée ;
- une catégorie créée automatiquement est masquée (`visible=0`) pendant le POC ;
- toute ambiguïté produit le bloqueur stable `CATEGORY_PATH_AMBIGUOUS` ;
- aucune écriture SQL directe n'est effectuée : les créations passent par `core_course_category::create()`.

## Dry-run

Si le chemin existe déjà intégralement, le plan Phase 2 normal est produit avec l'identifiant final de catégorie et le détail de résolution dans `moodle.category_resolution`.

Si un ou plusieurs segments doivent être créés, le dry-run ne crée rien. Il expose :

- les opérations `SELECT` et `CREATE` de catégorie ;
- `target_id=null` pour la catégorie finale qui n'existe pas encore ;
- une opération cours `WAIT_CATEGORY` ;
- `writes_performed=false`.

Après un `--apply`, un nouveau dry-run doit résoudre le même chemin uniquement en `SELECT` et retrouver le même identifiant final.

## Limite source actuelle

L'export natif ZIP d'un cours ILIAS ne fournit pas, dans le modèle actuel d'ILIAS2Moodle, le chemin complet de ses catégories parentes. Le chemin cible est donc pour l'instant fourni explicitement avec `--category-path`.

La découverte automatique de l'arborescence de dépôt ILIAS pourra être ajoutée ultérieurement au connecteur source (SOAP/API) sans modifier la politique Moodle décrite ici.

## Validation POC attendue

Le POC doit valider successivement :

1. dry-run sur un chemin partiellement ou totalement absent ;
2. aucune écriture pendant ce dry-run ;
3. apply Phase 2 avec création des catégories manquantes ;
4. catégories créées masquées ;
5. cours placé dans la catégorie finale ;
6. second dry-run ne proposant plus de `CREATE` de catégorie ;
7. second apply idempotent ;
8. contrôle visuel dans Moodle.

# Phase 2 — Reconstruction de la structure Moodle

## Environnement POC Moodle

Validation initiale du 4 septembre 2026 :

- Moodle `5.0.2 (Build: 20250811)` ;
- version interne `2025041402.00` ;
- PHP CLI `8.3.26` ;
- module `subsection` présent et actif (`visible=1`).

Le plugin conserve Moodle 4.5 comme version minimale afin de ne pas fermer inutilement la compatibilité, mais la cible POC réelle est désormais Moodle 5.0.2.

## Objectif du premier incrément

Avant toute création réelle, Moodle doit être capable de lire le `migration.json` généré en Phase 1 et produire un plan déterministe.

Pour le POC ILIAS `ref_id=31250` :

```text
Test migration
└── Dossier test
    └── sous dossier
```

devient :

```text
Cours Moodle : Test migration
└── Section : Dossier test
    └── Sous-section : sous dossier
```

Les ressources restent différées vers leurs phases dédiées.

## Sécurité

Le premier importeur Moodle est strictement `dry-run` :

- aucune création de cours ;
- aucune création de section ;
- aucune création de sous-section ;
- aucune ressource importée ;
- aucune ligne de mapping créée pendant le dry-run.

L'installation du plugin crée seulement sa table technique `local_iliasmigration_map`.

## Installation du plugin sur le serveur Moodle

Copier le répertoire :

```text
moodle/local_iliasmigration
```

vers :

```text
/var/www/moodle/local/iliasmigration
```

puis lancer depuis `/var/www/moodle` :

```bash
php admin/cli/upgrade.php --non-interactive
```

Vérifier ensuite :

```bash
php local/iliasmigration/cli/categories.php
```

## Exécution du dry-run

Le fichier `migration.json` peut être copié seul sur le serveur Moodle pour ce premier test.

```bash
php local/iliasmigration/cli/import.php \
  --source=/opt/ilias2moodle-data/course-31250/migration.json \
  --category=ID \
  --dry-run
```

Le résultat est du JSON et doit indiquer `writes_performed: false`.

## Règles d'arborescence

- dossier ILIAS de niveau 1 → section Moodle ;
- dossier ILIAS de niveau 2 → activité `subsection` Moodle ;
- profondeur supérieure à 2 → `FLATTEN_REQUIRED` dans le plan ;
- ressources → `DEFER` jusqu'à leur phase fonctionnelle.

## Étape suivante

Après validation du dry-run sur Moodle 5.0.2, le second incrément Phase 2 activera les écritures pour :

1. créer le cours ;
2. enregistrer son mapping ;
3. créer la section ;
4. créer la sous-section ;
5. rejouer l'import sans doublon.

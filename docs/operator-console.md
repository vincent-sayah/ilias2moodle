# Guide opérateur — Console ILIAS2Moodle V1

## Objectif

La console opérateur permet de lancer et suivre une migration sans enchaîner manuellement toutes les commandes CLI.

Version candidate : `0.21.0-rc1 / 2026100401`.

## Prérequis

- Moodle 4.5+ ; qualification cible : Moodle 5.0.2 ;
- plugin `local_iliasmigration` installé et upgradé ;
- cron Moodle opérationnel ;
- compte possédant `local/iliasmigration:manage` ;
- package ILIAS2Moodle déjà préparé ;
- espace disque suffisant dans `moodledata`.

Après installation :

```bash
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

Vérifier le cron :

```bash
php admin/cli/cron.php
```

## Préparer la source

### Option A — ZIP de package préparé

Créer d'abord le package ILIAS2Moodle :

```bash
./tools/run-ilias2moodle.sh prepare-export \
  --zip=/exports/course.zip \
  --output=/work/course \
  --ilias-version=10.8
```

Ajouter les récupérations complémentaires nécessaires selon les objets du cours, puis zipper **le package préparé**, pas uniquement le ZIP ILIAS natif.

Le ZIP envoyé à Moodle doit contenir exactement un `migration.json`.

### Option B — package déjà présent sur le serveur Moodle

Saisir le chemin absolu vers son `migration.json`.

Cette option évite de recopier de gros packages.

## Ouvrir la console

Dans Moodle :

```text
Administration du site
→ Plugins
→ Plugins locaux
→ Console opérateur ILIAS2Moodle
```

L'URL directe est :

```text
/local/iliasmigration/operator.php
```

## Créer un job

Renseigner :

1. **Source** :
   - ZIP du package, ou
   - chemin serveur vers `migration.json` ;
2. **Chemin de catégorie Moodle cible**, exemple :
   `Marine > Formation > Migration ILIAS` ;
3. **Politique d'erreur** ;
4. confirmation des écritures Moodle.

Une seule source doit être choisie.

### Politique « Pause »

Recommandée pour une première migration.

Une erreur non critique met le job en `PAUSED`. L'interface affiche :

- étape ;
- message d'erreur ;
- nombre de tentatives ;
- `Réessayer` ;
- `Ignorer et continuer` si l'étape est skippable.

### Politique « Continuer »

Une erreur non critique est journalisée comme `SKIPPED_ERROR` et la famille suivante démarre automatiquement.

À utiliser uniquement quand l'opérateur accepte qu'un type de contenu puisse être absent du résultat final.

## Étapes critiques

Les étapes suivantes ne sont pas ignorables :

- préflight du package ;
- création/résolution de la structure du cours.

Si elles échouent, poursuivre créerait une cible incohérente.

## Exemple d'échec Wiki

```text
Job #17          PAUSED

10 Préflight              SUCCESS
20 Structure              SUCCESS
30 Ressources simples     SUCCESS
40 SCORM                  SUCCESS
50 Wikis                  FAILED
60 Glossaires             PENDING
70 Exercices              PENDING

Erreur Wiki: ...
[Réessayer] [Ignorer et continuer]
```

### Réessayer

Utiliser après correction de la cause : fichier ajouté, mapping réparé, configuration Moodle corrigée, etc.

L'étape et les étapes suivantes sont remises en attente puis rejouées. Les executors utilisent les mappings persistants pour éviter les doublons.

### Ignorer et continuer

L'étape devient `IGNORED`. Le rapport final conserve :

- la famille ignorée ;
- l'erreur ;
- les événements associés.

Le job se termine en `COMPLETED_WARNINGS` si nécessaire.

## Granularité V1

L'ignorance est **par famille d'objets**.

Si trois Wikis sont présents et que l'executor Wiki échoue sur le troisième, la transaction Wiki est rollbackée ; « Ignorer » signifie donc ignorer l'étape Wikis et poursuivre les autres familles.

La migration des autres familles continue normalement. La transaction par objet individuel est un objectif V1.1.

## Journalisation

Le journal persistant contient au minimum :

- création et démarrage du job ;
- démarrage/réussite/échec de chaque étape ;
- exceptions ;
- saut automatique ;
- décision manuelle d'ignorer ;
- demande de retry ;
- fin du job.

Les logs sont stockés en base Moodle et visibles depuis la page du job.

## Compte rendu

Le lien **Compte rendu de migration** est disponible depuis le job.

Le JSON contient :

```json
{
  "schema_version": "operator-report-1",
  "job": {},
  "step_counts": {},
  "steps": [],
  "logs": []
}
```

Pour chaque étape sont conservés :

- statut ;
- caractère critique/skippable ;
- nombre de tentatives ;
- contexte (refs source) ;
- résultat de l'executor ;
- erreur éventuelle ;
- timestamps.

Le rapport JSON est téléchargeable pour archivage.

## États

| État | Signification |
|---|---|
| `NEW` | job créé |
| `QUEUED` | en attente du cron |
| `RUNNING` | worker actif |
| `PAUSED` | intervention opérateur requise |
| `FAILED` | erreur critique/fatale |
| `COMPLETED` | terminé sans erreur ignorée |
| `COMPLETED_WARNINGS` | terminé avec étapes ignorées/sautées |

Étapes : `PENDING`, `RUNNING`, `SUCCESS`, `FAILED`, `IGNORED`, `SKIPPED_ERROR`.

## Sécurité

- capability système dédiée ;
- confirmation explicite des écritures ;
- `sesskey` pour les actions opérateur ;
- contrôle ZIP contre chemins absolus/traversal/symlinks ;
- SHA-256 de la source stocké dans le job ;
- lock Moodle empêchant l'exécution concurrente du même job.

Les limites PHP/Moodle d'upload restent applicables (`upload_max_filesize`, `post_max_size`). Pour les gros packages, préférer le chemin serveur.

## Ce que la V1 n'automatise pas encore

- préparation complète depuis un export ILIAS brut ;
- récupérations source complémentaires ;
- Phase 7.1 utilisateurs/inscriptions/rôles dans le pipeline principal ;
- contributions Forum Phase 7.4 dans le pipeline principal ;
- métadonnées Wiki Phase 7.6 dans le pipeline principal ;
- progression/résultats : volontairement historique seulement ;
- isolation transactionnelle objet-par-objet ;
- réconciliation d'ordre V2 lorsque des Item Groups sont présents ;
- annulation d'un job en cours et politique de rétention/purge des packages.

## Qualification avant production

Pour la première qualification :

1. installer la branch/candidate sur une VM Moodle de test ;
2. exécuter l'upgrade ;
3. lancer un package connu ;
4. vérifier chaque étape ;
5. provoquer volontairement une erreur Wiki ;
6. tester `Ignorer et continuer` ;
7. vérifier que les autres familles sont migrées ;
8. vérifier `COMPLETED_WARNINGS` et le rapport ;
9. corriger la cause puis lancer un nouveau job/retry ;
10. rejouer la même migration et vérifier l'idempotence.

Ne promouvoir en version stable qu'après cette validation.

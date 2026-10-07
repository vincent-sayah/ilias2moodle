# Console opérateur ILIAS2Moodle — guide d'utilisation

## Objectif

La console opérateur introduite en `0.21.0-beta1` permet de piloter une migration ILIAS → Moodle depuis l'interface Moodle sans lancer manuellement chaque commande CLI.

Elle s'appuie sur les executors et validateurs déjà validés par le projet et ajoute :

- une orchestration ordonnée ;
- une journalisation persistante ;
- une reprise après erreur ;
- un choix explicite `Réessayer` / `Ignorer et continuer` ;
- un rapport final HTML ;
- un rapport JSON téléchargeable ;
- une exécution découpée en étapes pour éviter une requête web monolithique.

## Prérequis

- plugin `local_iliasmigration` installé ;
- upgrade Moodle effectué après installation de `0.21.0-beta1` ;
- package ILIAS2Moodle déjà préparé et présent sur le serveur Moodle ;
- fichier `migration.json` lisible par PHP/Apache ;
- utilisateur disposant de la capability `local/iliasmigration:operate`.

La console n'effectue pas encore l'extraction ILIAS elle-même. Le package doit donc être préparé en amont avec les outils ILIAS2Moodle.

## Accès

Administration du site → Plugins → Plugins locaux → **Console opérateur ILIAS2Moodle**.

URL directe :

```text
/local/iliasmigration/index.php
```

## Protection contre les doubles migrations

La console refuse de créer un nouveau run complet lorsqu'un mapping `course` valide existe déjà pour le même cours ILIAS et pointe vers un cours Moodle existant.

Cette protection correspond au scénario d'exploitation normal : un cours source est migré une fois.

Elle ne bloque pas les actions **Réessayer** dans un run déjà créé. L'idempotence des executors reste donc utilisée pour reprendre une étape en erreur sans créer de doublons.

## Création d'une migration

L'opérateur renseigne :

1. le chemin absolu vers `migration.json` ;
2. soit une catégorie Moodle existante ;
3. soit un chemin de catégorie à résoudre/créer automatiquement.

Exemple de source :

```text
/opt/ilias2moodle/work/course827/migration.json
```

Exemple de chemin de catégorie :

```text
Marine > Formation > Migration ILIAS
```

La console vérifie immédiatement :

- que le fichier existe ;
- qu'il est lisible ;
- que le document est un package ILIAS2Moodle valide ;
- que la cible catégorie est cohérente ;
- les types d'objets présents dans le package.

## Pipeline automatique beta1

La console crée une étape persistante par famille d'objet :

1. structure ;
2. ressources simples ;
3. SCORM ;
4. Learning Modules → Book ;
5. banques de questions + Quiz ;
6. Content Pages ;
7. Glossaires ;
8. Wikis ;
9. Exercices ;
10. Forums ;
11. MediaCasts ;
12. Blogs ;
13. Media Pools ;
14. Item Groups.

Une famille absente du package est automatiquement marquée :

```text
SKIPPED
SKIPPED_NOT_APPLICABLE
```

Cela évite de lancer inutilement un executor.

## États d'un run

```text
READY
RUNNING
WAITING_DECISION
COMPLETED
COMPLETED_WITH_SKIPS
```

### READY

Le run est créé et peut démarrer.

### RUNNING

La console exécute les étapes dans l'ordre.

Une seule famille est exécutée par requête HTTP. La page enchaîne automatiquement les étapes par redirections successives.

Cette conception évite de maintenir une unique requête très longue et permet de reprendre un run après interruption.

### WAITING_DECISION

Une étape a échoué.

Aucune étape suivante n'est exécutée tant que l'opérateur n'a pas choisi :

- **Réessayer l'étape** ;
- **Ignorer et continuer**.

### COMPLETED

Toutes les étapes applicables ont terminé sans erreur ignorée.

### COMPLETED_WITH_SKIPS

Au moins une étape en erreur a été explicitement ignorée par l'opérateur.

Le rapport final conserve cette information.

## Gestion d'une erreur

Exemple :

```text
Structure              SUCCESS
Ressources             SUCCESS
SCORM                  SUCCESS
Learning Modules       SUCCESS
Questions / Quiz       SUCCESS
Content Pages          SUCCESS
Glossaires             SUCCESS
Wikis                  FAILED
```

La console passe à :

```text
WAITING_DECISION
```

L'opérateur voit le message d'erreur du Wiki.

### Choix 1 — Réessayer

À utiliser lorsque la cause a été corrigée :

- package remis en conformité ;
- fichier manquant restauré ;
- module Moodle activé ;
- mapping corrigé ;
- prérequis applicatif rétabli.

L'étape repasse en `PENDING`, puis l'executor est rejoué.

### Choix 2 — Ignorer et continuer

Si le Wiki doit être traité ultérieurement, l'opérateur clique sur **Ignorer et continuer**.

La console enregistre :

```text
STEP_IGNORED
SKIPPED
ignored=true
```

Puis elle poursuit les autres familles :

```text
Exercices
Forums
MediaCasts
Blogs
Media Pools
Item Groups
```

Le Wiki n'est donc pas masqué dans le compte rendu : l'écart reste explicitement visible.

## Granularité beta1

La reprise/ignorance est actuellement gérée **par famille d'objet**.

Exemple :

- étape `Wikis` ;
- étape `Glossaires` ;
- étape `Exercices`.

Si un package contient plusieurs Wikis et qu'un seul bloque l'executor Wiki, l'étape Wiki complète peut être rejouée ou ignorée.

Une évolution ultérieure pourra découper les executors en unités `source_ref_id` pour permettre d'ignorer un Wiki précis tout en migrant les autres Wikis du même package.

Cette limitation est volontairement explicite dans la beta1.

## Journalisation

Trois tables complètent la table historique de mapping :

```text
local_iliasmigration_run
local_iliasmigration_step
local_iliasmigration_log
```

### Run

Conserve notamment :

- source `migration.json` ;
- SHA-256 du fichier source ;
- catégorie cible ;
- état global ;
- utilisateur ayant lancé la migration ;
- dates de début/fin ;
- résumé final.

### Step

Conserve :

- famille d'objet ;
- position dans le pipeline ;
- état ;
- nombre de tentatives ;
- erreur éventuelle ;
- indication `ignored` ;
- résumé compact du résultat de l'executor.

### Log

Conserve les événements :

```text
RUN_CREATED
STEP_NOT_APPLICABLE
STEP_STARTED
STEP_SUCCESS
STEP_FAILED
STEP_RETRY_REQUESTED
STEP_IGNORED
RUN_COMPLETED
```

avec niveau :

```text
INFO
WARNING
ERROR
```

## Compte rendu final

Depuis la page du run :

- **Voir le compte rendu** : synthèse HTML ;
- **Télécharger le rapport JSON** : export structuré.

Le JSON contient :

- identité du run ;
- hash source ;
- cible ;
- état final ;
- résumé des étapes ;
- erreurs ;
- étapes ignorées ;
- journal chronologique.

Un run ayant ignoré un objet se termine avec :

```text
COMPLETED_WITH_SKIPS
```

et non `COMPLETED`.

## Sécurité

La console utilise deux capabilities :

```text
local/iliasmigration:operate
local/iliasmigration:viewreports
```

Elles sont accordées par défaut au rôle Manager.

Les actions modifiant l'état utilisent le `sesskey` Moodle.

Les executors existants gardent leurs validations de package, transactions, fingerprints, API Moodle et contrôles d'idempotence.

La console n'ajoute pas de chemin d'écriture directe dans les tables pédagogiques cœur de Moodle.

## Idempotence

La console ne remplace pas les protections existantes.

Chaque executor continue à utiliser les mappings persistants ILIAS ↔ Moodle et ses propres règles `CREATE / UPDATE / KEEP / BLOCKED`.

Relancer un run sur un package déjà migré doit donc réutiliser les objets cibles existants lorsque l'executor concerné est idempotent.

## Périmètre non automatisé en beta1

### Phase 7

Les workflows suivants restent disponibles par CLI, mais ne sont pas encore intégrés à la console car ils nécessitent des inventaires/extractions auxiliaires distincts du `migration.json` principal :

- utilisateurs, inscriptions et rôles ;
- contributions Forum historiques ;
- auteurs Blog ;
- auteur/date des pages Wiki ;
- rapport historique de progression/résultats.

### Progression / résultats

La Phase 7.3 reste volontairement :

```text
PHASE73_HISTORICAL_REPORT_ONLY
```

Elle ne crée aucune tentative, note ou completion Moodle.

### Réconciliation d'ordre V2

La réconciliation finale d'ordre reste actuellement une commande gardée avec plan et SHA-256 attendus.

Elle n'est pas appelée implicitement par la console beta1 afin de ne pas affaiblir les contrôles déjà validés.

## Procédure opérateur recommandée

1. Préparer le package ILIAS2Moodle.
2. Copier le package sur le serveur Moodle.
3. Ouvrir la Console opérateur.
4. Sélectionner `migration.json`.
5. Sélectionner/créer la catégorie cible.
6. Lancer.
7. Surveiller les étapes.
8. En cas d'erreur :
   - corriger + réessayer ;
   - ou ignorer explicitement.
9. Télécharger le compte rendu JSON.
10. Exécuter si nécessaire les extensions Phase 7.
11. Exécuter la réconciliation d'ordre V2 lorsque le package utilise les scénarios concernés.
12. Effectuer le contrôle fonctionnel/visuel final avant de rendre le cours visible.

## Scénario de qualification — Ignorer un Wiki en erreur

L'issue #68 est qualifiée avec un package de test jetable dérivé d'un package réel déjà validé. Le cours nominal migré ne doit pas être modifié.

Créer le fixture :

```bash
python3.11 tools/make-operator-ignore-fixture.py \
  --source-package=/opt/ilias2moodle/operator_packages/course827 \
  --output=/opt/ilias2moodle/operator_packages/course827_ignore_wiki \
  --source-course-id=9282
```

Le générateur :

- copie intégralement le package source ;
- remplace uniquement l'identité du cours par le `source_id=9282` ;
- ajoute le suffixe `[TEST IGNORE WIKI]` au titre ;
- conserve les objets pédagogiques et ressources ;
- modifie uniquement le `schema_version` du premier `wikis/<ref>/structure.json` ;
- produit `operator-fixture.json` avec la description du défaut injecté ;
- ne modifie jamais le package source.

Erreur attendue à l'étape Wikis :

```text
WIKI_SCHEMA_UNSUPPORTED
```

Séquence attendue :

```text
Structure .. Content Pages   SUCCESS
Wikis                        FAILED
Run                          WAITING_DECISION
                              ↓
                       Ignorer et continuer
                              ↓
Exercices et étapes suivantes continuent
                              ↓
Run                          COMPLETED_WITH_SKIPS
```

Le rapport final doit conserver l'étape Wiki avec `ignored=true` et l'événement `STEP_IGNORED`.

Le fixture est exclusivement destiné à la qualification technique et doit être supprimé avec son cours Moodle cible et ses mappings après conservation des preuves de test.

## Critère d'exploitation de la beta1

La beta1 vise à supprimer la majorité des commandes manuelles pour une migration standard tout en conservant les garde-fous techniques existants.

Elle est considérée exploitable pour validation opérateur lorsque :

- l'installation/upgrade Moodle est validé ;
- un package de référence peut être migré de bout en bout depuis l'interface ;
- une étape volontairement mise en échec provoque bien `WAITING_DECISION` ;
- `Réessayer` fonctionne ;
- `Ignorer et continuer` permet aux familles suivantes de terminer ;
- le rapport final reflète exactement les étapes ignorées et réussies.

## Validation réelle — run #2

Validation réalisée le 4 octobre 2026 sur Moodle 5.0.2 avec une cible vierge.

Source :

```text
/opt/ilias2moodle/operator_packages/course827/migration.json
SHA-256 = 4b31eaab054d791962bd56fbd65c796632a633d3210d3629fc06cc6c3f113083
ILIAS ref_id = 282
ILIAS obj_id = 827
Cours test RC 0.20
```

Résultat opérateur :

```text
Run #2
status = COMPLETED
target category = 1
target course = Moodle id 4 / ILIAS-282
visible = 0
```

Étapes :

```text
Structure                         SUCCESS
Ressources simples               SUCCESS
SCORM                             SUCCESS
Learning Modules -> Book         SUCCESS
Banques de questions / Quiz      SUCCESS
Content Pages                    SUCCESS
Glossaires                       SKIPPED_NOT_APPLICABLE
Wikis                             SUCCESS
Exercices                         SUCCESS
Forums                            SKIPPED_NOT_APPLICABLE
MediaCasts                        SUCCESS
Blogs                             SUCCESS
Media Pools                       SUCCESS
Item Groups                       SUCCESS
```

Chaque étape applicable a réussi à la première tentative.

Contrôle post-migration :

```text
TOTAL_MAPPINGS = 621

assign       = 2
book         = 1
course       = 1
data         = 3
data_record  = 7
file         = 5
html_module  = 1
page         = 1
qbank        = 14
question     = 571
quiz         = 2
scorm        = 1
section      = 5
subsection   = 3
url          = 1
wiki         = 1
wiki_page    = 2
```

Le cours Moodle est créé masqué, conformément à la politique de validation avant mise à disposition.

Le scénario nominal end-to-end via l'interface est donc **validé**.

Restent à valider ultérieurement avant promotion RC :

- un scénario contrôlé `FAILED -> Ignorer et continuer -> COMPLETED_WITH_SKIPS` ;
- le contrôle fonctionnel final du rapport après amélioration des compteurs par étape ;
- les extensions Phase 7 et la réconciliation d'ordre V2 ne sont toujours pas automatisées par cette beta.

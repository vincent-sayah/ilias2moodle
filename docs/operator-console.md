# Console opérateur ILIAS2Moodle — guide d'utilisation

## Objectif

La console opérateur introduite en `0.21.0-beta1` et renforcée en `0.21.0-beta2` permet de piloter une migration ILIAS → Moodle depuis l'interface Moodle sans lancer manuellement chaque commande CLI.

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
- upgrade Moodle effectué après installation de `0.21.0-beta2` afin de créer la table d'audit des resets ;
- soit un export ZIP ILIAS natif lisible sous `importsroot`, que la console V1.1 peut préparer ;
- soit un package ILIAS2Moodle déjà préparé avec un fichier `migration.json` lisible par PHP/Apache ;
- utilisateur disposant de la capability `local/iliasmigration:operate`.

La console V1.1 peut préparer localement un export ZIP ILIAS natif en réutilisant le worker Python `prepare-export`. Les récupérations nécessitant un accès à l'instance ILIAS source restent exécutées séparément en lecture seule, puis le package préparé peut être réutilisé par la console.

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

## Réinitialisation après suppression d'un cours Moodle

Si un cours créé par ILIAS2Moodle est supprimé manuellement dans Moodle, les mappings persistants peuvent rester présents. La console détecte désormais ce cas avant la création d'un nouveau run.

États de détection :

```text
NONE         aucun mapping existant
LIVE_TARGET  le cours Moodle mappé existe encore : reset interdit
ORPHANED     le cours Moodle mappé n'existe plus : reset explicite proposé
AMBIGUOUS    scope de mappings non sûr : reset automatique refusé
```

Pour un état `ORPHANED`, la console affiche une page de confirmation avec :

- le cours source ILIAS ;
- l'instance source ;
- l'ancien ID du cours Moodle supprimé ;
- le nombre de mappings concernés ;
- le détail par type de mapping.

L'action **Réinitialiser les mappings et relancer la migration** :

1. recontrôle dans une transaction que le cours Moodle cible n'existe toujours pas ;
2. sérialise tous les mappings du périmètre logique du cours source : instance ILIAS canonique **et** scope historique `sourceinstance=''` lorsqu'il existe ;
3. calcule le SHA-256 du snapshot ;
4. écrit le snapshot dans `local_iliasmigration_reset` ;
5. supprime dans la même transaction les mappings de l'instance canonique et les mappings legacy vides du même `sourcecourse` ;
6. vérifie qu'aucun mapping du scope ne reste ;
7. crée immédiatement un nouveau run sur le même package et la même catégorie.

La présence d'un cours Moodle cible vivant interdit le reset. La console ne supprime donc jamais les mappings d'une migration encore active.

Un appel direct à `create_run()` est également protégé : un mapping de cours orphelin déclenche `courseorphanedmapping` et impose le passage par le reset explicite.

### Validation réelle du reset — run #6

Validation réalisée le 10 octobre 2026 sur Moodle 5.0.2 avec le fixture `sourcecourse=9283`.

Le scénario a volontairement supprimé le cours Moodle cible puis relancé la migration depuis la console. Une première itération a mis en évidence que les mappings historiques de Structure utilisaient `sourceinstance=''` alors que les phases suivantes utilisaient l'instance canonique `http://192.168.56.50`. Le reset beta2 traite désormais ces deux scopes comme un seul périmètre logique.

Audit final #2 :

```text
sourcecourse        = 9283
ancien cours Moodle = 7
mappings audités    = 618
snapshot JSON       = 618
SHA-256             = OK

sourceinstance=''                  = 6
sourceinstance=http://192.168.56.50 = 612
```

Après confirmation opérateur, la console a :

- conservé la catégorie cible `1` ;
- supprimé l'ensemble des 618 mappings orphelins ;
- créé automatiquement le nouveau run #6 ;
- recréé le cours Moodle sous un nouvel ID (`8`) ;
- exécuté Structure, Ressources, SCORM, Book, Questions/Quiz et Content Pages sans `ERROR_STALE_MAPPING` ;
- poursuivi après l'ignore contrôlé du Wiki de qualification ;
- terminé en `COMPLETED_WITH_SKIPS`.

Le scénario `suppression cible -> ORPHANED -> audit -> reset complet -> relance automatique -> remigration` est donc validé de bout en bout.

## V1.1 — préparation d'un export ZIP natif

La V1.1 commence par réutiliser le worker Python existant `prepare-export` au lieu de réimplémenter le parsing ILIAS en PHP.

Configuration Moodle :

```text
local_iliasmigration/projectroot
local_iliasmigration/importsroot
local_iliasmigration/packagesroot
local_iliasmigration/iliasversion
```

Valeurs de test usuelles :

```text
projectroot  = /opt/ilias2moodle
importsroot  = /var/moodledata/ilias2moodle/imports
packagesroot = /var/moodledata/ilias2moodle/packages
iliasversion = 10.8.0
```

Le ZIP doit obligatoirement se trouver sous `importsroot`. Le package de sortie est créé sous `packagesroot`. Un package existant n'est jamais écrasé.

Commande Moodle :

```bash
runuser -u apache -- php local/iliasmigration/cli/prepare_package.php \
  --zip=/var/moodledata/ilias2moodle/imports/export-ilias.zip \
  --output-name=course827
```

Pour un test CLI sur AlmaLinux, exécuter la commande avec le compte du serveur web (`apache`) reproduit le contexte réel de la console. Le bridge impose en plus un umask `0027` au worker : les nouveaux répertoires/fichiers sont donc créés avec des droits restrictifs au lieu de `777/666`.

Les récupérations complémentaires supportées par le worker Python restent disponibles :

```text
--exercise-irss-recovery
--mediacast-media-recovery
--mediaobject-recovery
--forum-attachment-recovery
--wiki-content-recovery
```

La couche PHP ne construit pas elle-même `migration.json`. Elle valide les chemins, appelle `tools/run-ilias2moodle.sh prepare-export` via une commande argumentée sans shell, contrôle le code retour et vérifie que `migration.json` a effectivement été créé.

Cette commande et le même service sont raccordés à l'interface opérateur V1.1. La préparation reste synchrone côté Moodle ; les récupérations nécessitant l'accès au serveur ILIAS sont pilotées par le `recovery-plan.json` et exécutées séparément en lecture seule.

### Interface web de préparation

La console V1.1 affiche désormais un bloc **Préparer un export ILIAS** avant le formulaire historique de lancement à partir de `migration.json`.

Le formulaire :

1. liste uniquement les fichiers `.zip` lisibles réellement présents sous `importsroot` ;
2. demande un nom de package de sortie ;
3. appelle le même service `operator_package_preparer` que le CLI ;
4. affiche le cours détecté, le titre, le nombre d'objets et le nombre de dépendances restantes ;
5. affiche le `recovery_plan` lorsqu'une récupération côté source est requise ;
6. lorsque `missing_count=0`, propose **Utiliser ce package pour une migration**, ce qui préremplit le chemin `migration.json` dans le formulaire de migration existant.

Le formulaire avancé historique reste disponible afin de ne pas casser les workflows déjà qualifiés.

Si le répertoire de sortie demandé existe déjà, la console ne l'écrase pas. Elle vérifie désormais que le package existant contient des `package.json` et `migration.json` lisibles et que `source_archive` correspond au ZIP sélectionné. Si ces contrôles réussissent, le package est réutilisé et son état courant est affiché ; cela permet notamment de reprendre un package après une récupération côté source. Un répertoire incomplet ou associé à un autre ZIP reste bloqué.

### Plan de récupération V1.1

Après `prepare-export`, le package contient désormais également :

```text
recovery-plan.json
```

Ce fichier décrit les dépendances read-only encore nécessaires côté ILIAS. Pour une collection IRSS d'Exercise non embarquée dans le ZIP, le plan contient notamment :

- le `ref_id` de l'Exercise ;
- l'ID d'unité lorsque disponible ;
- l'UUID exact de collection IRSS ;
- l'extracteur `tools/ilias_irss_extract.php` ;
- les arguments nécessaires ;
- la cible d'exécution `ILIAS_SOURCE` ;
- l'option de réinjection `--exercise-irss-recovery` ;
- le chemin attendu du `manifest.json`.

La génération du plan ne déclenche aucune commande distante. Elle constitue le contrat entre le worker de préparation et le futur orchestrateur de récupération. Les dépendances sans contrat automatique restent explicitement dans `unresolved` au lieu d'être ignorées.

Le dépôt fournit aussi un worker local à exécuter **sur le serveur ILIAS source**. Pour éviter d'installer toutes les dépendances Python du parseur sur le serveur ILIAS, le chemin recommandé utilise le wrapper autonome :

```bash
python3.11 tools/run-recovery-plan.py \
  --plan=/chemin/recovery-plan.json \
  --output=/tmp/ilias2moodle-recovery \
  --ilias-root=/var/www/ilias \
  --client=ilias10
```

Ce wrapper n'importe que le module de récupération, basé sur la bibliothèque standard Python. Le sous-commande `recover-source` du CLI principal reste disponible quand l'environnement Python complet ILIAS2Moodle est installé.

Le worker :

1. refuse toute requête qui n'est pas marquée `read_only=true` ;
2. refuse une cible autre que `ILIAS_SOURCE` ;
3. exécute les extracteurs avec une liste d'arguments, sans shell ;
4. vérifie la présence des manifests attendus ;
5. renvoie un résumé JSON global.

L'option `--dry-run` permet de valider le plan et les commandes sans lire de ressources ILIAS.

Une collection IRSS vide est un succès fonctionnel : l'extracteur écrit un manifest avec `resource_count=0` et retourne désormais un code de sortie `0`. Le worker garde aussi une compatibilité avec les anciens bundles qui retournaient `3 / COLLECTION_VIDE`, à condition que le manifest vide soit cohérent.

Le contrat `recovery-plan.json` décrit désormais plusieurs recoveries read-only déjà supportées par le projet :

| Type de dépendance | Extracteur source | Option de réinjection |
|---|---|---|
| collection IRSS Exercise | `tools/ilias_irss_extract.php` | `--exercise-irss-recovery` |
| fichier MediaObject d'un MediaCast | `tools/ilias_mediaobject_extract.php` | `--mediacast-media-recovery` |
| MediaObject Blog / Media Pool | `tools/ilias_mediaobject_extract.php` | `--mediaobject-recovery` |
| pièce jointe Forum | `tools/ilias_forum_attachment_extract.php` | `--forum-attachment-recovery` |
| contenu courant d'un Wiki absent du ZIP | `tools/ilias_wiki_content_extract.php` | `--wiki-content-recovery` |

Chaque requête contient les identifiants source minimaux nécessaires, la cible `ILIAS_SOURCE`, `read_only=true`, la commande d'extraction attendue et le chemin du manifest à produire. Le worker reconstruit lui-même les commandes à partir de champs validés : il n'exécute jamais une chaîne de commande arbitraire fournie par le plan.

Le transport entre Moodle et le serveur ILIAS reste volontairement hors de ce worker. Aucun mot de passe SSH, clé privée ou secret ILIAS n'est stocké dans le plugin Moodle. Le worker de recovery doit être exécuté localement sur le serveur ILIAS, ou par un mécanisme d'orchestration d'infrastructure explicitement approuvé.

Le test réel du cours `282` a produit deux demandes IRSS pour l'Exercise `356` :

```text
496f99f9-f8c3-48ab-9714-a6a54886877e
5cf327b1-4b20-42f7-8ee0-7c96c449d210
```

### Qualification V1.1 complète — 10 octobre 2026

La chaîne complète a été qualifiée sur :

- ILIAS `10.8.0` ;
- cours `obj_id=827 / ref_id=282`, `Cours test RC 0.20` ;
- Moodle `5.0.2` ;
- Python `3.11.13` ;
- package final `course827_v13_ui`.

La préparation finale a retourné `missing_count=0` et a réinjecté deux collections IRSS vides valides. La question Kprim réelle `external_id=6214d3a5600970.08414694` a été normalisée avec `type=kprim`, `max_score=1` et 16 combinaisons.

Les trois Item Groups du cours ont été enrichis depuis l'export natif :

- `349 / obj_id=996` — Module de formation ;
- `350 / obj_id=997` — Médias ;
- `355 / obj_id=1004` — Informations.

Le dry-run Item Group a retourné `blocked_item_groups=0`, `root_structure_ready=true` et `apply_ready=true`.

Le run opérateur final a terminé toutes les familles applicables en `SUCCESS`. Les étapes Glossaires et Forums ont été `SKIPPED` car aucun objet correspondant n'était présent dans `migration.json`. Un contrôle fonctionnel final dans Moodle a été réalisé avec succès.

La qualification a également validé le scénario `suppression du cours cible -> mappings ORPHANED -> snapshot d'audit -> reset -> nouvelle migration`, y compris les mappings legacy avec `sourceinstance=''`.


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

## Intégrité du package pendant un run

Lors de la création d'un run, la console enregistre le SHA-256 de `migration.json`.

Avant chaque étape, la console recalcule ce hash. Si le fichier a été modifié, supprimé ou remplacé pendant la migration, l'étape est arrêtée avec :

```text
SOURCE_CHANGED_DURING_RUN
```

Le run ne doit pas continuer sur un document source mutable, car cela rendrait le journal et le rapport non reproductibles.

Après restauration exacte du `migration.json` d'origine, l'opérateur peut utiliser **Réessayer** sur l'étape en erreur.

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

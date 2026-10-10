# Transfert sécurisé des bundles de recovery ILIAS vers Moodle

## Objectif

Automatiser le dernier maillon manuel du workflow recovery sans stocker de secret dans Moodle.

Le principe est un push SSH depuis le serveur ILIAS vers Moodle :

~~~
[SRV ILIAS]
run-recovery-plan.py
  -> recovery local
  -> bundle .tar.gz
  -> SSH avec clé privée dédiée
  -> [SRV MOODLE]
  -> clé publique forcée
  -> receive-recovery-bundle.sh
  -> /var/moodledata/ilias2moodle/recovery/bundles/
  -> Console opérateur
~~~

La clé privée reste uniquement sur le serveur ILIAS. La clé publique installée sur Moodle est limitée par authorized_keys avec restrict et une commande forcée. Cette clé ne donne pas accès à un shell libre, à SCP/SFTP libre, au forwarding ou à un PTY.

## Composants

- src/ilias2moodle/recovery_transport.py : construit, contrôle et publie le bundle.
- tools/publish-recovery-bundle.py : publie un bundle déjà existant.
- tools/run-recovery-plan.py : peut exécuter le recovery, créer le bundle et le publier en une seule commande.
- tools/receive-recovery-bundle.sh : commande forcée côté Moodle ; valide nom, taille et SHA-256 avant dépôt atomique.

## Préparation Moodle

### [SRV MOODLE]

Créer le compte dédié :

~~~bash
getent passwd ilias2moodlepush >/dev/null || \
  useradd \
    --system \
    --create-home \
    --home-dir /home/ilias2moodlepush \
    --shell /bin/bash \
    ilias2moodlepush

passwd -l ilias2moodlepush
usermod -aG apache ilias2moodlepush

restorecon -Rv /home/ilias2moodlepush
~~~

Préparer le répertoire de bundles :

~~~bash
mkdir -p /var/moodledata/ilias2moodle/recovery/bundles

chown root:apache \
  /var/moodledata/ilias2moodle/recovery/bundles

chmod 2770 \
  /var/moodledata/ilias2moodle/recovery/bundles
~~~

Préparer authorized_keys :

~~~bash
install -d \
  -o ilias2moodlepush \
  -g ilias2moodlepush \
  -m 0700 \
  /home/ilias2moodlepush/.ssh

touch /home/ilias2moodlepush/.ssh/authorized_keys

chown ilias2moodlepush:ilias2moodlepush \
  /home/ilias2moodlepush/.ssh/authorized_keys

chmod 0600 \
  /home/ilias2moodlepush/.ssh/authorized_keys
~~~

## Génération de la clé côté ILIAS

### [SRV ILIAS]

~~~bash
install -d -o root -g root -m 0700 /etc/ilias2moodle

ssh-keygen \
  -t ed25519 \
  -f /etc/ilias2moodle/recovery_push_ed25519 \
  -N '' \
  -C 'ilias2moodle recovery push'

chmod 0600 \
  /etc/ilias2moodle/recovery_push_ed25519

cat /etc/ilias2moodle/recovery_push_ed25519.pub
~~~

La clé privée ne doit jamais quitter ILIAS.

## Installation de la clé publique forcée

### [SRV MOODLE]

Recopier uniquement la clé publique affichée sur ILIAS dans authorized_keys, précédée des options suivantes :

~~~text
restrict,command="/bin/bash /opt/ilias2moodle/tools/receive-recovery-bundle.sh /var/moodledata/ilias2moodle/recovery/bundles /var/moodledata/ilias2moodle/packages" ssh-ed25519 AAAA... ilias2moodle recovery push
~~~

Le mot-clé restrict désactive notamment le forwarding, l'agent, X11 et le PTY. La commande forcée ignore toute tentative d'exécuter une autre commande distante. Les deux répertoires sont fixés côté Moodle : le premier reçoit les bundles, le second expose uniquement le fichier recovery-plan.json d'un package dont le nom a été validé.

## Épinglage de la clé hôte Moodle

### [SRV MOODLE]

~~~bash
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
~~~

### [SRV ILIAS]

~~~bash
ssh-keyscan \
  -t ed25519 \
  192.168.56.54 \
  > /etc/ilias2moodle/moodle_known_hosts

chmod 0644 /etc/ilias2moodle/moodle_known_hosts

ssh-keygen -lf /etc/ilias2moodle/moodle_known_hosts
~~~

Comparer l'empreinte avec celle affichée directement sur Moodle avant de poursuivre.

## Test avec un bundle existant

### [SRV ILIAS]

~~~bash
cd /opt/ilias2moodle

python3.11 tools/publish-recovery-bundle.py \
  --bundle=/tmp/course827_v14_recovery.tar.gz \
  --host=192.168.56.54 \
  --user=ilias2moodlepush \
  --identity=/etc/ilias2moodle/recovery_push_ed25519 \
  --known-hosts=/etc/ilias2moodle/moodle_known_hosts
~~~

Le JSON attendu contient success=true et published=true.

### [SRV MOODLE]

~~~bash
ls -lh \
  /var/moodledata/ilias2moodle/recovery/bundles/
~~~

La console opérateur doit proposer automatiquement le bundle reçu.

## Recovery + bundle + publication en une commande

Le mode historique accepte toujours un plan local avec --plan.

Le mode complètement intégré utilise --fetch-package : ILIAS récupère d'abord le recovery-plan.json directement dans le package Moodle, vérifie taille, SHA-256, JSON et schema_version, exécute le recovery puis renvoie le bundle.

### [SRV ILIAS]

~~~bash
cd /opt/ilias2moodle

python3.11 tools/run-recovery-plan.py \
  --fetch-package=course827_v18_fullauto \
  --output=/tmp/course827_v18_recovery \
  --ilias-root=/var/www/ilias \
  --client=ilias10 \
  --publish-host=192.168.56.54 \
  --publish-user=ilias2moodlepush \
  --publish-identity=/etc/ilias2moodle/recovery_push_ed25519 \
  --publish-known-hosts=/etc/ilias2moodle/moodle_known_hosts
~~~

Sans --bundle explicite, le bundle est créé automatiquement à côté du répertoire output sous le nom <output>.tar.gz.

## Garanties de sécurité

- nom distant limité à un nom simple .tar.gz/.tgz ;
- taille maximale : 512 MiB ;
- taille annoncée vérifiée côté Moodle ;
- SHA-256 annoncé vérifié côté Moodle ;
- écriture temporaire puis renommage atomique ;
- aucun chemin distant fourni par ILIAS ;
- lecture du plan limitée à packages/<nom-validé>/recovery-plan.json ;
- taille maximale du plan : 2 MiB ;
- SHA-256, taille, UTF-8, JSON et schema_version du plan revalidés sur ILIAS ;
- aucune commande shell arbitraire transmise ;
- aucune clé privée stockée dans Moodle ;
- vérification stricte de la clé hôte Moodle ;
- la console Moodle revalide ensuite l'archive et les manifests avant re-préparation.

Le transport ne remplace pas les contrôles de la console : il les précède.

## Worker périodique ILIAS — beta4

La beta4 ajoute `tools/recovery-worker.py`. Le worker utilise la même clé privée dédiée et le même canal forced-command.

Le protocole ajoute l'action `list-pending`. Moodle ne renvoie que les packages explicitement placés dans `recoveriesroot/requests` par la console et dont :
- le SHA-256 du plan correspond toujours au marqueur de queue ;
- `recovery_required=true` ;
- la liste `requests` est non vide ;
- `unresolved_count=0` ;
- aucun bundle déterministe `<package>_recovery.tar.gz` n'est déjà présent.

Le worker garde un état local dans `/var/lib/ilias2moodle-recovery-worker`. Un plan ayant échoué n'est pas relancé à chaque minute tant que son SHA-256 ne change pas ; un retry explicite reste possible avec `--retry-failed`.

### Installation systemd sur [SRV ILIAS]

Copier l'exemple d'environnement :

~~~bash
install -d -o root -g root -m 0700 /etc/ilias2moodle

cp \
  /opt/ilias2moodle/deploy/systemd/recovery-worker.env.example \
  /etc/ilias2moodle/recovery-worker.env

chmod 0600 /etc/ilias2moodle/recovery-worker.env
~~~

Adapter au minimum `MOODLE_HOST` et `ILIAS_CLIENT_ID`.

Installer les unités :

~~~bash
cp \
  /opt/ilias2moodle/deploy/systemd/ilias2moodle-recovery-worker.service \
  /etc/systemd/system/

cp \
  /opt/ilias2moodle/deploy/systemd/ilias2moodle-recovery-worker.timer \
  /etc/systemd/system/

systemctl daemon-reload
~~~

Avant d'activer le timer, exécuter une qualification manuelle :

~~~bash
systemctl start ilias2moodle-recovery-worker.service
systemctl status ilias2moodle-recovery-worker.service --no-pager
journalctl -u ilias2moodle-recovery-worker.service -n 100 --no-pager
~~~

Après qualification :

~~~bash
systemctl enable --now ilias2moodle-recovery-worker.timer
systemctl list-timers --all | grep ilias2moodle-recovery
~~~

Le timer lance le worker environ une fois par minute et le verrou local empêche deux exécutions simultanées.


## Dernière étape : re-préparation automatique Moodle — beta5

Une fois le bundle publié par le worker ILIAS, la beta5 ne nécessite plus de clic opérateur.

La tâche planifiée Moodle :

~~~text
\local_iliasmigration\task\recovery_reprepare_task
~~~

s'exécute chaque minute via le cron Moodle. Elle rapproche la queue `recoveriesroot/requests` et les bundles `recoveriesroot/bundles`, puis applique automatiquement le bundle correspondant avec les mêmes contrôles que l'import manuel.

Après succès :
- le package est re-préparé atomiquement ;
- le marqueur de queue est supprimé si `missing_count=0`, ou renouvelé si un autre recovery reste nécessaire ;
- le bundle consommé est déplacé dans `recoveriesroot/processed` ;
- l'état d'exécution est conservé dans `recoveriesroot/reprepare-state`.

Pour qualifier la tâche sans attendre le cron :

~~~bash
cd /var/www/moodle

runuser -u apache -- php admin/cli/scheduled_task.php \
  --execute='\local_iliasmigration\task\recovery_reprepare_task'
~~~

Le flux cible complet devient donc :

~~~text
Console Moodle
  -> prepare-export
  -> queue
  -> timer systemd ILIAS
  -> list-pending
  -> fetch-plan
  -> recovery read-only
  -> bundle
  -> publish
  -> tâche cron Moodle
  -> reprepare atomique
  -> package prêt à migrer
~~~


## Qualification finale V20

Le scénario V20 valide en exploitation réelle la chaîne automatique complète avec les deux ordonnanceurs actifs :

- timer systemd côté ILIAS ;
- cron Moodle exécuté chaque minute sous le compte `apache`.

Le worker ILIAS a traité `course827_v20_beta6` avec `processed_count=1`, `failed_count=0` et deux requests recovery. Le package Moodle était déjà passé à `missing_count=0` au moment du contrôle, preuve que la tâche cron Moodle avait consommé le bundle automatiquement.

Le `migration.json` V20, normalisé par suppression de `generated_at`, est strictement identique aux références V13 et V15 :

~~~text
fc1d8073abb9bdd43d36f5020aaa3e2c1898ed1d4af99630cf8ada739d97a9fb
~~~

La chaîne recovery ne nécessite donc plus de transfert manuel ni de déclenchement manuel dans le scénario nominal.

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
    --home-dir /var/lib/ilias2moodlepush \
    --shell /bin/bash \
    ilias2moodlepush

passwd -l ilias2moodlepush
usermod -aG apache ilias2moodlepush
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
  /var/lib/ilias2moodlepush/.ssh

touch /var/lib/ilias2moodlepush/.ssh/authorized_keys

chown ilias2moodlepush:ilias2moodlepush \
  /var/lib/ilias2moodlepush/.ssh/authorized_keys

chmod 0600 \
  /var/lib/ilias2moodlepush/.ssh/authorized_keys
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
restrict,command="/bin/bash /opt/ilias2moodle/tools/receive-recovery-bundle.sh /var/moodledata/ilias2moodle/recovery/bundles" ssh-ed25519 AAAA... ilias2moodle recovery push
~~~

Le mot-clé restrict désactive notamment le forwarding, l'agent, X11 et le PTY. La commande forcée ignore toute tentative d'exécuter une autre commande distante.

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

### [SRV ILIAS]

~~~bash
cd /opt/ilias2moodle

python3.11 tools/run-recovery-plan.py \
  --plan=/tmp/course827_v14-recovery-plan.json \
  --output=/tmp/course827_v17_recovery \
  --ilias-root=/var/www/ilias \
  --client=ilias10 \
  --bundle=/tmp/course827_v17_recovery.tar.gz \
  --publish-host=192.168.56.54 \
  --publish-user=ilias2moodlepush \
  --publish-identity=/etc/ilias2moodle/recovery_push_ed25519 \
  --publish-known-hosts=/etc/ilias2moodle/moodle_known_hosts
~~~

## Garanties de sécurité

- nom distant limité à un nom simple .tar.gz/.tgz ;
- taille maximale : 512 MiB ;
- taille annoncée vérifiée côté Moodle ;
- SHA-256 annoncé vérifié côté Moodle ;
- écriture temporaire puis renommage atomique ;
- aucun chemin distant fourni par ILIAS ;
- aucune commande shell arbitraire transmise ;
- aucune clé privée stockée dans Moodle ;
- vérification stricte de la clé hôte Moodle ;
- la console Moodle revalide ensuite l'archive et les manifests avant re-préparation.

Le transport ne remplace pas les contrôles beta3 : il les précède.

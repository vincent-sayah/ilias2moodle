# Phase 1 — Première collecte sur ILIAS 10

Cette étape sert à déterminer la meilleure méthode d'extraction de l'instance ILIAS 10 **sans modifier les données**.

## 1. Choisir un cours POC

Choisir un cours ILIAS représentatif avec, si possible :

- une page d'accueil ;
- un dossier et un sous-dossier ;
- un PDF ou autre fichier ;
- une URL ;
- une image ou vidéo ;
- un SCORM ;
- un module d'apprentissage ILIAS ;
- un test avec quelques questions.

Relever :

- le titre du cours ;
- l'URL complète du cours ;
- son `ref_id`.

Le `ref_id` est généralement visible dans l'URL ILIAS, par exemple sous la forme `ref_id=1234` ou dans une cible de type `crs_1234`.

## 2. Relever la version exacte

Noter la version ILIAS exacte (10.x) visible dans l'administration ou dans les informations système de l'instance.

Ne jamais transmettre de fichier de configuration complet contenant des secrets.

## 3. Tester l'accès SOAP/WSDL

Depuis une machine ayant accès à l'URL ILIAS :

```bash
git clone https://github.com/vincent-sayah/ilias2moodle.git
cd ilias2moodle
chmod +x tools/diagnose-ilias.sh
./tools/diagnose-ilias.sh https://URL-DE-VOTRE-ILIAS
```

Le script teste uniquement des lectures HTTP et ne modifie pas ILIAS.

Il vérifie les deux formes d'URL courantes :

```text
/soap/server.php?wsdl
/public/soap/server.php?wsdl
```

Conserver la sortie complète du script.

## 4. Tester un export manuel du cours

Dans ILIAS, ouvrir le cours POC et vérifier si un onglet/action **Export** est disponible.

Si possible, produire l'export XML/ZIP natif du cours sans supprimer ni modifier aucun objet.

Conserver le ZIP tel quel : il servira de jeu de données de référence pour développer le premier parseur ILIAS2Moodle.

## 5. Compte technique

Ne pas créer de compte technique avant d'avoir vérifié les services disponibles.

Si l'automatisation par SOAP/API est retenue, créer ensuite un compte dédié avec uniquement les droits de lecture/export nécessaires sur le cours POC. Ne jamais versionner son mot de passe dans Git.

## 6. Informations nécessaires pour poursuivre

À la fin de cette étape, il faut disposer de :

1. version ILIAS exacte ;
2. URL de base ILIAS ;
3. URL et `ref_id` du cours POC ;
4. sortie de `tools/diagnose-ilias.sh` ;
5. export XML/ZIP du cours POC si disponible ;
6. indication sur l'accès shell au serveur ILIAS (oui/non) ;
7. indication sur la possibilité de créer un compte technique dédié (oui/non).

Aucun mot de passe, token, secret de base de données ou `config.json` complet ne doit être partagé ni commité.

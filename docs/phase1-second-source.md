# Phase 1 — Deuxième serveur source ILIAS

Validation effectuée le 4 septembre 2026 sur un nouveau serveur ILIAS accessible à l'adresse `http://192.168.56.50`.

## Environnement

- ILIAS : 10.8
- PHP CLI : 8.2.30
- Extension PHP SOAP : présente
- Python système : 3.6.8 conservé
- Python ILIAS2Moodle : 3.11.13
- WSDL SOAP : `http://192.168.56.50/soap/server.php?wsdl`
- HTTP : 200
- endpoint `/public/soap/server.php?wsdl` : 404, non utilisé

Le WSDL expose notamment `login`, `getXMLTree`, `getTreeChilds`, `getCourseXML`, `getObjectByReference`, `getStructureObjects` et `getIMSManifestXML`.

## Cours POC

- URL : `http://192.168.56.50/ilias.php?...&ref_id=128`
- `ref_id` : `128`
- export : `1788537956__0__crs_504.zip`
- titre : `cours test migration`

Analyse réelle :

- 7 objets
- 2 fichiers
- 1 dossier
- 1 module HTML
- 1 URL
- 1 banque de questions
- 1 test
- 0 objet non supporté

Préparation du package :

- 2 fichiers extraits
- 1 média extrait
- 1 fichier de module HTML extrait
- 2 fichiers de test extraits
- 2 fichiers de banque de questions extraits (`qpl` + `qti`)
- 0 package SCORM dans ce cours
- `missing_count=0`

Point important : contrairement au premier POC ILIAS 10.5, cet export de cours ILIAS 10.8 contient bien les données de la banque de questions. Il fournit donc un jeu de référence utile pour la Phase 6.

## Attention aux URL dans Bash

Une URL ILIAS contenant des `&` doit être entourée de quotes simples :

```bash
./tools/diagnose-ilias.sh 'http://192.168.56.50/ilias.php?baseClass=ilrepositorygui&cmd=view&ref_id=128'
```

Sinon Bash interprète les fragments après `&` comme des commandes séparées / jobs en arrière-plan.

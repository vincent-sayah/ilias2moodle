# Phase 1 — Deuxième serveur source ILIAS

Validation effectuée le 4 septembre 2026 sur un nouveau serveur ILIAS accessible à l'adresse `http://192.168.56.50`.

## Cours POC

- URL : `http://192.168.56.50/ilias.php?...&ref_id=128`
- `ref_id` : `128`

## Diagnostic

- PHP CLI : 8.2.30
- Extension PHP SOAP : présente
- Python système : 3.6.8
- Python 3.11+ : à installer en parallèle avant d'exécuter ILIAS2Moodle
- WSDL SOAP : `http://192.168.56.50/soap/server.php?wsdl`
- HTTP : 200
- endpoint `/public/soap/server.php?wsdl` : 404, non utilisé

Le WSDL expose notamment `login`, `getXMLTree`, `getTreeChilds`, `getCourseXML`, `getObjectByReference`, `getStructureObjects` et `getIMSManifestXML`.

## Attention aux URL dans Bash

Une URL ILIAS contenant des `&` doit être entourée de quotes simples :

```bash
./tools/diagnose-ilias.sh 'http://192.168.56.50/ilias.php?baseClass=ilrepositorygui&cmd=view&ref_id=128'
```

Sinon Bash interprète les fragments après `&` comme des commandes séparées / jobs en arrière-plan.

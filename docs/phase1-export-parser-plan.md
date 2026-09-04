# Phase 1 — Parseur d'export ILIAS

Objectif immédiat : permettre à `ilias2moodle` de lire directement un export XML/ZIP ILIAS 10.5 et de générer `migration.json` ainsi qu'un rapport de compatibilité.

Entrée prévue :

```bash
ilias2moodle analyse-export --zip /chemin/export-course.zip --output ./exports/course-31250
```

La première version devra extraire :

- manifest racine ;
- arborescence `Container` ;
- RefId / ObjId / types / titres ;
- fichiers ;
- WebResources ;
- MediaObjects et COPage ;
- SCORM ;
- HTML Learning Module ;
- présence et métadonnées des tests et pools de questions.

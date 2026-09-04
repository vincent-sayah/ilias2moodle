# Analyse du POC ILIAS 10.5

Cours de référence : **Test migration** (`ref_id=31250`)

Version source : **ILIAS 10.5**

## Résultats confirmés dans l'export XML

L'export du cours contient un `manifest.xml` racine listant les objets exportés et un composant `ILIAS/Container` contenant l'arborescence complète avec les `RefId` et `ObjId`.

Arborescence détectée :

```text
Cours Test migration (ref_id 31250 / obj_id 268823)
├── Dossier test (31251 / 268827)
│   ├── sous dossier (31252 / 268829)
│   │   └── cours-algorithmie.pdf (31253 / 268830)
│   ├── HDVS - test [SCORM] (31257 / 268834)
│   └── TEST HTML5 - test [module HTML] (31258 / 268835)
├── lien vers youtube [WebResource] (31254 / 268831)
├── bdq_sayah [Question Pool] (31255 / 268832)
└── test math [Test] (31256 / 268833)
```

Le contenu de page du cours contient également une image `banniere_cimmedia.png` référencée comme MediaObject `268828`, ainsi qu'un titre `Bienvenue dans la formation`.

## Données exploitables sans SOAP

L'export contient déjà suffisamment d'informations pour construire la première version du parseur :

- arborescence et ordre des objets ;
- `ref_id` ILIAS ;
- `obj_id` ILIAS ;
- type ILIAS (`crs`, `fold`, `file`, `sahs`, `htlm`, `webr`, `qpl`, `tst`) ;
- titres ;
- fichiers binaires ;
- URL des WebResources ;
- contenu de page du cours ;
- MediaObjects ;
- package SCORM `content.zip` et propriétés SCORM ;
- contenu du module HTML ;
- QTI du test et ordre des questions.

## Limite détectée

Dans cet export de cours, le composant de la banque de questions `qpl` ne contient pas les questions elles-mêmes. Le test exporté contient en revanche son fichier QTI avec ses questions.

La migration complète d'une banque de questions sera donc testée avec un **export séparé de la banque de questions** pendant la Phase 6.

## Décision Phase 1

La première implémentation du connecteur ILIAS reposera sur les **exports natifs XML/ZIP ILIAS**, car ils permettent déjà une extraction structurée, reproductible et non intrusive.

SOAP reste une piste complémentaire pour automatiser la découverte et le déclenchement d'exports, mais il n'est pas requis pour construire le premier parseur.

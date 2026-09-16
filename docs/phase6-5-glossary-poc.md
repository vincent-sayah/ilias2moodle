# Phase 6.5.2 — POC Glossaire ILIAS

Issue : #15  
Branche : `phase6-5-glossary`

## Export réel analysé

Cours ILIAS 10.8 de référence :

- cours : `ref_id=128`, `obj_id=504` ;
- export : `/vagrant/1789580035__0__crs_504.zip` ;
- Glossaire : `obj_id=797` ;
- type ILIAS : `glo` ;
- export set : `set_29/1789580035__0__glo_797` ;
- titre exporté : `glossaire test migration`.

Le `ref_id` du Glossaire sera rapproché du document de migration par le parseur de cours, comme pour les autres objets : le parser Glossaire isolé travaille d'abord par `obj_id`.

## Composants présents

Le manifeste propre au Glossaire référence :

- `ILIAS/Glossary` ;
- `ILIAS/MediaObjects` ;
- `ILIAS/MetaData` ;
- `ILIAS/File` ;
- `ILIAS/ILIASObject` ;
- `ILIAS/COPage` ;
- `ILIAS/Style`.

Aucun composant `Taxonomy` n'est présent dans ce POC.

## Métadonnées Glossary

Le composant `Glossary/set_0/export.xml` contient :

- `Id=797` ;
- `Virtual=none` ;
- `PresMode=table` ;
- `SnippetLength=200` ;
- `ShowTax=0` ;
- `GloMenuActive=y`.

La description est vide dans l'export réel.

## Termes du POC

Deux termes sont présents :

| Term ID | Terme | Langue |
|---:|---|---|
| 6 | Chat | fr |
| 7 | chien | fr |

Les définitions ne sont pas stockées dans le dataset `Glossary`. Elles sont exportées comme pages COPage :

- `term:6` → définition de `Chat` ;
- `term:7` → définition de `chien`.

### Chat

Blocs observés :

1. paragraphe `ceci est un chat` ;
2. média `il_0_mob_798`.

### chien

Blocs observés :

1. paragraphe `Ceci est un <Strong><Emph>chien</Emph></Strong>` ;
2. média `il_0_mob_799`.

Le moteur Page Editor est donc réutilisable pour préserver les définitions riches.

## Médias

Deux MediaObjects sont exportés :

- `mob 798` → `chat.jpg`, `image/jpeg` ;
- `mob 799` → `chien.png`, `image/png`.

Les fichiers physiques sont stockés sous `components/ILIAS/MediaObjects/set_0/expDir_*/dsDir_1/`.

## Fichiers joints

Le composant `ILIAS/File/set_0/export.xml` est présent mais vide dans ce POC. Aucune pièce jointe n'est donc validée à ce stade.

## Taxonomie, catégories et alias

Ce POC réel ne valide pas encore ces fonctions :

- `ShowTax=0` ;
- aucun composant `Taxonomy` dans l'export ;
- aucun alias/synonyme observé dans le dataset Glossary ;
- aucune catégorie/taxonomie exportée.

Ces fonctions ne doivent pas être inventées dans la transformation Moodle. Elles seront ajoutées uniquement après observation d'un export réel qui les contient.

## Première représentation neutre retenue

Le parser isolé produit :

- métadonnées du Glossaire ;
- paramètres utiles ;
- liste ordonnée des termes ;
- pour chaque terme, sa définition COPage sous forme de blocs neutres ;
- dictionnaire des MediaObjects ;
- dictionnaire des File objects lorsqu'ils existent ;
- liste explicite des composants Page Editor non supportés ;
- état de présence/activation de la taxonomie.

## Étapes suivantes

1. Exécuter `tools/inspect-glossary.py` sur le ZIP réel.
2. Vérifier que le résultat reproduit exactement les deux termes, les deux définitions et les deux médias.
3. Enrichir le POC ILIAS si une validation des taxonomies, catégories, alias ou pièces jointes est souhaitée.
4. Après validation du parser réel, intégrer le Glossaire à `prepare-export` et au package neutre.
5. Ajouter ensuite le dry-run Moodle et seulement après l'apply `mod_glossary`.

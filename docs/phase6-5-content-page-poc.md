# Phase 6.5.1 — POC Content Page

## Objet source

- cours ILIAS : `ref_id=128`, `obj_id=504` ;
- Content Page : `ref_id=270`, `obj_id=789` ;
- type ILIAS : `copa` ;
- titre : `Page test migration` ;
- description : `Page de contenu de validation pour la migration ILIAS vers Moodle.`.

## Contrat d’export observé

L’objet est exporté dans un `ExportSet` de type `copa` et fournit les composants :

- `ContentPage` : métadonnées principales ;
- `COPage` : contenu Page Editor ;
- `MediaObjects` : médias incorporés ;
- `File` : fichiers incorporés ;
- `MetaData` ;
- `ILIASObject`.

## Contenu réel du POC

Le `COPage` contient notamment :

- paragraphes standards et titre `Headline1` ;
- mise en forme `Strong` ;
- lien externe `ExtLink` ;
- lien interne `IntLink` ;
- média ;
- liste de fichiers ;
- tableau ;
- section ;
- onglets `Tabs` ;
- grille `Grid` avec plusieurs cellules média.

Lien interne réel :

```text
il_0_htlm_518_134
```

Il correspond au `ref_id=134` et devra être réécrit vers la cible Moodle existante lorsque le mapping est unique et valide.

Médias observés : `790`, `793`, `794`, `795`.

Fichiers observés :

- `791` — DOCX ;
- `792` — PDF.

## Politique Phase 6.5.1

La Content Page sera transformée en `mod_page`.

La représentation neutre doit préserver les informations éditoriales utiles et ne doit pas réduire les paragraphes à du texte brut. Les liens, mises en forme, sauts de ligne, médias, fichiers et tableaux doivent être conservés.

Les composants ILIAS sans équivalent Moodle interactif direct, notamment `Tabs` et `Grid`, seront convertis en HTML statique déterministe. Ils ne doivent pas être supprimés silencieusement.

## Validation progressive

1. parser le ZIP réel avec l’outil `tools/inspect-content-page.py` ;
2. comparer le JSON produit au POC ILIAS ;
3. intégrer le parser validé dans `prepare-export` ;
4. extraire médias/fichiers dans le package normalisé ;
5. ajouter le plan/dry-run Moodle ;
6. créer ou mettre à jour `mod_page` ;
7. réécrire les liens internes ;
8. contrôler visuellement ;
9. rejouer en UPDATE sans doublon ;
10. fusionner uniquement après validation réelle.

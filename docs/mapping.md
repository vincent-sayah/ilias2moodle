# Mapping ILIAS 10 → Moodle 4.5

Cette matrice constitue le contrat fonctionnel de la migration. Les mappings sont validés progressivement sur le cours POC réel ILIAS 10.8 → Moodle 5.0.2, tout en conservant une compatibilité minimale du plugin avec Moodle 4.5.

| Type ILIAS | Code indicatif ILIAS | Cible Moodle | Phase | Statut |
|---|---|---|---:|---|
| Catégorie | `cat` | Catégorie | 2 | validé |
| Cours | `crs` | Cours | 2 | validé |
| Dossier | `fold` | Section / Sous-section | 2 | validé |
| Fichier | `file` | `mod_resource` | 3 | validé |
| URL | `webr` | `mod_url` | 3 | validé |
| Module HTML | `htlm` | `mod_resource` | 3 | validé |
| SCORM | `sahs` | `mod_scorm` | 4 | validé |
| Learning Module ILIAS | `lm` | `mod_book` | 5 | validé |
| Test | `tst` | `mod_quiz` | 6 | validé |
| Question pool | `qpl` | Banque de questions / `qbank` | 6 | validé |
| Content Page | `copa` | `mod_page` | 6.5 | validé — #14 |
| Glossaire | `glo` | `mod_glossary` | 6.5 | validé — #15 |
| Wiki | `wiki` | `mod_wiki` | 6.5 | validé — #16 |
| Exercice | `exc` | `mod_assign` | 6.5 | validé — #17 |
| Forum | `frm` | `mod_forum` | 6.5 | validé structure — #18 ; contributions Phase 7 |
| Mediacast | `mcst` | `mod_data` | 6.5 | validé — #19 ; MP4 local + URL externe |
| Blog | `blog`* | `mod_data` privilégié | 6.5 | étude/POC — #20 |
| Media Pool / galerie média | `mep`* / selon export | ressources, `mod_data` ou `mod_page` | 6.5 | étude/POC — #21 |
| Groupe | `grp`* | Groupe / Groupement + structure/restrictions si nécessaire | 7 | planifié — #7 |
| Learning Progress | — | Completion / historique | 7 | complexe |

\* Les codes encore marqués d’un astérisque restent indicatifs tant qu’ils n’ont pas été confirmés sur leur POC réel.

## Règle pour les dossiers

Un dossier ILIAS n’est pas systématiquement converti en ressource « Folder » Moodle.

- dossier structurant un cours → section ou sous-section ;
- dossier servant uniquement de dépôt de fichiers → ressource Folder possible ;
- cas ambigu → signalé dans le rapport.

## Liens internes

La Phase 3 valide désormais la réécriture des liens internes ILIAS lorsqu’une cible Moodle unique et sûre existe.

```text
ILIAS type|ref_id
      ↓
mapping persistant
      ↓
Moodle component + course_module id
```

Si aucune cible Moodle n’existe encore, ou si plusieurs cibles distinctes sont possibles, le lien permanent ILIAS est conservé comme fallback au lieu de produire une réécriture approximative.

## Phase 6.5 — extension des objets pédagogiques

La Phase 6.5 est suivie par l’issue #13 et par les tickets #14 à #21.

Ordre de développement retenu :

1. Content Page → `mod_page` ;
2. Glossaire → `mod_glossary` ;
3. Wiki → `mod_wiki` ;
4. Exercice → `mod_assign` ;
5. Forum → `mod_forum` ;
6. Mediacast → `mod_data` ;
7. Blog → `mod_data` privilégié ;
8. Media Pool / galerie média → cible à figer après POC.

Pour les objets dépendants des identités utilisateurs, la structure pédagogique peut être traitée en Phase 6.5, tandis que les auteurs, membres, remises, notes ou contributions sont reportés à la Phase 7 lorsqu’un rapprochement utilisateur fiable est nécessaire. C’est la politique validée pour le Forum : le `mod_forum` est créé/mis à jour en Phase 6.5.5, tandis que les threads, posts et assets de contributions restent conservés dans le package jusqu’au rapprochement des auteurs.

Pour le Mediacast, le mapping validé est `mcst` → `mod_data` : un `mod_data` par Mediacast et un record par entrée. Le périmètre POC validé couvre `video/mp4` local et les références externes HTTP/HTTPS. Les MP4 absents du ZIP natif peuvent être récupérés en lecture seule via MediaObjects/IRSS, puis contrôlés par taille et SHA-256 avant packaging. Le fichier est stocké via Moodle Files API et rendu dans un lecteur HTML5 ; les previews restent conservées dans le package sans être injectées dans Moodle.

## Objet Groupe

L’objet ILIAS Groupe reste volontairement en Phase 7. Sa migration complète peut nécessiter plusieurs éléments Moodle :

```text
ILIAS Group
   ↓
Moodle Group / Grouping
+
structure de cours éventuelle
+
restriction d’accès
+
membres rapprochés
```

Le contenu éventuel du groupe pourra être analysé lors de la Phase 6.5, mais le rattachement des membres et la sémantique de groupe restent pilotés par l’issue #7.

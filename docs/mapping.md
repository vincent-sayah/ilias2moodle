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
| Blog | `blog` | `mod_data` | 6.5 | validé — #20 ; billets + images, auteurs Moodle Phase 7 |
| Media Pool / galerie média | `mep` | `mod_data` | 6.5 | validé — #21 ; images + MP4 + COPage + dossiers |
| Groupe | `grp` | Non migré automatiquement pour l’instant ; nécessite conteneur + membres + restrictions + contenus | 7 | différé — #22 |
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
7. Blog → `mod_data` ;
8. Media Pool / galerie média → `mod_data`.

Pour les objets dépendants des identités utilisateurs, la structure pédagogique peut être traitée en Phase 6.5, tandis que les auteurs, membres, remises, notes ou contributions sont reportés à la Phase 7 lorsqu’un rapprochement utilisateur fiable est nécessaire. C’est la politique validée pour le Forum : le `mod_forum` est créé/mis à jour en Phase 6.5.5, tandis que les threads, posts et assets de contributions restent conservés dans le package jusqu’au rapprochement des auteurs.

Pour le Mediacast, le mapping validé est `mcst` → `mod_data` : un `mod_data` par Mediacast et un record par entrée. Le périmètre POC validé couvre `video/mp4` local et les références externes HTTP/HTTPS. Les MP4 absents du ZIP natif peuvent être récupérés en lecture seule via MediaObjects/IRSS, puis contrôlés par taille et SHA-256 avant packaging. Le fichier est stocké via Moodle Files API et rendu dans un lecteur HTML5 ; les previews restent conservées dans le package sans être injectées dans Moodle.

Pour le Blog, le mapping validé est `blog` → `mod_data` : un `mod_data` par Blog et un record par billet. Le contenu riche est reconstruit depuis les COPage `blp:<posting_id>`, avec conservation des paragraphes, grilles et images locales validées sur le POC. Les images sont stockées via Moodle Files API dans la zone `mod_data/content`. L’identifiant auteur ILIAS est conservé dans le champ `source_author` ; l’attribution à un utilisateur Moodle est reportée à la Phase 7. Le second apply met à jour le même CMID et les mêmes records sans doublon.

Pour le Media Pool, le mapping validé est `mep` → `mod_data` : un `mod_data` par Media Pool et un record par nœud de contenu `mob` ou `pg`. Les nœuds `dummy` sont ignorés, les dossiers sont conservés sous forme de `folder_path`, les contenus COPage sont rendus dans le record, et les originaux image/MP4 sont stockés via Moodle Files API dans `mod_data/content`. Les previews `mob_vpreview.png` ne sont pas importées. Le POC réel valide PNG + MP4 ; l’audio reste non déclaré supporté faute de POC réel audio.

## Objet Groupe

Le type ILIAS Groupe est confirmé : `grp`.

La migration automatique de cet objet est **différée**. Un objet ILIAS Groupe est un conteneur de dépôt à part entière : il possède des membres et peut contenir des ressources/activités visibles dans son propre contexte. Un Moodle Group ne représente qu'un ensemble de participants et ne constitue donc pas un équivalent fonctionnel complet.

Le POC `obj_id=743 / ref_id=254` ne contient aucun objet enfant. Il a permis de valider techniquement la lecture des memberships, mais la création d'un simple Moodle Group n'est plus considérée comme une migration valide de l'objet ILIAS `grp`.

Politique retenue :

```text
ILIAS grp
   ↓
DEFERRED / HISTORY_ONLY
```

Aucun Moodle Group ne doit être créé automatiquement comme substitut de l'objet ILIAS Groupe. Une future prise en charge devra définir et valider une combinaison complète, par exemple section/sous-section + Groupe/Grouping + restrictions d'accès + migration des contenus enfants.

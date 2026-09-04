# Mapping ILIAS 10 → Moodle 4.5

Cette matrice est le contrat fonctionnel de la migration. Elle sera affinée avec le cours POC réel.

| Type ILIAS | Code indicatif ILIAS | Cible Moodle | Phase | Statut |
|---|---|---|---:|---|
| Catégorie | `cat` | Catégorie | 2 | prévu |
| Cours | `crs` | Cours | 2 | prévu |
| Dossier | `fold` | Section / Sous-section | 2 | prévu |
| Fichier | `file` | Resource | 3 | prévu |
| URL | `webr` | URL | 3 | prévu |
| Page / contenu | selon contexte | Page / Label | 3 | à préciser |
| SCORM | `sahs` | SCORM | 4 | prévu |
| Learning Module ILIAS | `lm` | Book | 5 | prévu |
| Test | `tst` | Quiz | 6 | prévu |
| Question pool | `qpl` | Question bank | 6 | prévu |
| Forum | `frm` | Forum | ultérieur | à étudier |
| Wiki | `wiki` | Wiki | ultérieur | à étudier |
| Exercice | `exc` | Assignment | ultérieur | à étudier |
| Groupe | `grp` | Groupe / Groupement | 7 | à étudier |
| Learning Progress | — | Completion | 7 | complexe |

## Règle pour les dossiers

Un dossier ILIAS n’est pas systématiquement converti en ressource « Folder » Moodle.

- dossier structurant un cours → section ou sous-section ;
- dossier servant uniquement de dépôt de fichiers → ressource Folder possible ;
- cas ambigu → signalé dans le rapport.

## Liens internes

Les liens contenant des identifiants ILIAS devront être résolus après création des objets Moodle grâce à une table de correspondance.

```text
ILIAS source_id → Moodle component + instance/course_module id
```

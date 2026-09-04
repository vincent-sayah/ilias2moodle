# Format `migration.json`

## But

`migration.json` constitue le contrat entre l’extraction ILIAS et l’import Moodle.

Le format doit rester indépendant des détails internes des deux bases de données.

## Version du schéma

Chaque document contient :

```json
{
  "schema_version": "1.0"
}
```

Toute évolution incompatible devra modifier cette version.

## Structure minimale

```json
{
  "schema_version": "1.0",
  "source": {
    "lms": "ILIAS",
    "version": "10"
  },
  "course": {
    "source_id": "1234",
    "title": "Cours de démonstration",
    "description": "",
    "items": []
  }
}
```

## Objet pédagogique

Chaque objet possède au minimum :

```json
{
  "source_id": "1240",
  "type": "folder",
  "title": "Séquence 1",
  "description": "",
  "position": 1,
  "metadata": {},
  "items": []
}
```

## Principes

- `source_id` doit permettre de retrouver l’objet ILIAS.
- `position` préserve l’ordre au sein d’un parent.
- `metadata` contient uniquement les données utiles non couvertes par les champs standards.
- `items` représente la hiérarchie.
- les fichiers physiques sont référencés par chemin relatif dans le package d’export.

## Évolutions prévues

Le schéma sera enrichi pour :

- visibilité et disponibilité ;
- fichiers et checksum ;
- utilisateurs et inscriptions ;
- questions et tests ;
- mapping de destination Moodle ;
- avertissements de conversion.

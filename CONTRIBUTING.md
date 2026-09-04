# Contribuer à ILIAS2Moodle

## Principes

- Ne jamais versionner de mot de passe, token ou certificat privé.
- Une migration doit être rejouable et traçable.
- Aucun accès SQL direct aux tables Moodle pour créer des objets pédagogiques.
- Tout nouveau type d’objet doit être ajouté à la matrice `docs/mapping.md`.
- Les objets non pris en charge doivent être signalés explicitement.

## Développement

```bash
python -m venv .venv
source .venv/bin/activate
pip install -e ".[dev]"
pytest
ruff check .
```

## Branches

Pour les évolutions significatives, utiliser une branche dédiée puis une Pull Request vers `main`.

Exemples :

```text
feature/phase1-soap-client
feature/phase2-moodle-structure
fix/report-counter
```

## Commits

Format recommandé :

```text
feat: add ILIAS SOAP course discovery
fix: preserve item ordering
chore: update CI
 docs: document QTI mapping
```

# Phase 6.5.3 — Wiki ILIAS vers Moodle Wiki

## Contrat d'export préparatoire

Cette implémentation est construite à partir du contrat d'export du composant Wiki ILIAS et doit être confirmée sur un export réel ILIAS 10.8 avant toute écriture Moodle.

Le parseur attend notamment :

- un ExportSet de type wiki ;
- le composant components/ILIAS/Wiki/set_0/export.xml ;
- une entité DataSet wiki pour les paramètres de l'objet ;
- des entités wpg pour les pages ;
- éventuellement wiki_imp_page pour les pages importantes ;
- le composant COPage avec des ExportItem Id="wpg:<page_id>" ;
- MediaObjects et File pour les ressources embarquées.

## Politique historique

L'export XML courant ne transporte pas les anciennes révisions COPage ni leurs auteurs. La migration Phase 6.5.3 importe donc uniquement l'état courant des pages et signale explicitement :

- history.migration_policy = current_pages_only ;
- history.authors_migrated = false.

La Phase 7 pourra traiter l'identité des utilisateurs lorsque des données source adaptées existent.

## POC réel à valider

Le POC doit contenir au minimum :

1. une page d'accueil ;
2. deux pages supplémentaires ;
3. des liens croisés entre pages ;
4. du texte riche ;
5. une image ;
6. si possible un fichier lié depuis une page ;
7. plusieurs modifications successives d'une page pour confirmer que l'historique n'est pas exporté.

Aucun apply Moodle ne doit être autorisé tant que la structure réelle n'a pas été comparée à ce contrat.

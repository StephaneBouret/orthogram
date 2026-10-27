# Module 2 — import local vérifié le 27 octobre 2026

Les trois quiz ont été importés sur **orthogram**, en `dev`, via `127.0.0.1:3306`, serveur MySQL `STEF-LEGION` (8.4.7). La destination a été identifiée par la connexion effective, `SELECT DATABASE()`, `@@hostname` et `@@port`. Aucun accès distant n’a été effectué.

| Clé | ID local | Initiales | Réserves | Mode | Quota global |
| --- | ---: | ---: | ---: | --- | --- |
| module2_test1 | 9 | 12 | 19 | fixe | NULL |
| module2_test2 | 10 | 12 | 24 | fixe | NULL |
| module2_general | 11 | 24 | 86 | fixe | NULL |

L’import a ajouté 177 questions, 354 propositions, 177 traces de copie et 3 identités éditoriales. Aucun cours n’a été créé ou rattaché. Les thèmes, contenus, réponses, rôles et positions ont été contrôlés contre le manifeste, inchangé. La nouvelle prévisualisation n’annonce aucun ajout ; un second passage a conservé intégralement toutes les tables, y compris leurs structures et compteurs.

## Adaptations du code

`ModuleTwoQuizImportCommand` expose `--allow-work-database` et transmet ce consentement à `ModuleTwoQuizImporter::apply()`, qui revérifie la destination. `ModuleTwoQuizDestination` autorise uniquement l’import fixe sur `orthogram` en `dev` avec ce consentement et toutes les assertions. Le port effectif est vérifié. Les destinations distantes et la production sont refusées ; le renouvellement sur la base de travail demeure interdit. Les règles des bases jetables sont conservées.

Les commandes/services du module 1 et le dossier éditorial n’ont pas été modifiés. Le plan inclut toujours la destination et est recalculé sous verrou. Le refus des collisions, divergences, imports partiels et plans périmés reste inchangé.

## Sauvegarde et séparation des environnements

Avant toute mutation d’`orthogram`, un export complet a été réalisé avec `mysqldump` (transaction cohérente, tables, données, routines, événements et triggers, valeurs binaires conservées). La base ne comportait que 31 tables InnoDB, sans vues, routines, événements ni triggers.

Sauvegarde conservée, hors Git :

`var/backups/orthogram_module2_before_20261027_ef2b8cba11809a44.sql`

Taille : 388 823 octets. SHA-256 :

```text
991c0ff4b0853587e790aee071d53787cefef5d500ed377409561bf74caec181
```

La restauration a été vérifiée sur `orthogram_module2_restore_ef2b8cba11809a44`, puis cette seule base de restauration a été supprimée. Toutes les lignes et définitions de colonnes sont identiques ; le comparateur de schéma Doctrine ne relève aucune différence. MySQL rend parfois explicite le jeu de caractères hérité : seule cette redondance syntaxique a été normalisée pour comparer aussi les DDL. Deux exports de diagnostic antérieurs restent conservés localement ; la sauvegarde validée est celle indiquée ci-dessus.

Les tests automatisés utilisent exclusivement les bases de recette MySQL aléatoires et SQLite existantes. Les identités `orthogram` des tests de garde-fou sont des doublures sans connexion à la base de travail. La restauration est un contrôle opérationnel distinct, sans lancement de tests applicatifs sur les données restaurées.

Sur **orthogram** uniquement : identification et relevés de conservation, sauvegarde en lecture, dry-run, migration ciblée, aperçu, import fixe autorisé et second passage sans modification. Aucun test automatisé, aucune activation et aucun rattachement n’y ont été exécutés.

## Migration et conservation du module 1

Les migrations `Version20261025120000` et `Version20261025150000` étaient déjà enregistrées. Seule **`DoctrineMigrations\Version20261027120000`** a été exécutée après son dry-run. Elle a ajouté la table vide de traçabilité attendue et son enregistrement de migration. Aucun autre schéma ni aucune ancienne ligne n’a changé. Le schéma créé correspond au mapping Doctrine.

| Quiz module 1 | ID | Initiales / réserves conservées | Renouvellement conservé | Quota conservé | Cours conservé |
| --- | ---: | --- | --- | --- | ---: |
| module1_test1 | 5 | 14 / 16 | actif | NULL | 63 |
| module1_test2 | 6 | 12 / 13 | actif | NULL | 64 |
| module1_test3 | 7 | 12 / 18 | actif | NULL | 65 |
| module1_test4 | 8 | 20 / 47 | actif | NULL | 66 |

Les comparaisons portent sur les lignes complètes, pas seulement sur les volumes : quiz, questions, propositions, traces, cours, tentatives/snapshots et progressions. Le premier quiz avait une tentative et une progression, les autres aucune ; toutes sont inchangées. La vérification globale couvre aussi les autres tables préexistantes, notamment les dictées.

## Contrôles exécutés et preuves locales

- Tests ciblés module 2 : **49 tests, 450 assertions**, aucun échec.
- Non-régression quiz/modules 1 et 2 : **264 tests, 3 130 assertions**, aucune erreur, aucun échec ni test ignoré.
- PHPStan niveau 6 sur les fichiers adaptés : aucune erreur.
- `lint:container`, PHP CS Fixer sur les cinq fichiers PHP adaptés et contrôle du diff hors dossier éditorial : conformes. Le guide fourni comporte des espaces de fin de ligne conservés volontairement ; ses octets et son empreinte ne sont pas modifiés. Les fins de ligne des rapports synthétiques destinés au dépôt ont été uniformisées en LF, sans changement de données.

La suite complète des fonctionnalités sans rapport avec les quiz n’a pas été lancée. Les preuves de préparation sur données synthétiques restent dans `tests/manual/module2_*_report.json`. Les rapports opérationnels réels et les captures contenant des données locales restent **hors dépôt**, dans `var/module2-local` :

| Fichier | Preuve |
| --- | --- |
| `backup.json` | Destination, empreinte, restauration des 31 tables et nettoyage |
| `migration-dry-run.json` / `.log` | Dry-run et absence de mutation |
| `migration-apply.json` / `.log` | Seule migration ciblée, concordance du schéma |
| `preview.json` | Plan initial : 3 quiz, 177 questions, 354 propositions |
| `import.json` | Application du plan exact |
| `conservation.json` | Conservation de chaque table et identités créées |
| `module1-before.json`, `module1-after.json` | Paramètres et empreintes de conservation du module 1 |
| `second-preview.json`, `second-apply.json` | Zéro ajout et no-op |
| `noop-verification.json`, `final-verification.json` | Identité intégrale après second passage et état final |
| `targeted-tests.xml`, `quiz-regression.xml` | Résultats PHPUnit de cette exécution |
| `adaptations.patch` | Diff depuis la version relue, avant ouverture de la destination locale |

Plan d’import appliqué : `3363a1917c4896dcae39aad2c8f57b8d0230d5c6520b6dbfe74585939300ea58`.
Plan du second passage : `30df5cf74daf058731fc8320d98d4bee8986099eca9bcb0388760b4f670bdbc9`.
Ces empreintes sont propres à cette exécution et ne doivent pas être réutilisées pour une autre opération.

## Rattachement manuel EasyAdmin

Aucun rattachement n’a été effectué. Lors de l’étape manuelle, utiliser dans **Cours** le **Type de contenu : Quiz**, puis le champ **Quiz** avec le titre correspondant. Les ID ci-dessus sont ceux de cette base locale, sans présumer d’identifiants de cours à modifier.

| Quiz à sélectionner | Emplacement pédagogique validé |
| --- | --- |
| ID 9 — Test : écrire les nombres et les expressions courantes | Après l’exercice « plutôt / plus tôt », avant le cours « on / on n’ » |
| ID 10 — Test : distinguer les formes et les terminaisons | Après l’exercice des adverbes en « -amment / -emment », avant le cours « d’en / dans » |
| ID 11 — Test général : réviser le module 2 | Après l’exercice sur tout adverbe, avant la dictée finale |

Conserver le mode fixe et le quota NULL. La publication, les rattachements et l’activation restent des opérations distinctes. Le commit local sur `preprod` est autorisé après ces contrôles ; aucun push, déploiement ni accès Hostinger n’est effectué.

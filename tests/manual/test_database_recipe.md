# Préparer la base locale pour PHPUnit

La suite utilise une base MySQL persistante pour les rappels, des bases MySQL temporaires pour les dictées et des bases SQLite temporaires pour plusieurs tests HTTP. Le bootstrap charge l'environnement et isole les fichiers de test ; il ne prépare pas le schéma. Appliquer explicitement les migrations avant les tests, sans charger les fixtures de développement.

## Vérifier les connexions avant toute écriture

Depuis PowerShell, dans `C:\Formations\orthogram` :

```powershell
php -d xdebug.mode=off tests/manual/inspect_test_connection.php dev
php -d xdebug.mode=off tests/manual/inspect_test_connection.php test
```

L'outil affiche uniquement l'environnement, les connexions Doctrine résolues, le pilote, l'hôte, le port et le nom de base. En mode `dev`, il n'ouvre aucune connexion SQL. En mode `test`, il refuse une connexion autre que MySQL local avec un nom terminé par `_test`, puis confirme `SELECT DATABASE()` en lecture seule. Ce contrôle ne lance ni migration ni fixture et ne vérifie pas à lui seul l'état du schéma.

Sur cette installation, les sorties attendues sont :

| Environnement | Connexion | Hôte et port | Base |
| --- | --- | --- | --- |
| dev | default | 127.0.0.1:3306 | orthogram |
| test | default | 127.0.0.1:3306 | orthogram_test |

Comparer les deux sorties : **arrêter avant toute écriture si la cible n'est pas exclusivement locale, dédiée aux tests et distincte de celle de développement**. Ne pas réutiliser ces commandes sur une connexion distante. Ne pas afficher `DATABASE_URL` ni les secrets pour diagnostiquer une différence.

`phpunit.dist.xml` force `APP_ENV=test` et `APP_DEBUG=1`. Vérifier aussi un éventuel `phpunit.xml` local. Symfony charge les fichiers Dotenv avec les surcharges du processus ; `.env.local` est normalement ignoré en `test`, mais `.env.test.local` ou un environnement compilé peuvent modifier la résolution. Doctrine ajoute `_test` puis `TEST_TOKEN` au nom MySQL. Ne pas ajouter déjà `_test` à `DATABASE_URL`. Cette procédure locale suppose `TEST_TOKEN` vide ; ne pas lancer plusieurs suites simultanément sur `orthogram_test`.

Les connexions supplémentaires des tests de verrou reprennent les paramètres Doctrine résolus. `DictationTestDatabase` crée ses propres bases `orthogram_dictation_2a_<16 caractères hexadécimaux>_test`, contrôle l'hôte local et ne supprime normalement que celles qu'il vient de créer. Les tests SQLite utilisent des fichiers temporaires ou `:memory:` et rétablissent leurs surcharges d'environnement.

## Examiner puis appliquer les migrations

Après validation des connexions, dans le même environnement PowerShell :

```powershell
php -d xdebug.mode=off bin/console doctrine:migrations:status --env=test --no-interaction
php -d xdebug.mode=off bin/console doctrine:migrations:list --env=test --no-interaction
php -d xdebug.mode=off bin/console doctrine:migrations:migrate --env=test --dry-run --no-interaction -vv
```

Lire les migrations annoncées et leurs opérations `up()` avant de continuer. Une simulation ne garantit pas qu'une base partiellement migrée acceptera les opérations : comparer aussi les écarts au mapping avec la commande de lecture seule ci-dessous.

```powershell
php -d xdebug.mode=off bin/console doctrine:schema:update --dump-sql --env=test
```

Ne jamais ajouter `--force`, exécuter manuellement le SQL proposé, marquer artificiellement une migration ou supprimer/recréer la base existante. Après examen :

```powershell
php -d xdebug.mode=off bin/console doctrine:migrations:migrate --env=test --no-interaction
php -d xdebug.mode=off bin/console doctrine:migrations:status --env=test --no-interaction
php -d xdebug.mode=off bin/console doctrine:schema:validate --env=test
php -d xdebug.mode=off bin/console doctrine:schema:update --dump-sql --env=test
```

Arrêter sur toute erreur inattendue. Ne pas transformer cette préparation en migration automatique à chaque test.

## Exécuter les tests

`config/packages/mailer.yaml` fixe le transport de test à `null://null`, même si `MAILER_DSN` est hérité. Les tests de commande remplacent le processeur des rappels par un double ; les tests du processeur remplacent le mailer. Ne pas lancer de worker Messenger : le transport `failed` reste lié à la connexion Doctrine de test et le transport `async` habituel est `doctrine://default?auto_setup=0`.

Les tests audio dépassent la limite CLI locale de 128 Mo lors d'une suite longue. Utiliser 512 Mo pour ces processus, sans modifier `php.ini` :

```powershell
php -d xdebug.mode=off -d memory_limit=512M vendor/phpunit/phpunit/phpunit --filter 'Dictation|LearningReminder' --colors=never
php -d xdebug.mode=off -d memory_limit=512M vendor/phpunit/phpunit/phpunit tests/Services/ExerciceCorrectionServiceTest.php tests/Entity/ExerciceTest.php tests/Form/ExerciceSentenceValidationTest.php --colors=never
php -d xdebug.mode=off -d memory_limit=512M vendor/phpunit/phpunit/phpunit --display-skipped --colors=never
```

Le dernier appel exécute toute la suite, sans filtre ni exclusion. Sous Windows, `CourseFilesTest::testEscapingSymlinkIsRefused` est ignoré si le compte ne peut pas créer de lien symbolique ; conserver cette condition et les assertions existantes.

## Diagnostic du 24 octobre 2026

Sur `preprod`, commit initial `fc9d53a3b5f3abba2a211dd27540dfe557d97563`, le répertoire de travail était propre. `orthogram_test` avait 32 migrations exécutées sur 38. Les six migrations suivantes ont été appliquées exclusivement à cette base locale :

- `Version20260913160000` : tentatives de quiz.
- `Version20261001120000` : gratuité des cours.
- `Version20261009120000` : marque-page des cours.
- `Version20261016120000` : tables des dictées.
- `Version20261016180000` : références audio et segments.
- `Version20261017180000` : `user.dictation_prerequisites`.

Les 52 erreurs du journal provenaient toutes de la colonne absente. Le test `testNoReminderReturnsSuccessAndReleasesLock` attendait `0` et obtenait `1` : la sélection des rappels joint `user`, et la commande intercepte l'exception SQL comme erreur d'infrastructure. Reproduction avec zéro rappel en base et horloge fixée au 1er janvier 1900 UTC. Après migration, les 9 tests de commande passent avec 53 assertions, sans correction métier ni modification d'assertion.

Il reste un écart de **nom d'index** entre la migration historique du marque-page (`IDX_USER_LAST_VISITED_COURSE`) et le mapping (`IDX_8D93D649AFDEBEB2`), sur la même colonne `user.last_visited_course_id`. `doctrine:schema:validate` échoue donc encore et `--dump-sql` propose uniquement ce renommage. Ne pas le masquer par un SQL manuel ni modifier la migration historique dans ce chantier. Les colonnes et tables manquantes sont, elles, rétablies.

Le premier lancement ciblé, à 128 Mo, s'est interrompu dans `DictationDraftDeletionTest::testDeletionPreservesIdentityVersionsAttemptsResultsAndSharedAudio` avant le nettoyage de sa base temporaire. La base `orthogram_dictation_2a_d30fe2a8557c89c9_test`, créée pendant ce lancement, a été conservée conformément à la contrainte de ne pas supprimer de base existante. Le lancement ciblé à 512 Mo a ensuite réussi : 590 tests, 31 274 assertions, pic mémoire de 176 Mo. Les tests `click_words` restent à 27 tests et 188 assertions.

La suite complète à 512 Mo a terminé avec le code de sortie 0 : **1 080 tests, 36 534 assertions, zéro erreur, zéro échec, un test ignoré**, en 4 min 33 s, avec un pic mémoire de 184 Mo. Le seul test ignoré est celui du lien symbolique décrit plus haut ; aucune exclusion n'a été ajoutée. Journaux locaux : `var/test-database-targeted.log` et `var/test-database-full.log` ; le journal initial `var/click-words-phpunit.log` a été conservé.

Contrôles des fichiers modifiés :

```powershell
php -l tests/manual/inspect_test_connection.php
php -d xdebug.mode=off vendor/bin/php-cs-fixer fix tests/manual/inspect_test_connection.php --dry-run --diff --using-cache=no
php -d xdebug.mode=off bin/console lint:yaml config/packages/mailer.yaml
php -d xdebug.mode=off bin/console lint:container --env=test
git diff --check
```

Ces contrôles passent. Le refus d'une connexion distante a aussi été vérifié avant toute requête SQL, ainsi que le maintien du transport mail `null://null` avec une variable SMTP héritée. Aucun fichier métier, test existant, migration historique ou dépendance n'a été modifié ; aucun commit, push ni déploiement n'a été effectué.

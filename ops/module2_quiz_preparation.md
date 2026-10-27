# Module 2 — préparation de l’import et recette

Le dossier livré est conservé à l’identique dans `resources/quiz/module2/`, avec les cinq empreintes de `SHA256SUMS.txt`. Le manifeste a pour SHA-256 `2ce76e2738a3f8221fd0c121861b59d1888c13d6259930a6d539959ef39a2571`. Les conversions de fins de ligne Git sont désactivées pour ce dossier afin de préserver ses octets. Aucun contenu pédagogique n’a été retouché.

| Quiz | Initiales / tentative | Réserves | Banque |
| --- | ---: | ---: | ---: |
| module2_test1 | 12 | 19 | 31 |
| module2_test2 | 12 | 24 | 36 |
| module2_general | 24 | 86 | 110 |

116 sources, dont 110 importables et les six attentes Q2/Q44/Q45/Q75/Q80/Q111 ; 177 copies distinctes et 354 propositions. Les trois quiz sont créés fixes, sans quota global d’erreurs. Aucun cours n’est créé ou rattaché.

## Commandes livrées

- `app:quiz:module2-import` : prévisualisation par défaut ; `--apply` exige l’empreinte exacte du plan et la destination vérifiée.
- `app:quiz:module2-renewal enable|disable` : opération distincte, limitée aux trois identités du module 2. Prévisualisation par défaut également.

Options communes : `--expect-database`, `--expect-env`, `--expect-host`, `--expect-port`, `--expect-server`, `--expect-plan`, `--apply`, `--allow-work-database`, `--allow-production-database`, `--report`. La sortie est un rapport JSON indenté ; `--report` en conserve une copie. Les erreurs de contenu détaillent quiz, source, champ, attendu et constaté. Une prévisualisation ouvre une transaction MySQL/MariaDB en lecture seule ; elle n’exécute aucune migration. `--expect-database` est toujours requis ; les cinq assertions de destination sont obligatoires pour appliquer et, en production, dès la prévisualisation.

Les écritures de recette restent autorisées en environnement `test` sur une base locale nommée `orthogram_module2_<16 caractères hexadécimaux>_test`. L’import fixe, l’activation et la désactivation sur la base locale `orthogram`, en environnement `dev`, exigent en plus `--allow-work-database`. Ce consentement porte sur l’action demandée ; il est transmis à l’importeur puis revérifié sur la connexion réelle. Il ne fait pas partie de l’identité de destination et ne remplace aucune assertion ni l’empreinte du plan. Le port effectif `@@port` est également contrôlé. Les hôtes distants sont refusés hors prod. En prod explicitement attendu, toute écriture exige le consentement distinct `--allow-production-database` ; `--allow-work-database` y est interdit. Voir la [procédure bêta](module2_quiz_production.md), sans opération distante réalisée pendant cette préparation. Les protections et commandes du module 1 ne sont pas élargies. Aucune option ne permet de forcer une adoption, une correction ou un remplacement de données existantes.

Exemples de syntaxe PowerShell, uniquement pour une base de recette déjà créée et une connexion configurée vers cette base. Les variables doivent provenir de l’identification réelle de cette connexion ; ce ne sont pas des noms de destination à inventer :

```powershell
$destination = @(
    '--env=test', "--expect-database=$RecipeDatabase", '--expect-env=test',
    "--expect-host=$RecipeHost", "--expect-port=$RecipePort", "--expect-server=$RecipeServer"
)
php bin/console app:quiz:module2-import @destination --report=var/module2-preview.json
# Lire le rapport, résoudre les prérequis, puis utiliser son plan_hash exact.
php bin/console app:quiz:module2-import @destination --apply "--expect-plan=$ReviewedPlanHash"
# Refaire un aperçu après l'import : son application identique doit être un no-op.
php bin/console app:quiz:module2-import @destination --report=var/module2-second-preview.json
# Activation distincte dans cette recette jetable.
php bin/console app:quiz:module2-renewal enable @destination --report=var/module2-enable-preview.json
php bin/console app:quiz:module2-renewal enable @destination --apply "--expect-plan=$ReviewedEnableHash"
```

L’empreinte du plan inclut base, hôte, port, nom du serveur, environnement, action, manifeste, identités, configuration et contenu constaté. Le plan doit être renouvelé après le premier import ou toute modification pertinente ; réutiliser l’ancien plan après une création est refusé, même si un nouveau plan permet ensuite un no-op.

## Persistance et concurrence

La migration **`Version20261027120000`** ajoute seulement `quiz_manifest_identity` : clé éditoriale unique, quiz unique, empreinte du manifeste. La référence nullable conserve une trace de suppression du quiz. Elle ne crée aucun quiz et n’active rien. Les migrations existantes des champs de renouvellement et de `quiz_reserve_import` sont des prérequis.

Les 177 copies réutilisent `QuizReserveImport`, initiales comprises : son identité `(quiz_id, source_key)` et ses empreintes ne dépendent pas du rôle de la question. Ses usages module 1 sont limités à leurs quiz propres. La suppression d’une question conserve une trace sans question ; la suppression d’un quiz laisse son identité éditoriale, empêchant une recréation implicite. Une identité manquante, une trace étrangère, un import partiel ou une collision de titre bloque tout le lot.

Un verrou MySQL `GET_LOCK` propre à la base protège les créations simultanées avant l’existence des quiz. Les contraintes SQL garantissent aussi l’unicité persistante. Les quiz existants sont verrouillés par `QuizEditorialService`, en commun avec les éditions et les créations de tentatives. Le plan est recalculé sous ces protections. Questions, propositions, identités et traces sont enregistrées dans une seule transaction DML. Le verrou est libéré en `finally`, y compris après rollback. Le DDL reste distinct, conformément aux commits implicites de MySQL.

Le chargeur vérifie les clés, rôles, affectations, positions, thèmes, catégories explicites, réponses et empreintes canoniques compatibles Python. Le validateur Symfony de la banque future s’exécute aussi en prévisualisation, avec le renouvellement activé seulement sur un objet non persisté. Lors de la création, le mode fixe est restauré avant flush. Un réimport identique ne fait aucun flush et conserve un mode renouvelable et un quota existants, signalés dans le rapport.

## Recette reproductible

```powershell
py resources/quiz/module2/valider_module2.py
$env:MODULE2_RECIPE_REPORTS = '1'
php -d memory_limit=512M -d xdebug.mode=off vendor/bin/phpunit --filter 'ModuleOne|ModuleTwo|Quiz' --log-junit var/module2_quiz_regression.xml
```

`DictationTestDatabase(recipe: 'module2')` crée une base MySQL locale aléatoire, sans sélectionner la base de l’application, installe le schéma et détruit sa propre base après chaque test. Les données sont entièrement synthétiques. Les tests de non-régression utilisent leurs dispositifs isolés existants (MySQL jetable ou SQLite). Aucun accès distant n’est nécessaire.

Les tests module 2 couvrent : schéma manquant sans écriture ; migration réelle et concordance Doctrine ; conservation de toutes les lignes préexistantes, dont tentatives terminées/en cours, progression, cours et dictées ; comptes exacts et absence de rattachement ; second import sans aucune modification ; autre base avec AUTO_INCREMENT décalés ; rollback après insertion réellement effectuée ; treize corruptions et changement des octets du manifeste ; collision de titre ; cinq assertions de destination ; plan périmé ; deux processus concurrents ; parcours fixe, reprise interrompue, correction et score ; activation/désactivation séparées ; quota existant conservé ; premiers renouvellements comparés au simulateur ; conservation des réponses et corrigés historiques après édition de banque.

Preuves conservées dans `tests/manual/module2_*_report.json` : prévisualisation et empreintes avant/après no-op, identifiants décalés, concurrence, parcours apprenant. Le rapport de contrôles consolidé précise les commandes réellement exécutées. Les noms de bases de ces rapports sont ceux de bases jetables supprimées ; les plans ne sont pas réutilisables ailleurs.

Exécution du 27 octobre 2026 : **248 tests, 3 100 assertions, zéro échec/erreur/test ignoré**, dont les 33 cas du module 2. PHPStan niveau 6 sur les fichiers PHP concernés, `lint:container`, vérification syntaxique de la migration/du chargeur/de l’importeur et PHP CS Fixer sont au vert. Les cinq empreintes du dossier sont inchangées ; aucune base `orthogram_module2_*_test` ne subsiste après recette. Résultats détaillés : [rapport de contrôles](../tests/manual/module2_checks_report.json). La suite complète des fonctionnalités sans rapport avec les quiz n’a pas été exécutée.

## Suite ultérieure

L’import fixe local et la migration de traçabilité sur `orthogram` ont été autorisés après revue, avec sauvegarde SQL et restauration vérifiée obligatoires. La séquence est : identification effective, tests isolés, export complet et restauration sur une nouvelle base locale jetable, dry-run puis exécution de la seule `DoctrineMigrations\Version20261027120000`, prévisualisation liée à la destination, import fixe avec consentement, conservation intégrale des données préexistantes et second passage sans modification. Les preuves locales restent sous `var/module2-local` et la sauvegarde sous `var/backups`, hors Git. Le bilan figure dans [module2_local_import.md](module2_local_import.md).

Exemple pour la destination locale effectivement identifiée, après réussite des prérequis ci-dessus :

```powershell
$local = @('--env=dev', '--expect-env=dev', '--expect-database=orthogram', '--expect-host=127.0.0.1', '--expect-port=3306', '--expect-server=STEF-LEGION')
php bin/console app:quiz:module2-import @local --report=var/module2-local/preview.json
# Utiliser exclusivement l’empreinte du rapport relu, jamais celle d’une recette ou d’une autre destination.
php bin/console app:quiz:module2-import @local --apply --allow-work-database "--expect-plan=$ReviewedLocalPlanHash" --report=var/module2-local/import.json
```

Le rattachement EasyAdmin est manuel et n’est pas effectué par l’import. Après validation de la recette locale, le renouvellement local est autorisé dans une étape distincte, décrite dans [module2_local_renewal.md](module2_local_renewal.md). Les opérations distantes, le push et le déploiement restent hors périmètre. Le commit local sur `preprod` est autorisé après réussite des contrôles.

Pour le renouvellement, le plan liste explicitement `renewal_changes` (identité tracée, ID résolu, état avant/après). L’application retourne `quizzes_changed`. Une répétition avec un nouveau plan cohérent retourne `noop=true`, sans flush ; l’ancien plan est refusé si l’état a changé. Aucun quota, contenu, rattachement ni historique n’est réinitialisé.

La réserve n’allonge jamais une tentative. Le moteur reste inchangé : il peut cycler sur une partie de la banque et ne promet pas de parcourir toutes les réserves. Le thème « tout » du général impose au moins une reprise entre deux tentatives. Aucun quota global d’erreurs n’est ajouté. Le contrôle DOCX n’a pas été réexécuté ici, le Word original n’étant pas dans l’archive ; la validation Python locale et les tests Symfony/MySQL restent des preuves distinctes.

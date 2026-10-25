# Lot 3 — Réserves du module 1

Pour une livraison sur `beta.orthogram.fr`, voir la [procédure SSH de production](../../ops/quiz_renewal_production.md). Les commandes locales ci-dessous conservent leurs protections ; la production exige une destination complète, `--expect-env=prod` et l'autorisation distincte `--allow-production-database` pour écrire.

Préparation et recette du 25 octobre 2026. L'import, l'activation et la nouvelle migration ont été exécutés **uniquement sur copie jetable**. La base de travail `orthogram` reste inchangée. Les commandes de la section « Exécution locale ultérieure » attendent une autorisation distincte de Stéphane.

## Manifeste et audit

Le manifeste versionné `resources/quiz/module1_reserve.json` reprend les 47 candidats validés dans `prep_cours/sql/quiz_module1_reserve_relecture/reserve_47_relecture.json`, la référence des 58 initiales et la répartition validée. Il conserve les empreintes SHA-256 des trois fichiers documentaires. Les corrections 42, 54, 64, 92, 98, 101 et les ajustements 28, 51, 77 sont inclus. Les anciens statuts « proposé » ne remettent pas en cause la validation acquise de Stéphane.

Les réponses et catégories sont explicites : `0 erreur` en position 0, `1 erreur` en position 1, une seule réponse correcte, cohérente avec `contains_error`. Aucun thème, quota ou corrigé n'est déduit d'un libellé. `corrected_text` reste documentaire ; l'explication validée est importée dans le champ existant `explanation`.

La résolution exige un titre exact, le chemin programme/section/cours attendu, un rattachement unique et huit quiz distincts. Pour la seule clé `fundamentals_4`, la compatibilité bêta accepte « Test général : réviser les fondamentaux » et « Test : réviser les fondamentaux », avec une correspondance unique sur l'ensemble des deux titres et les trois slugs inchangés. Les sept autres quiz gardent leur titre exact du manifeste. Cette compatibilité est portée par le résolveur, sans modifier le manifeste ni son empreinte ; voir la [procédure de production](../../ops/quiz_renewal_production.md#compatibilité-précise-du-titre-de-fundamentals_4). Les identifiants ci-dessous ont été **observés**, pas utilisés comme constantes de résolution. Les tests décalent les identifiants pour vérifier ce point.

| Quiz | ID observé | Cours observé | Initiales / taille de tentative | Réserves prévues |
| --- | ---: | ---: | ---: | ---: |
| Fondamentaux, test 1 | 1 | 15 | 10 | 0 |
| Fondamentaux, test 2 | 2 | 24 | 12 | 0 |
| Fondamentaux, fonctions | 3 | 25 | 12 | 0 |
| Fondamentaux, général | 4 | 26 | 22 | 0 |
| Module 1, test 1 | 5 | 63 | 14 | 16 |
| Module 1, test 2 | 6 | 64 | 12 | 13 |
| Module 1, test 3 | 7 | 65 | 12 | 18 |
| Module 1, général | 8 | 66 | 20 | 47 |

Socle constaté : huit quiz fixes, 114 initiales, 340 propositions, quotas d'erreurs tous à `NULL`. Aucun écart sur les 58 références du module 1 : titres, textes, explications, caractère multiple, thèmes, positions et propositions ordonnées. Les identifiants et rattachements sont relevés séparément. Toute différence future est publiée dans `initial_differences`, sans écrasement ; une référence documentaire n'a pas priorité sur les contenus réels.

Les catégories des initiales restent inchangées, y compris leurs valeurs `NULL` : aucun quota global n'étant introduit, leur renseignement n'est pas nécessaire. Une valeur de quota déjà présente est signalée et bloque import/activation, sans remise à `NULL` automatique.

## Mécanisme et évolution du schéma

`app:quiz:module1-reserves` prévisualise par défaut dans une transaction MySQL en lecture seule. `--expect-database` exige le nom effectif exact. Une écriture exige `--apply` et l'empreinte du plan relu sous verrou. Les commandes n'acceptent que MySQL local ; sur `orthogram`, elles exigent aussi `--allow-work-database`, à réserver à l'étape ultérieure autorisée.

L'import verrouille les quatre quiz du module 1 par identifiants triés via `QuizEditorialService`, recharge questions et réponses, résout de nouveau les cibles, vérifie le plan et effectue un seul flush transactionnel. Le démarrage/redémarrage prend désormais le même verrou de quiz après le verrou utilisateur, avant la sélection et le snapshot. La reprise d'une tentative existante reste fondée sur son snapshot. Les règles éditoriales et le sélecteur du lot 1 sont conservés.

Une table nouvelle, `quiz_reserve_import`, assure l'identité `(quiz_id, source_key)`, avec une référence unique à la question et les empreintes du contenu et du manifeste. La suppression d'une question laisse une trace avec référence nulle : un réimport refuse de la recréer silencieusement. Les contrôles détectent les traces partielles, les modifications, suppressions, déplacements et collisions de texte dans le quiz cible. Deux copies de la même source dans deux quiz sont volontaires.

La migration **`DoctrineMigrations\Version20261025150000`** crée uniquement cette table et ses contraintes. Elle ne modifie aucune table existante et n'importe aucune question. Elle a été prévisualisée puis exécutée sur la copie ; elle reste **non appliquée sur `orthogram`**. `Version20261025120000`, déjà présente sur la source et la copie, n'a pas été rejouée. Le retour `down` est refusé afin de conserver la traçabilité ; le retour fonctionnel prioritaire est la désactivation.

Les 94 nouvelles questions ont `reserve=true`, leur catégorie explicite, le thème exact de leur jeu initial et des positions strictement après les initiales. Les 188 propositions sont créées avec elles. L'import laisse les huit quiz fixes. Un réimport identique n'écrit aucune ligne, même après l'import concurrent ayant consommé le même plan. Une divergence provoque un refus et un rollback intégral.

`app:quiz:module1-renewal enable` est une commande distincte, également en prévisualisation par défaut. Elle exige l'import complet et valide les quatre compositions avant activation atomique. `disable` remet ces quatre quiz en mode fixe sans supprimer les réserves ni la provenance ; il reste utilisable en présence d'une divergence de réserve, sous réserve de la validation de composition fixe applicable. Les fondamentaux ne sont jamais activés.

## Recette effectivement réalisée

Source vérifiée : `orthogram`, `127.0.0.1:3306`, serveur `STEF-LEGION`, MySQL 8.4.7. Copie : **`orthogram_quiz_reserve_lot3_b8ceb85b4af9`**, distincte et initialement identique sur les lignes et colonnes des 30 tables. Configuration injectée au processus, `.env.local` intact, courrier `null://null`, transport Messenger `in-memory://`, aucune tâche externe lancée.

Avant import, un compte synthétique a reçu un historique général terminé (tentative 2, 20/20) et une tentative en cours du test 1 (tentative 3, une réponse validée). La tentative réelle préexistante 1 reste intacte. Ces données de préparation sont distinguées des effets de l'import.

Après import : **47 sources, 94 réserves, 188 propositions ajoutées ; 208 questions, 528 propositions au total**. Les 114 initiales et leurs propositions, identifiants, positions, contenus, rattachements, les cours et toutes les données préexistantes restent identiques. Le réimport conserve toutes les lignes et tous les schémas. Les huit quiz sont fixes après import.

Dans Chrome 155, avec authentification locale du compte synthétique :

- Ouverture des huit quiz et vérification des tailles ; compteurs initiaux/réserves dans EasyAdmin.
- Consultation du corrigé historique synthétique antérieur à l'import, avec contrôle des 20 textes et explications.
- Reprise de la tentative 3 avec son snapshot et sa réponse préexistante ; réponse, rafraîchissement et reprise du test 2 avec état identique.
- Finalisation des quatre tests fixes : 14/14, 12/12, 12/12, 20/20 et identifiants initiaux dans leur ordre exact.
- Activation, redémarrage des quatre tests, puis désactivation pendant ces nouvelles tentatives : leurs sélections et états sont conservés.
- Finalisation des quatre tentatives renouvelées, contrôle de chaque emplacement thématique et des réutilisations minimales.
- Redémarrage après désactivation : retour aux initiales vérifié dans l'interface puis sur l'intégralité des quatre snapshots en base. Réserves conservées et compteurs visibles.

Aucune erreur HTTP applicative ni exception JavaScript observée. Le module Google Maps externe, importé globalement par le site mais sans rapport avec le quiz, a été remplacé par un module neutre dans le navigateur de recette ; les accès externes sont bloqués. L'authentification complète et l'intégration Google Maps ne font pas partie de ce contrôle.

Les scénarios navigateur ont volontairement ajouté 11 tentatives pour le seul compte synthétique. La tentative 3 a été terminée sans changer son snapshot ni sa première réponse. Les historiques terminés antérieurs et les progressions déjà présentes restent identiques. Les nouvelles progressions et le signet de cours (`last_visited_course_id`, `course_bookmark_version`) concernent exclusivement le compte synthétique. Toutes les autres tables sont identiques à l'état après import. La copie est conservée localement pour revue ; elle est jetable.

### Comportement réel du renouvellement

| Quiz du module 1 | Taille | Réutilisations au deuxième passage | Questions distinctes sur les deux passages | Banque totale |
| --- | ---: | ---: | ---: | ---: |
| Test 1 | 14 | 2 | 26 | 30 |
| Test 2 | 12 | 3 | 21 | 25 |
| Test 3 | 12 | 0 | 24 | 30 |
| Général | 20 | 1 | 39 | 67 |

Le thème « déjà » n'a aucune réserve : ses deux initiales du test 2 et son initiale du général sont nécessairement réutilisées. Les thèmes ayant moins de réserves que de places expliquent les autres réutilisations. Ce minimum ne garantit pas un renouvellement intégral. Le sélecteur est déterministe : le troisième passage testé automatiquement retrouve l'ensemble des identifiants initiaux, avec permutation possible à l'intérieur d'un même thème. Il ne couvre pas toute la banque et n'effectue pas de tirage aléatoire. Son algorithme n'a pas été réécrit.

### Contrôles automatisés et limites

Les tests d'intégration créent leurs propres bases `orthogram_dictation_2a_<aléatoire>_test`, vérifient leur nom, puis les suppriment. Ils ne pointent jamais sur `orthogram`.

Les nouveaux tests couvrent import, réimport, préservation des snapshots/progressions, modes et sélection ; refus d'import partiel, contenu divergent, question supprimée/déplacée, collision ; plan périmé ; exception après insertion SQL réelle avec rollback complet. Cinq scénarios à deux connexions/processus MySQL contrôlent l'attente effective sur le verrou : deux imports, import/édition, import/démarrage, import/redémarrage et activation/redémarrage. Une édition concurrente reste conservée ; l'import passe entièrement avant elle ou refuse le plan devenu périmé.

Les résultats chiffrés finaux et les diagnostics PHPStan comparatifs sont dans `quiz_renewal_lot3_report.json`. PHPStan reste à 34 diagnostics existants, comparés intégralement (fichier, ligne, message, identifiant et métadonnées), sans nouvelle exclusion. Le mapping Doctrine est valide. La comparaison complète du schéma de copie signale seulement le renommage d'index `idx_user_last_visited_course` vers `IDX_8D93D649AFDEBEB2`, écart préexistant hors lot ; aucune correction générale n'a été appliquée.

Les preuves brutes locales sont sous `C:\Formations\orthogram\var\quiz-reserve-lot3` : audits, snapshots, sortie de migration, résultats des tests, rapports navigateur et captures. Les snapshots contiennent les données locales et ne sont pas intégrés au rapport versionné.

## Prévisualisation utilisable maintenant, sans écriture en base

```powershell
Set-Location 'C:\Formations\orthogram'
$php = 'C:\wamp64\bin\php\php8.4.27\php.exe'
New-Item -ItemType Directory -Force 'var\quiz-reserve-lot3' | Out-Null
& $php -d xdebug.mode=off bin/console app:quiz:module1-reserves --env=dev --expect-database=orthogram --report=var/quiz-reserve-lot3/preview-work.json
if ($LASTEXITCODE -ne 0) { throw 'Audit refusé : lire le rapport.' }
```

`schema_ready=false` est attendu tant que la nouvelle migration de traçabilité n'est pas appliquée. L'audit n'exécute aucune migration. Lire `initial_differences`, `errors`, les huit identités, les quotas et les comptes. La création du fichier JSON est la seule écriture de cette commande.

## Exécution locale ultérieure — après autorisation explicite

Ne pas exécuter cette section dans le cadre de la préparation du lot 3. Prévoir un créneau sans autre écriture sur la base pendant sauvegarde, migration, import et comparaison. Refaire l'audit si le code ou les contenus ont changé ; ne jamais reprendre une empreinte ancienne pour forcer un plan nouveau.

### 1. Vérifier la destination et sauvegarder

```powershell
Set-Location 'C:\Formations\orthogram'
$php = 'C:\wamp64\bin\php\php8.4.27\php.exe'
& $php bin/console doctrine:query:sql 'SELECT DATABASE() AS db, @@hostname AS server, @@port AS port, VERSION() AS version' --env=dev
if ($LASTEXITCODE -ne 0) { throw 'Connexion non vérifiée.' }
```

Exiger `db=orthogram`, serveur de cette machine et port MySQL local ; vérifier également que la configuration effective utilise `127.0.0.1`, `localhost` ou `::1`. Ne pas afficher le mot de passe. Ne pas changer `.env.local`.

```powershell
$backupDir = Join-Path (Get-Location).Path ('var\backups\quiz-lot3-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $backupDir | Out-Null
$backup = Join-Path $backupDir 'orthogram-before-lot3.sql'
$dump = 'C:\wamp64\bin\mysql\mysql8.4.7\bin\mysqldump.exe'
$mysqlUser = Read-Host 'Utilisateur MySQL local autorisé à sauvegarder orthogram'
& $dump --protocol=TCP --host=127.0.0.1 --port=3306 "--user=$mysqlUser" --password --single-transaction --routines --events --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF "--result-file=$backup" orthogram
if ($LASTEXITCODE -ne 0) { throw 'Export échoué : ne pas poursuivre.' }
if ((Get-Item -LiteralPath $backup).Length -eq 0) { throw 'Export vide.' }
if (-not (Select-String -LiteralPath $backup -Pattern '^-- Dump completed on ' -Quiet)) { throw 'Export non terminé.' }
if (-not (Select-String -LiteralPath $backup -Pattern '^CREATE TABLE `quiz_attempt`' -Quiet)) { throw 'Sauvegarde des tentatives absente.' }
Get-FileHash -LiteralPath $backup -Algorithm SHA256 | Format-List | Out-File (Join-Path $backupDir 'sha256.txt')
Write-Output "Sauvegarde vérifiée : $backup"
```

Ces contrôles vérifient le succès de l'export, sa présence et sa fin ; ils ne remplacent pas un essai de restauration. Avant toute restauration destructive, restaurer d'abord dans une nouvelle base jetable et comparer les comptes/contenus. Conserver la sauvegarde et son empreinte hors versionnement.

### 2. Vérifier les migrations, appliquer uniquement la traçabilité si absente

```powershell
& $php bin/console doctrine:migrations:status --env=dev
& $php bin/console doctrine:migrations:list --env=dev
```

Si `Version20261025150000` est déjà exécutée, sauter les deux commandes suivantes. Sinon, prévisualiser son SQL, vérifier qu'il crée uniquement `quiz_reserve_import`, puis exécuter cette version seule. Ne pas lancer `doctrine:migrations:migrate`. Ne pas rejouer `Version20261025120000`.

```powershell
& $php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261025150000' --up --dry-run --no-interaction --env=dev
if ($LASTEXITCODE -ne 0) { throw 'Prévisualisation de migration échouée.' }
& $php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261025150000' --up --no-interaction --env=dev
if ($LASTEXITCODE -ne 0) { throw 'Migration échouée : inspecter avant de poursuivre.' }
```

### 3. Prévisualiser puis importer en conservant le mode fixe

```powershell
& $php bin/console app:quiz:module1-reserves --env=dev --expect-database=orthogram --report=var/quiz-reserve-lot3/import-local-preview.json
if ($LASTEXITCODE -ne 0) { throw 'Prévisualisation refusée.' }
$plan = Get-Content 'var\quiz-reserve-lot3\import-local-preview.json' -Raw | ConvertFrom-Json
if (-not $plan.schema_ready -or $plan.errors.Count -ne 0) { throw 'Plan non applicable.' }
# Lire aussi initial_differences et les cibles avant de continuer.
& $php bin/console app:quiz:module1-reserves --env=dev --expect-database=orthogram --apply --allow-work-database "--expect-plan=$($plan.plan_hash)" --report=var/quiz-reserve-lot3/import-local-result.json
if ($LASTEXITCODE -ne 0) { throw 'Import refusé : lire le rapport, ne pas forcer.' }
& $php bin/console app:quiz:module1-reserves --env=dev --expect-database=orthogram --report=var/quiz-reserve-lot3/import-local-after.json
if ($LASTEXITCODE -ne 0) { throw 'Contrôle après import échoué.' }
```

Contrôler 94 traces, zéro ajout prévu au réimport, huit quiz fixes, tailles 10/12/12/22 et 14/12/12/20, réserves 0/0/0/0 et 16/13/18/47. Comparer au relevé et à la sauvegarde : toutes les lignes préexistantes doivent être identiques, hors seule entrée attendue au journal Doctrine ; seuls la table de provenance et les 94/188 ajouts sont attendus. Vérifier les écrans administratifs et un historique existant sans modifier les contenus.

### 4. Activer séparément, seulement si cette action est autorisée

```powershell
& $php bin/console app:quiz:module1-renewal enable --env=dev --expect-database=orthogram --report=var/quiz-reserve-lot3/enable-local-preview.json
if ($LASTEXITCODE -ne 0) { throw 'Activation non prête.' }
$plan = Get-Content 'var\quiz-reserve-lot3\enable-local-preview.json' -Raw | ConvertFrom-Json
& $php bin/console app:quiz:module1-renewal enable --env=dev --expect-database=orthogram --apply --allow-work-database "--expect-plan=$($plan.plan_hash)" --report=var/quiz-reserve-lot3/enable-local-result.json
if ($LASTEXITCODE -ne 0) { throw 'Activation refusée.' }
```

Cette étape change uniquement `renewal_enabled` sur les quatre quiz du module 1. Elle ne modifie ni quota, ni contenu, ni snapshot. Dans EasyAdmin, Caroline retrouve les initiales et réserves existantes ; le nombre présenté à l'apprenant reste celui des initiales. Une tentative déjà commencée garde son contenu. Les prochaines tentatives suivent le mode courant.

### 5. Retour fonctionnel prioritaire : désactiver

```powershell
& $php bin/console app:quiz:module1-renewal disable --env=dev --expect-database=orthogram --report=var/quiz-reserve-lot3/disable-local-preview.json
if ($LASTEXITCODE -ne 0) { throw 'Prévisualisation de désactivation échouée.' }
$plan = Get-Content 'var\quiz-reserve-lot3\disable-local-preview.json' -Raw | ConvertFrom-Json
& $php bin/console app:quiz:module1-renewal disable --env=dev --expect-database=orthogram --apply --allow-work-database "--expect-plan=$($plan.plan_hash)" --report=var/quiz-reserve-lot3/disable-local-result.json
if ($LASTEXITCODE -ne 0) { throw 'Désactivation refusée.' }
```

Ne pas supprimer les réserves déjà référencées par des tentatives. Les tentatives commencées peuvent se terminer avec leur snapshot ; les suivantes repartent des initiales. Une restauration complète est un dernier recours : arrêt des écritures, examen des tentatives/progressions créées depuis la sauvegarde, validation d'une restauration sur une autre base, puis décision explicite. Ne jamais écraser les historiques récents pour retirer seulement des réserves.

## Fichiers livrés

- `resources/quiz/module1_reserve.json` : manifeste canonique validé et empreintes documentaires.
- `src/Services/ModuleOneReserveManifest.php`, `ModuleOneReserveImporter.php` : validation, audit, plan, import, activation/désactivation.
- `src/Command/QuizReserveCommand.php`, `QuizRenewalCommand.php` : commandes protégées et rapports JSON.
- `src/Entity/QuizReserveImport.php`, `migrations/Version20261025150000.php` : provenance composite et migration dédiée.
- `src/Services/QuizEditorialService.php`, `QuizAttemptService.php` : verrou commun aux opérations concernées.
- `tests/Integration/ModuleOneReserveImportTest.php`, `ModuleOneReserveConcurrencyTest.php`, `tests/Support/ModuleOneReserveFixture.php`, `module1_reserve_worker.php` : tests sur bases jetables.
- `tests/manual/quiz_renewal_lot3.md`, `quiz_renewal_lot3_report.json` : procédure et résultats réels.

Aucun fichier d'environnement ni contenu initial modifié. Aucun commit, push ou déploiement.

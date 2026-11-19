# Module 3 — préparation de l’import des trois quiz

Préparation du 18 novembre 2026 sur la branche `preprod`, à partir de `bfa503ffddcc692a20925127646883378f859360`. Les essais automatisés ont utilisé des bases jetables MySQL et MariaDB. Après l’import fixe dans `orthogram` locale, Stéphane a effectué la recette manuelle décrite à la fin du bilan d’import.

| Clé éditoriale | Titre | Initiales | Réserves |
| --- | --- | ---: | ---: |
| `module3_test1` | Test : choisir la bonne forme en contexte | 10 | 25 |
| `module3_test2` | Test : appliquer les règles d’écriture et d’accord | 12 | 23 |
| `module3_general` | Test général : réviser le module 3 | 20 | 78 |

### Édition vérifiée

Les cinq fichiers référencés dans `SHA256SUMS.txt` correspondent aux empreintes livrées. Le vérificateur Python a comparé les 112 entrées au fichier `CORRECTIONS_COURS/Module_3_quiz.docx`, dont le SHA-256 est `137f43ad33b4bd125b5c46d4fbbb359c9e2d201591771731950416e54a2da101`. Le manifeste accepté a pour SHA-256 `fa86493da4fb4ede77c8246bf3f460b235183eb82f506ff2acdba08ca697e876`. Un changement d’édition exige une nouvelle revue.

Les associations des sources, la liste des copies et les matrices thématiques concordent. Parmi les 98 sources retenues, 70 ont une copie dans un intermédiaire et une autre dans le général. Les 28 autres figurent seulement dans le général. Les 12 futures candidates et les 2 sources d’un autre usage ont zéro copie.

Le texte jouable vient de `candidate.text`. Les champs de correction documentaire et les originaux du Word restent dans le dossier éditorial. Le contrôle PHP recalcule les 168 empreintes sur les champs effectivement mappés, avec ordre des listes conservé, clés d’objets triées récursivement et JSON UTF-8 compact.

### Résultats de la recette

| Opération | Résultat observé sur les deux moteurs |
| --- | --- |
| Prévisualisation puis import fixe | Prévisualisation sans écriture. Création de 3 quiz non rattachés, 42 questions initiales, 126 réserves et 336 propositions. Renouvellement désactivé et quota SQL `NULL`. |
| Identification | 3 identités éditoriales et 168 traces par couple quiz/source, y compris les initiales. Un essai a décalé les compteurs d’ID avant l’import. |
| Conservation | Sur chacun des deux jeux témoins, les 15 lignes préexistantes des 29 tables sont conservées, y compris le cours rattaché, la progression, les deux tentatives et leurs snapshots. Les cinq ajouts par table sont détaillés ci-dessous. |
| Second passage | Plan à 0 quiz, 0 question, 0 proposition. Application avec `noop=true`. Comparaison intégrale des lignes identique. |
| Conflits | Collision de titre et de texte, texte modifié, empreinte de copie divergente, réponses altérées, suppression de question ou de quiz, identité manquante et traces orphelines. Le diff indique le champ, la source, l’attendu et l’observé. Les tentatives d’application ont laissé les lignes identiques. |
| Concurrence et interruption | Deux processus console attendaient le même verrou. Un seul a créé le lot. Le second a refusé le plan devenu périmé. Une exception injectée après insertion d’une trace a annulé toute la transaction. Une nouvelle connexion ORM a permis de reprendre. |
| Destination | Les essais ont omis ou faussé chacune des cinq assertions. Le nom `orthogram` a été fourni comme **valeur attendue erronée** à une commande connectée à la base jetable. |
| Activation de recette | Le plan annonce exactement trois modifications de `renewal_enabled`. La comparaison de toutes les lignes confirme ces seuls changements. Après activation, un réimport préserve également un quota non nul fixé en recette. |
| Parcours apprenant | Premier passage dans l’ordre du manifeste, reprise à l’identique après une réponse, redémarrage fixe identique. Après activation de recette, les tentatives gardent 10, 12 et 20 places, les quotas et l’ordre des thèmes. La sélection correspond aux réserves attendues par ordre éditorial, avec zéro répétition entre la tentative fixe et la première renouvelée pour les trois quiz. |
| Correction et historique | L’état envoyé pour la première question masque l’explication et les choix justes. Les bilans des tentatives terminées avec toutes les bonnes réponses indiquent 100 %. Le bilan historique reste identique après édition des réponses et de l’explication dans la banque jetable. |

Les deux captures témoins ont comparé toutes les lignes avant import, après import, après réimport et après activation de recette. L’import ajoute exactement 3 lignes dans `quiz`, 168 dans `quiz_question`, 336 dans `quiz_answer`, 3 dans `quiz_manifest_identity` et 168 dans `quiz_reserve_import`. Toutes les autres tables conservent les mêmes lignes.

| Moteur et base témoin | SHA-256 des lignes après import | SHA-256 après réimport |
| --- | --- | --- |
| MySQL 8.4.7 · `orthogram_module3_3538ab35e0e06d5c_test` | `77754c6034cfcf67472c7dfa60758798b79a8dcd2986851cbd69bdee30ef6a17` | `77754c6034cfcf67472c7dfa60758798b79a8dcd2986851cbd69bdee30ef6a17` |
| MariaDB 11.4.9 · `orthogram_module3_f3a6c8c407091a8e_test` | `f132f02474de9c92908be74c95455a3568b04b9718b69c84a0584905fa2aa81b` | `f132f02474de9c92908be74c95455a3568b04b9718b69c84a0584905fa2aa81b` |

La suppression de ces deux bases témoins a été vérifiée dans le catalogue de chaque serveur. Les tests d’intégration créent et suppriment également leur propre base aléatoire pour chaque scénario.

Les cinq tests d’intégration du module 3 ont réussi sur MySQL et sur MariaDB, avec 222 assertions par moteur. Les contrôleurs du lecteur et des bilans ont passé 61 tests supplémentaires, avec 669 assertions sur des fichiers SQLite jetables et les données témoins des suites existantes. Symfony a validé le conteneur de services. PHPStan et PHP CS Fixer ont validé les 15 fichiers PHP concernés.

La passe finale groupant le module 3, les modules 1 et 2 et les contrôles de destination a réussi avec 157 tests et 1 588 assertions. Un premier lancement simultané avec la compilation du conteneur avait échoué au renommage d’un fichier du cache Symfony sous Windows. La passe finale a tourné seule.

### Rejouer la recette

Depuis la racine du projet, la suite d’intégration construit elle-même le schéma jetable. Elle exige une connexion MySQL/MariaDB locale et un compte capable de créer et supprimer la base de recette.

```powershell
python -X utf8 resources/quiz/module3/verifier_module3.py "C:\Users\STEF-LEGION\Documents\ORTHOGRAM\CORRECTIONS_COURS\Module_3_quiz.docx"
php -d memory_limit=1G vendor/bin/phpunit tests/Integration/ModuleThreeQuizImportTest.php
php -d memory_limit=1G vendor/bin/phpunit --filter 'ModuleOneReserve|ModuleTwoQuiz|QuizCommandDestinationTest'
```

### Procédure manuelle sur une nouvelle base jetable

1. Utiliser une base de recette locale avec le schéma Orthogram déjà installé et les tables de quiz en InnoDB. Son nom effectif doit correspondre à `orthogram_module3_<16 caractères hexadécimaux minuscules>_test`. En environnement Symfony `test`, configurer `DATABASE_URL` dans le processus pour cette seule base, en omettant le suffixe final `_test` que Doctrine ajoute. Renseigner les variables PowerShell ci-dessous avec le nom **effectif**, le port et le nom du serveur de recette attendus. Employer un répertoire de rapports existant et réservé à cette exécution.
2. Prévisualiser l’import. Relire la destination, l’empreinte du manifeste, les trois clés, les volumes et les listes `schema_missing`, `errors` et `differences`. Toute divergence interrompt la procédure. Décider séparément de toute révision, adoption ou intervention sur le schéma.

```powershell
$cible = @('--env=test', '--expect-env=test', "--expect-database=$baseJetable", '--expect-host=127.0.0.1', "--expect-port=$portRecette", "--expect-server=$serveurRecette")
php bin/console app:quiz:module3-import @cible "--report=$dossierRapports/import-plan.json"
```

3. Appliquer uniquement le plan relu, puis refaire la prévisualisation. Vérifier 168 copies existantes, zéro ajout, zéro différence et des ID de quiz issus du registre. L’application vérifie la destination, prend un verrou nommé, ouvre une transaction, verrouille les quiz existants et recalcule l’empreinte du plan avant écriture.

```powershell
$planImport = (Get-Content -Raw "$dossierRapports/import-plan.json" | ConvertFrom-Json).plan_hash
php bin/console app:quiz:module3-import @cible --apply "--expect-plan=$planImport" "--report=$dossierRapports/import-applique.json"
php bin/console app:quiz:module3-import @cible "--report=$dossierRapports/import-verifie.json"
```

4. Pour un rattachement manuel ultérieur, ouvrir le cours de type **Quiz** dans EasyAdmin, vérifier sa section et sa position avec le guide, sélectionner dans le champ **Quiz** le quiz correspondant à l’identité vérifiée après import, puis vérifier le premier passage en mode fixe. Les emplacements décrits dans le manifeste servent de repères éditoriaux. [Guide de relecture et de placement](../resources/quiz/module3/module3_guide_relecture.md)
5. Prévisualiser le renouvellement séparément. Relire les trois couples avant/après et les quotas. Pour simuler l’application sur la base jetable, utiliser l’empreinte de **ce** plan. Une nouvelle prévisualisation de l’activation doit ensuite renvoyer `renewal_changes=[]`.

```powershell
php bin/console app:quiz:module3-renewal enable @cible "--report=$dossierRapports/activation-plan.json"
$planActivation = (Get-Content -Raw "$dossierRapports/activation-plan.json" | ConvertFrom-Json).plan_hash
php bin/console app:quiz:module3-renewal enable @cible --apply "--expect-plan=$planActivation" "--report=$dossierRapports/activation-appliquee.json"
php bin/console app:quiz:module3-renewal enable @cible
```

### Prévisualisation et import fixe sur la base de travail locale

La prévisualisation vérifie les cinq assertions de destination et calcule le plan dans une transaction en lecture seule. Pour appliquer un import fixe sur `orthogram`, la commande exige `--apply`, `--allow-work-database` et `--expect-plan` avec l’empreinte relue. L’écriture locale est limitée à l’environnement `dev`, sur `127.0.0.1:3306`, serveur `STEF-LEGION`. La commande et le service transmettent l’intention d’écriture au contrôle de destination partagé. Le renouvellement local dans `orthogram` se fait dans EasyAdmin. La procédure bêta décrit le consentement de production et l’activation distincte. [Procédure bêta du module 3](module3_quiz_production.md)

```powershell
php bin/console app:quiz:module3-import --env=dev --expect-env=dev --expect-database=orthogram --expect-host=127.0.0.1 --expect-port=3306 --expect-server=STEF-LEGION
```

Le 18 novembre 2026, cette prévisualisation a confirmé la destination `dev/orthogram` sur `127.0.0.1:3306`, serveur `STEF-LEGION`. Le plan prévoyait 3 nouveaux quiz fixes sans rattachement, avec un quota `NULL` chacun, 168 questions et 336 propositions. Il recensait zéro copie déjà tracée et ne signalait aucune collision de titre ou de contenu, divergence de configuration ou anomalie de schéma.

Lors de cette première étape limitée à la lecture, les six tests du module 3 ont réussi sur MySQL et MariaDB, avec 236 assertions par moteur.

### Bilan de l’import local du 18 novembre 2026

Avant l’écriture locale, les tests ciblés des commandes et protections de destination ont réussi (89 tests, 352 assertions). La restauration du dump SQL a ensuite été vérifiée sur la base jetable `orthogram_module3_restore_9d047cfbd9281fa2` par comparaison des 32 tables, de leur schéma et des 1 806 lignes, puis cette base a été supprimée. La sauvegarde de 565 353 octets se trouve hors Git dans `var/backups/orthogram_module3_before_20261118_58d66e407c1a4414.sql`. Son SHA-256 est `43eb46ca44c37979ff4020b8138aafe4f89ac78a2c71540c7876480dd1d7f65e`.

La nouvelle prévisualisation a reproduit le rapport relu et l’empreinte `107a6a4c04c8ecf12861f5c976e5e613848318f59daccc6a037e91c653669948`. La transaction a appliqué ce plan après recalcul sous verrou.

| Clé | ID local | Initiales | Réserves |
| --- | ---: | ---: | ---: |
| `module3_test1` | 12 | 10 | 25 |
| `module3_test2` | 13 | 12 | 23 |
| `module3_general` | 14 | 20 | 78 |

À la sortie de l’import, les trois quiz étaient sans rattachement, avec le renouvellement désactivé et un quota SQL `NULL`. Les seuls ajouts étaient 3 quiz, 168 questions, 336 propositions, 3 identités éditoriales et 168 traces. Les 1 806 lignes préexistantes étaient identiques, notamment les 271 traces, les 6 tentatives avec leurs snapshots et résultats, et les 6 progressions. La prévisualisation de fin d’import recensait 168 copies existantes et zéro ajout. Les preuves locales sont dans `var/module3-local-8652b100ea0a/` (sauvegarde vérifiée, captures avant/après, plans, résultat de transaction et comparaison par table).

### Recette manuelle locale de Stéphane

Stéphane a vérifié que les trois quiz étaient rattachés aux cours prévus. Il a activé le renouvellement **manuellement dans EasyAdmin** pour les identifiants locaux 12, 13 et 14, en laissant le quota de chacun à `NULL`.

| Quiz local | Emplacement vérifié | Compteur affiché | Mode affiché |
| --- | --- | ---: | --- |
| 12 · `module3_test1` | Cours prévu pour le premier test | 10 questions | Avec renouvellement |
| 13 · `module3_test2` | Cours prévu pour le deuxième test | 12 questions | Avec renouvellement |
| 14 · `module3_general` | Cours prévu pour le test général | 20 questions | Avec renouvellement |

Pour le quiz **12**, Stéphane a aussi vérifié en navigateur le premier passage, l’apparition de variantes au redémarrage et la reprise d’une tentative en cours. À la reprise, il a retrouvé les mêmes questions et l’avancement déjà acquis.

### Fichiers concernés

| Emplacement | Changement |
| --- | --- |
| `resources/quiz/module3/` | Six fichiers de l’archive, accompagnés d’un `.gitattributes` préservant leurs octets. |
| `src/Command/` | Socle partagé `EditorialQuizImportCommand`, adaptation de `ModuleTwoQuizImportCommand` et nouvelles commandes `ModuleThreeQuizImportCommand` et `ModuleThreeQuizRenewalCommand`. |
| `src/Services/` | Extraction du mappage, du calcul du plan et de la transaction dans `EditorialQuizManifest`, `EditorialQuizImporter` et le contrat `EditorialQuizDestination`. Adaptation des trois services `ModuleTwoQuiz*` correspondants. Ajout de `ModuleThreeQuizManifest`, `ModuleThreeQuizImporter` et `ModuleThreeQuizDestination`. |
| `tests/Support/DictationTestDatabase.php`, `tests/Integration/ModuleThreeQuizImportTest.php` | Génération et nettoyage des bases jetables du module 3, tests de sécurité, de conservation et de parcours apprenant. |
| `ops/module3_quiz_preparation.md` | Rapport de recette et procédure. |

Le schéma inspecté contient déjà la clé primaire éditoriale de `quiz_manifest_identity` et la clé composite quiz/source de `quiz_reserve_import`. Les références nullables au quiz ou à la question permettent de détecter une suppression. La migration `Version20261027120000` fournit le registre. Cette préparation utilise le schéma existant.

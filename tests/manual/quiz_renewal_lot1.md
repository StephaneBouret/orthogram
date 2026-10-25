# Renouvellement des quiz — lot 1

Implémentation locale du 25 octobre 2026, à partir de `73e93c31fc15350dd4a784f7948735cee3de177b` (arbre initial propre). Aucun commit, push, déploiement, accès Hostinger, import de réserve ou activation sur les contenus réels. La base de travail n'a reçu aucune migration.

## Modèle et compatibilité

| Champ | Valeur par défaut | Sens |
| --- | --- | --- |
| `Quiz.renewalEnabled` | `false` / SQL `0` | Mode fixe ; activation explicite nécessaire. |
| `QuizQuestion.reserve` | `false` / SQL `0` | Question initiale ; `true` désigne une réserve. |
| `QuizQuestion.theme` | champ existant | Clé de quota ; espaces extérieurs ignorés, casse et accents conservés. |
| `Quiz.errorQuestionQuota` | `null` | Aucune contrainte de catégorie. Un entier impose exactement ce nombre de phrases erronées. |
| `QuizQuestion.containsError` | `null` | Métadonnée éditoriale : `false` = phrase correcte, `true` = phrase contenant une erreur. |

Pour un bilan de 20 questions à équilibre 10/10 : quota `10` et catégorie renseignée explicitement sur toutes les questions admissibles. Le nombre complémentaire de phrases correctes est déduit de la taille du jeu initial. Aucun titre, identifiant de quiz ou libellé de proposition n'intervient. Les QCM ordinaires conservent un quota `null`, sans catégorie obligatoire.

La migration `Version20261025120000` ajoute seulement quatre colonnes. Aucun changement des identifiants, questions, propositions, corrections, cours, tentatives ou progressions. Les anciens imports SQL et ORM qui omettent ces champs produisent toujours des quiz fixes et des questions initiales. `theme` reste facultatif pour les anciens quiz fixes. Les snapshots restent au format v1, sans nouvelle clé obligatoire ; aucune réécriture de l'historique.

Le retour arrière destructif est volontairement refusé par `down()` : il ferait perdre les rôles et catégories renseignés après migration. Prévoir une sauvegarde avant une migration ultérieure sur une copie de recette. Le DDL MySQL n'est pas transactionnel : en cas d'échec d'un ALTER, inspecter le schéma avant toute relance.

## Politique exacte de sélection

1. Mode fixe : uniquement les initiales, triées par `(position, id)`, à chaque passage. Une réserve, même incomplète, n'affecte pas ce mode.
2. Mode renouvelé, aucun antécédent pour le triplet utilisateur/cours/quiz : exactement les initiales dans cet ordre.
3. Au redémarrage : quotas de thème calculés sur le jeu initial courant. Candidats = initiales et réserves du même quiz. Le nombre total reste celui du jeu initial.
4. Chaque question ne peut apparaître qu'une fois. Le coût d'une question vaut 1 si son identifiant figure dans le snapshot immédiatement précédent, sinon 0.
5. Sans équilibre global, prendre les absentes du passage précédent en premier pour chaque thème, puis compléter avec les déjà vues.
6. Avec équilibre global, énumérer les nombres possibles de phrases erronées de chaque thème. Pour chacun, prendre les candidates les moins coûteuses de chaque catégorie ; une programmation dynamique choisit ensuite la combinaison de coût total minimum respectant le quota global. Aucun quota 50/50 n'est imposé par thème.
7. Départage déterministe : position, puis identifiant à l'intérieur d'une catégorie ; thèmes dans l'ordre de première apparition initiale ; options de nombre d'erreurs croissantes et premier chemin de coût égal conservé. Dans une sélection équilibrée, les phrases erronées du thème précèdent les correctes. Les questions retenues sont replacées dans les emplacements de thème du jeu initial.

Il n'y a pas de tirage aléatoire. La même entrée donne la même sélection et les tests sont reproductibles. Une alternative qui permet de diminuer la réutilisation est nécessairement utilisée ; si l'équilibre l'interdit, les quotas priment. Une réserve absente ou insuffisante est normale. Cette politique peut alterner entre deux compositions ; elle ne cherche pas à couvrir tout l'historique.

## Validation et intégration

`QuizConfigurationValidator::validate($quiz)` est le point réutilisable pour le lot 2, y compris avant persistance. Il lève `InvalidQuizConfiguration` avec un message métier et n'écrit rien. Le sélecteur l'appelle avec `requirePersisted: true` avant de figer la tentative : identifiants de questions et de propositions positifs et distincts requis.

Contrôles : titre du quiz, présence d'initiales, rattachements cohérents, contraintes Symfony existantes sur les questions et propositions (contenu, nombre, bonnes réponses, mode de réponse, position), thèmes requis en renouvellement, catégorie obligatoire si un quota existe, quota compris entre zéro et le total, équilibre respecté par le jeu initial. En mode renouvelé, toute la banque doit être exploitable et une réserve doit porter un thème présent dans le jeu initial ; les brouillons invalides doivent donc être finalisés avant activation. Il n'existe pas de nouveau statut de publication dans ce lot.

Le jeu initial devant être présenté tel quel, un équilibre incompatible avec ce jeu est refusé, même si les réserves pourraient le réaliser. Un jeu initial valide constitue aussi une preuve de faisabilité des reprises, puisque ses questions restent admissibles. Le moteur vérifie néanmoins l'existence d'une solution avant de retourner sa composition.

La sélection se produit dans `QuizAttemptService::create`, sous le verrou utilisateur existant, avant le snapshot et `persist`. Une erreur renvoie HTTP 409 et annule la transaction. Reprise, état, réponse, finalisation et rejeu d'un redémarrage déjà créé ne revalident pas la banque éditoriale et ne sélectionnent rien.

Les lectures verrouillées et rafraîchies, `UNIQ_QUIZ_ACTIVE`, `UNIQ_QUIZ_RESTART`, contrôles d'appartenance, droits, CSRF et règles de complétion sont conservés. Une ancienne tentative fixe compte comme passage antérieur après activation. Les corrections et réponses restent attachées au snapshot ; aucune métadonnée de catégorie ni bonne réponse future ne part vers le lecteur.

L'introduction sans tentative compte désormais `getInitialQuestions()`. Pour une tentative, le lecteur JavaScript, les résultats, l'historique et la correction utilisent déjà le total/contenu persistant. Le détail éditorial EasyAdmin peut toujours parcourir la banque entière : son interface de réserve relève du lot 2. La comparaison historique existante reste conservatrice : des questions renouvelées peuvent être signalées comme « contenu du test modifié », sans calcul de gain entre contenus différents.

## Vérifications automatisées

Commandes depuis `C:\Formations\orthogram` :

```powershell
php -d memory_limit=512M -d xdebug.mode=off vendor/phpunit/phpunit/phpunit --filter Quiz
php -d memory_limit=512M -d xdebug.mode=off vendor/phpunit/phpunit/phpunit
php -d memory_limit=512M -d xdebug.mode=off tests/manual/verify_quiz_lot2_mysql.php
php -d memory_limit=512M -d xdebug.mode=off vendor/bin/phpstan analyse --no-progress --memory-limit=512M
php -d memory_limit=512M -d xdebug.mode=off bin/console lint:container --env=test
php -d memory_limit=512M -d xdebug.mode=off bin/console doctrine:schema:validate --skip-sync --env=test
composer validate --no-check-publish
git diff --check
```

Le contrôle CS Fixer utilise `.php-cs-fixer.dist.php`, en `--dry-run --diff`, sur les fichiers PHP de ce lot uniquement.

- `QuizQuestionSelectorTest` : défauts, exclusion des réserves, ordre initial, quotas, manque de réserve, unicité/appartenance, 20 questions / 19 thèmes / 10 erreurs, priorité de l'équilibre, validation réutilisable et configurations invalides. Un oracle exhaustif vérifie le minimum global sur les 128 sous-ensembles possibles de questions précédentes d'une petite banque.
- `QuizPlayerTest` : parcours HTTP, compteur indépendant de la banque, activation après un passage fixe, tentative initiale renouvelable, réponse hors snapshot, absence de fuite, reprise et rejeu après invalidation éditoriale, correction historique, aucune écriture après refus. Les tests existants de sécurité et complétion restent exécutés.
- `QuizRenewalPersistenceTest` : vrai MySQL, migration additive sur un schéma antérieur reconstitué avec une tentative terminée et une autre commencée (une réponse validée), comparaison exhaustive des lignes avant/après, valeurs par défaut d'un ancien import, reprise et renouvellement après migration. Concordance complète du schéma migré avec le mapping.
- Concurrence : deux processus PHP et connexions MySQL distincts, bloqués derrière un verrou utilisateur tenu par le parent avant libération. Vérification du même identifiant et snapshot complet pour `start` et `restart`, d'un seul slot actif et d'un seul successeur.
- L'ancien script MySQL reste utilisable et teste aussi les réponses concurrentes identiques/contradictoires, la finalisation, l'historique après suppression éditoriale et son ancienne migration up/down.

Les tests MySQL réutilisent `DictationTestDatabase`, helper existant qui crée uniquement une base locale aléatoire `orthogram_dictation_2a_<16 hex>_test`, vérifie `SELECT DATABASE()`, puis la supprime en fin de test. Ce préfixe historique n'implique aucune modification des dictées. Le script historique utilise son propre préfixe `orthogram_quiz_lot2_<16 hex>_test`. Aucun de ces tests ne migre la base configurée de travail. Les tests HTTP utilisent des fichiers SQLite jetables : ils ne constituent pas la preuve de concurrence MySQL.

Résultats de la session : suite ciblée **160 tests, 1 622 assertions, OK**. L'ancien script MySQL a passé les courses start/answer/finish/restart et supprimé sa base. Conteneur, mapping et CS Fixer : OK. PHPStan global : **34 erreurs dans des fichiers non modifiés**, aucune dans les fichiers de ce lot, sans ajout d'exclusion. Composer : valide avec avertissements préexistants sur les contraintes `getid3` et `apache-pack`. Le premier essai avec la limite PHP de 128 Mo a épuisé la mémoire lors de la compilation Symfony ; les essais suivants utilisent 512 Mo.

Suite PHPUnit complète : **1 117 tests, 37 229 assertions, aucun échec, 1 ignoré**, en 4 min 57 s. Le test ignoré est `CourseFilesTest::testEscapingSymlinkIsRefused` : Windows ne permet pas à cet utilisateur de créer le lien symbolique (motif confirmé par une exécution avec `--display-skipped`). Aucune exclusion ajoutée.

Après cette exécution complète, le test HTTP de renouvellement a été enrichi d'assertions sur les compteurs rendus dans le corrigé et l'historique, puis réexécuté : **1 test, 42 assertions, OK** (`--filter testReserveCountersRenewalAndResumeUseOnlyPersistentSnapshot`). Le corrigé affiche « 2 sur 2 », l'historique « 2/2 », avec trois questions dans la banque. Aucun code applicatif n'a changé après la suite complète. La vérification complémentaire `node --check` n'a pas pu être lancée, Node n'étant pas disponible dans cet environnement ; le lecteur JavaScript n'a pas été modifié.

## Migration et recette de l'étape suivante

Les commandes suivantes sont à exécuter **uniquement sur une copie locale de recette distincte**, jamais sur la base de travail durant ce lot. Ne pas lancer globalement les fixtures sur les vrais contenus.

1. Préparer une copie de recette avec le schéma antérieur et un compte de test. Configurer son `DATABASE_URL` dans le processus de recette, sans changer `.env.local`. En environnement `test`, Doctrine ajoute `_test` au nom de base de l'URL : vérifier le nom final, l'hôte local et la séparation avec la base de travail.
2. Avant migration, avec la version précédente sur cette copie : terminer une tentative A ; recommencer une tentative B, valider une réponse puis quitter. Relever IDs, ordre, snapshot, réponses, scores, dates, total et état de complétion. Conserver un export des tables quiz/questions/propositions/cours/tentatives/leçons pour comparaison.
3. Vérifier la base sélectionnée, puis prévisualiser et exécuter **uniquement** cette migration :

```powershell
php bin/console doctrine:query:sql 'SELECT DATABASE() AS recette_database' --env=test
# PowerShell : le nom de classe ci-dessous contient un seul antislash.
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261025120000' --up --dry-run --env=test
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261025120000' --up --env=test
php bin/console doctrine:schema:validate --env=test
```

4. Vérifier que les huit quiz copiés restent fixes, toutes les anciennes questions initiales, mêmes IDs/textes/propositions/positions/corrigés/rattachements. Comparer les exports en ignorant seulement les nouvelles colonnes. Les quatre tailles du module 1 restent 14, 12, 12 et 20 ; constater les tailles existantes des fondamentaux sans les inventer.
5. Ouvrir l'historique et le corrigé de A : aucune différence de score, date ou contenu. Reprendre B : mêmes questions/ordre, réponse validée intacte ; terminer B normalement, sans changement des règles de complétion.
6. Sur un quiz de recette uniquement, ajouter des réserves avec `reserve = true`. Garder `renewalEnabled = false` : introduction et nouveau passage doivent compter/présenter uniquement les initiales.
7. Renseigner les thèmes, éventuellement les catégories et le quota. Appeler `QuizConfigurationValidator::validate` avant activation. Activer sur cette copie par un script ORM de recette ; aucune interface d'activation n'est livrée ici. Un nouvel apprenant reçoit les initiales ; l'apprenant de A/B reçoit un renouvellement dès le prochain redémarrage.
8. Vérifier les quotas par thème et le total ; sur le bilan, 20 questions, 19 thèmes et exactement 10 catégories `true`/10 `false`. Retirer des réserves d'un thème : aucune diminution du nombre de questions. Ajouter une alternative admissible : diminution du nombre de questions reprises lorsque les contraintes le permettent.
9. Rafraîchir, quitter/reprendre, rejouer deux requêtes de redémarrage : même tentative et ordre persistant. Après création, modifier texte et corrigé éditoriaux ; la correction de la tentative reste inchangée. Une question de réserve hors snapshot doit être refusée.
10. Rendre la configuration invalide : un nouveau démarrage/redémarrage doit afficher un message métier, sans nouvelle tentative ; une tentative existante reste reprenable. Vérifier les compteurs dans le lecteur, les résultats, l'historique et le corrigé.

**Portée de la première session** : les vérifications ci-dessus utilisaient des fixtures. Les vérifications complémentaires ci-dessous portent désormais sur une copie des huit quiz réellement présents en local et sur Chrome. La base de travail reste intacte, sans migration ; ne pas lancer le nouveau code contre son ancien schéma avant la migration prévue dans une étape ultérieure.

## Vérifications complémentaires du 25 octobre 2026

Le [rapport structuré](quiz_renewal_lot1_audit.json) conserve les diagnostics PHPStan complets normalisés, les preuves d'identité des dépendances/configurations, l'inventaire des huit quiz, les empreintes de contenu et les résultats navigateur. Aucun code applicatif ni test du lot 1 n'a été modifié pendant ces vérifications. Seuls cette documentation et le rapport ont été ajoutés/actualisés ; les outils temporaires restent sous `var/quiz-renewal-audit/`, ignoré par Git.

### Comparaison PHPStan avec le commit de départ

Checkout local indépendant dans `var/quiz-renewal-audit/baseline`, HEAD détachée sur `73e93c31fc15350dd4a784f7948735cee3de177b`. Aucun changement dans l'arbre courant pour construire ce checkout. Les **15 645 fichiers de `vendor/`** ont été copiés puis comparés par SHA-256, sans différence ni installation de dépendances. `composer.json`, `composer.lock`, `phpstan.dist.neon` et le XML de conteneur de référence `var/cache/dev/App_KernelDevDebugContainer.xml` sont identiques par empreinte. Le chargement de `App\Entity\Quiz` depuis le checkout antérieur a été vérifié par réflexion PHP.

Même PHP 8.4.27 et même commande, exécutée dans chacun des deux répertoires :

```powershell
php -d memory_limit=512M -d xdebug.mode=off vendor/bin/phpstan analyse --no-progress --memory-limit=512M --error-format=json
```

Résultat : **34 diagnostics identiques**, après normalisation du seul préfixe absolu du checkout. Comparaison des chemins relatifs, lignes, messages, identifiants et autres propriétés des diagnostics : **aucun ajouté, supprimé ou modifié**. Les deux commandes retournent le code 1, correspondant aux erreurs déjà présentes. Aucun correctif applicatif, exclusion ou nettoyage général n'est nécessaire pour ce lot.

| Diagnostic | Nombre identique avant/après |
| --- | ---: |
| `missingType.iterableValue` | 21 |
| `class.notFound` | 2 |
| `offsetAccess.notFound` | 7 |
| `property.notFound` | 2 |
| `method.notFound` | 2 |

Les détails par fichier et par ligne figurent dans le rapport JSON. Les erreurs concernent les tests existants de parcours/résultats, la migration des dictées, les calendriers/rappels et des scripts manuels existants. Il ne s'agit pas seulement d'une égalité de nombres : tous les diagnostics sont identiques.

### Copie réelle, migration ciblée et comparaison des données

- Source vérifiée par `SELECT DATABASE()` : **`orthogram`**, hôte **`127.0.0.1:3306`**, MySQL **8.4.7**.
- Destination distincte vérifiée de la même façon : **`orthogram_quiz_renewal_recette_646344e9279b_test`**.
- La connexion source utilise `SET SESSION TRANSACTION READ ONLY`, valeur contrôlée à 1, et une transaction de lecture. La création de la copie utilise une connexion sans base sélectionnée, puis une connexion dédiée à la destination. Aucune écriture ni migration sur la source.
- Copie initiale : **30 tables, 618 lignes**, données identiques et schéma équivalent. `SHOW CREATE TABLE` explicitait seulement un `CHARACTER SET utf8mb4` redondant sur `doctrine_migration_versions` dans la destination ; cette différence d'affichage a été vérifiée, sans différence de charset/collation effective.
- Une tentative terminée existait dans la source copiée ; aucune tentative active. Avec le **code du checkout antérieur**, création d'un compte synthétique local `quiz-renewal-recette@example.test` (ID 5) et préparation sur le cours 63 : tentative **2 terminée, 14/14**, puis tentative **3 en cours, une réponse validée sur 14**. La tentative déjà présente dans la source a été conservée.
- Avant migration : empreintes des **622 lignes** de la copie préparée. Exécution via la commande Symfony de **`DoctrineMigrations\Version20261025120000` uniquement** : une migration, deux ALTER TABLE.
- Après migration : **toutes les données existantes identiques**, en ignorant uniquement les quatre nouvelles colonnes. Le journal Doctrine possède exactement une entrée supplémentaire, celle de cette migration ; ses anciennes entrées sont inchangées. Les autres schémas sont inchangés.
- Contrôle individuel des huit quiz : comparaison de toutes les colonnes des quiz, questions, propositions et de leurs rattachements, avec ordre `(position, id)` ; textes, corrigés, IDs, positions et cours conservés. Toutes les questions ont `reserve = 0`, tous les quiz `renewal_enabled = 0`, les nouvelles métadonnées facultatives sont nulles.

| Quiz réel | ID quiz | ID cours | Questions | Propositions |
| --- | ---: | ---: | ---: | ---: |
| Fondamentaux — Test 1 : reconnaître les natures de mots | 1 | 15 | 10 | 40 |
| Fondamentaux — Test 2 : reconnaître les natures de mots | 2 | 24 | 12 | 48 |
| Fondamentaux — Reconnaître les fonctions dans la phrase | 3 | 25 | 12 | 48 |
| Fondamentaux — Test général | 4 | 26 | 22 | 88 |
| Module 1 — Consolider les premières règles | 5 | 63 | 14 | 28 |
| Module 1 — Distinguer les formes proches | 6 | 64 | 12 | 24 |
| Module 1 — Maîtriser les accords et terminaisons | 7 | 65 | 12 | 24 |
| Module 1 — Test général | 8 | 66 | 20 | 40 |

Total constaté : **114 questions et 340 propositions**, sans réimport. La reprise de la tentative 3 après migration restitue exactement le même état serveur, notamment la réponse validée et son horodatage ; l'état terminé de la tentative 2, les lignes de tentatives et les complétions sont identiques avant/après migration.

### Recette navigateur réellement exécutée

Exécutée dans **Chrome 155.0.8059.86 sans fenêtre**, avec automatisation CDP, DOM rendu, contrôleurs Stimulus réels et clics sur les boutons/propositions. Origine **`http://127.0.0.1:8873`**, profil isolé et débogage limité à la boucle locale. Le serveur utilise `APP_ENV=test`, la copie MySQL vérifiée avant tout accès applicatif, un compte synthétique connecté via KernelBrowser, `MAILER_DSN=null://null` et `MESSENGER_TRANSPORT_DSN=in-memory://`. Aucun worker, tâche externe ou courriel réel n'a été lancé. `.env.local` n'a pas été modifié.

La bibliothèque Google Maps importée globalement par le contrôleur d'adresse existant a dû être neutralisée dans le navigateur de recette : son import est satisfait par un petit module local qui interdit tout appel Google Maps. Sans cette neutralisation, le blocage des accès externes empêche le démarrage de l'ensemble des contrôleurs Stimulus. **Aucun fichier JavaScript applicatif n'a été modifié** ; tous les autres accès hors origine sont bloqués. Le contrôleur d'adresse n'intervient pas dans les scénarios quiz.

Résultats du passage final :

1. **Huit quiz ouverts**, avec titre réel et compteur exact. Démarrage du premier passage pour sept quiz ; reprise du passage antérieur pour le cours 63. Première question affichée conforme à l'ordre attendu. Aucun corrigé futur transmis.
2. **Historique antérieur** de la tentative 2 consulté : un résultat terminé, 14/14. **Corrigé antérieur** : 14 questions, textes historiques et résultat conservés.
3. **Reprise de la tentative 3** : même tentative, une réponse déjà validée et question suivante attendue. Validation des treize réponses restantes par les contrôles de l'interface.
4. **Finalisation** : 14/14, cours terminé, ordre des 14 questions identique au jeu initial.
5. **Redémarrage fixe** : nouvelle tentative **27**, réponse initiale vide, même snapshot complet et même ordre que le passage antérieur. Un rafraîchissement retrouve exactement le même état.
6. Passage final : **aucune erreur HTTP locale, aucune exception JavaScript**. Captures conservées dans `var/quiz-renewal-audit/` : `quiz-1-opened.png` à `quiz-8-opened.png`, `history-before-existing.png`, `correction-before-existing.png`, `resumed-finished.png`, `fixed-restart.png`.

Les premiers essais ont servi à régler le serveur isolé : exception DNS pour la boucle locale, neutralisation de Google Maps, puis autorisation de la route locale POST de mémorisation du dernier cours visité. Après ces réglages, les tentatives du seul compte synthétique ont été restaurées depuis leurs états préparés pour rejouer le parcours. Les captures et le rapport navigateur décrivent le dernier passage complet réussi.

Après navigateur, contrôle en base : historique réel préexistant et tentative 2 inchangés ; première réponse/horodatage de la tentative 3 conservés ; snapshot de finalisation inchangé ; snapshot complet du redémarrage identique. Les huit contenus éditoriaux restent inchangés. Seuls les essais du compte synthétique, son marque-page de dernier cours et l'entrée de migration ont évolué dans la copie ; aucun message de tâche supplémentaire.

Les **30 tables de la source** ont été comparées de nouveau en fin de recette : mêmes empreintes de données et de schémas qu'avant la copie. Les empreintes des fichiers suivis et des modifications locales initiales ont également été vérifiées : aucun changement de code ni de `.env.local`.

Le serveur et Chrome de recette ont été arrêtés. La base de copie, le checkout antérieur et les preuves locales sont conservés pour inspection. Aucun scénario demandé ne reste bloqué faute de navigateur. Cette recette valide la compatibilité en mode fixe ; elle ne constitue pas un test navigateur de l'interface EasyAdmin future, qui n'a pas été commencée.

Pour rejouer le parcours sur **cette même copie**, sans relancer la migration ni recréer les contenus :

```powershell
php -d memory_limit=512M -d xdebug.mode=off var/quiz-renewal-audit/scenario.php reset-synthetic
python var/quiz-renewal-audit/browser.py
php -d memory_limit=512M -d xdebug.mode=off var/quiz-renewal-audit/scenario.php verify-browser
php -d memory_limit=512M -d xdebug.mode=off var/quiz-renewal-audit/database.php final-check
```

Ces outils de recette sont locaux et ignorés par Git. `reset-synthetic` ne restaure que les tentatives/leçons du compte de recette sur la copie vérifiée ; il ne touche jamais aux contenus éditoriaux ni à la source. Le script navigateur démarre/arrête lui-même les deux processus locaux. Ne pas exécuter à nouveau la migration déjà appliquée sur cette copie.

## Ce que le lot 2 devra exposer dans EasyAdmin

- Activation explicite du renouvellement, après validation métier de toute la configuration.
- Rôle initial/réserve, position des initiales et thème ; distinction claire entre taille présentée et taille de banque.
- Catégorie éditoriale à trois états (non renseignée/correcte/erronée) et quota global facultatif, jamais inférés des libellés.
- Synthèse des quotas par thème, du total, des catégories et des erreurs de validation ; réserve insuffisante signalée à titre informatif, sans bloquer une composition faisable.
- Appel du validateur partagé à l'activation et lors des modifications d'un quiz actif, avec affichage des erreurs au formulaire et sans enregistrement partiel. La validation des nouveaux objets peut se faire avant leur attribution d'identifiants ; la création de tentative impose ensuite leur persistance.
- Aucune conversion automatique des numéros documentaires de Caroline en IDs ; aucun import de contenu dans ce lot.

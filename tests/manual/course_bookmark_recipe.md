# Reprise du dernier cours consulté

Utiliser des comptes de recette (abonné actif, administrateur, non-abonné), après application de `Version20261009120000` sur l'environnement de recette.

1. Avec un abonné sans marque-page, ouvrir **Ma formation** : aucun bloc de reprise. Survoler un lien de cours, sans cliquer : toujours aucun marque-page.
2. Consulter le cours A, puis le cours B, même déjà terminé. Revenir dans **Ma formation** : « Reprendre ma formation », section et titre de B, bouton « Reprendre ce cours ». Vérifier dans le réseau un POST `/course/{id}/bookmark` retournant 204 après affichage ; aucun POST lors d'un simple préchargement GET.
3. Ouvrir les résultats, un historique puis une correction : « Reprendre mon cours » ramène à B. Consulter profil/abonnement : B reste mémorisé. Consulter un exercice et un quiz sans les démarrer : la page devient le marque-page, sans nouvelle tentative ni changement des réponses/progression.
4. Se déconnecter, fermer le navigateur, puis se reconnecter et essayer un autre navigateur/appareil avec le même compte : B reste disponible. La connexion conserve sa destination habituelle ; « Ma formation » ouvre le sommaire.
5. Changer de compte, utiliser Précédent/Suivant et revenir aux résultats : aucune donnée de reprise de l'autre compte. Revenir avec le premier compte : retrouver sa reprise. Vérifier aussi une restauration native du navigateur après un changement de compte.
6. Expirer l'abonnement après mémorisation : aucun lien de reprise, ancien lien protégé refusé. Répéter avec compte inactif, visiteur, compte gratuit et 2FA non terminée : aucune mémorisation. Vérifier l'administrateur et le remember-me autorisé.
7. Renommer/déplacer le cours ou modifier les slugs : le lien et les titres suivent les valeurs actuelles. Supprimer le cours : plus de reprise, compte conservé. Anonymiser le compte : référence effacée.
8. À **320 et 390 px**, en thèmes clair/sombre, vérifier le bloc et les liens des trois vues de résultats, les titres longs et l'absence de débordement. Parcourir au clavier (Tab, Entrée), vérifier le focus visible, le fil d'Ariane, le plein écran et les sections repliées. Le sommaire public et les cours gratuits restent accessibles.

Sans JavaScript, la consultation ne met pas à jour la reprise. En cas d'échec réseau du POST, son résultat peut être inconnu (le serveur a éventuellement déjà écrit) : aucune répétition aveugle, la navigation reste disponible et la prochaine consultation utilise le contrôle de version. Aucun défilement ni position média n'est enregistré.

## Ordre asynchrone et fraîcheur de la reprise

Chaque écriture présente la version du marque-page lue dans le lecteur. Le serveur vérifie droits et CSRF puis exécute un seul `UPDATE ... WHERE id = ? AND course_bookmark_version = ?`, qui remplace le cours et incrémente la version. Une ancienne version obtient 409 et la version courante, sans écriture. L'anonymisation efface le cours et avance également la version.

Un coordinateur JavaScript partagé pendant la vie du document Turbo conserve la dernière consultation. Seule celle-ci peut réessayer un conflit (quatre essais au maximum). Une réponse de A arrivée après une consultation B ne relance donc pas A. Une fois B enregistrée, une écriture A déjà partie avec une version antérieure ne peut plus l'écraser. `keepalive` conserve son rôle lors du départ de page ; aucune garantie ne repose sur l'annulation d'une écriture serveur. Lors d'un départ complet du document (`pagehide`), ses nouvelles tentatives cessent.

Entre onglets/appareils indépendants, la dernière écriture atomique acceptée l'emporte. Les versions viennent du serveur, sans timestamp client, ni ordre global prétendu des instants de consultation. Une concurrence continue peut épuiser les quatre essais ; le marque-page confirmé reste utilisable. Les autres documents déjà ouverts ne sont pas actualisés en temps réel : leur prochaine navigation relit le serveur.

Lors d'une navigation Turbo vers les résultats ou le sommaire, une confirmation plus récente que le bloc affiché provoque une relecture de la page et le remplacement du seul bloc de reprise. Cela couvre aussi une page HTML ancienne livrée après la confirmation. Le compte est contrôlé avant remplacement. Si cette relecture échoue, le lien périmé est masqué, sans bouton inactif ; les liens de navigation habituels restent disponibles. Cette actualisation d'une écriture en vol concerne le document Turbo courant, pas une autre page ouverte indépendamment ou par une navigation complète.

## Reproduction navigateur avec barrières contrôlées

Les scripts `course_bookmark_order_browser_check.ps1` et `course_bookmark_order_scenarios.js` pilotent Chrome via CDP sur des fixtures locales. Ils retiennent A **avant** l'appel réseau jusqu'à la confirmation de B et vérifient l'état serveur par une lecture indépendante. Un autre cas retient seulement la livraison de la réponse de A, après confirmation de son écriture : ce second cas ne reproduit pas l'écrasement. Les essais couvrent aussi un HTML de lecteur ancien, les deux ordres de livraison POST/page de résultats ou sommaire, les erreurs réseau et un changement de compte pendant un POST.

Préparer un routeur jetable à partir des fixtures existantes (aucun endpoint de test n'est ajouté à l'application) :

```powershell
$fixture = 'course-bookmark-order-' + [Guid]::NewGuid().ToString('N')
$router = (Get-Content tests/manual/courses_lot3_browser_router.php -Raw).Replace('dirname(__DIR__, 2)', 'dirname(__DIR__)').Replace('courses-lot3-browser', $fixture).Replace('8813', '8823')
Set-Content var/course-bookmark-order-router.php $router
php -d xdebug.mode=off -S 127.0.0.1:8823 -t public var/course-bookmark-order-router.php
```

Dans un second terminal, démarrer Chrome avec un profil isolé et exécuter le contrôle :

```powershell
Start-Process 'C:\Program Files\Google\Chrome\Application\chrome.exe' -WindowStyle Hidden -ArgumentList '--headless=new','--remote-debugging-port=8824','--remote-debugging-address=127.0.0.1',"--user-data-dir=$PWD\var\bookmark-order-chrome",'about:blank'
./tests/manual/course_bookmark_order_browser_check.ps1
```

L'option `-BeforeFix` vérifie les deux défauts sur le code de la première archive, avec des fixtures séparées ; elle est volontairement incompatible avec le code corrigé. Les journaux de reproduction avant/après sont inclus dans l'archive de revue.

## Précision sur l'horloge du précédent compte rendu

Aucune implémentation d'horloge n'a été modifiée. La première intégration, non livrée, injectait `CourseBookmarkService` comme argument de `ProgramSummaryController::__invoke`. La résolution des arguments Symfony passait alors par le résolveur DateTime, initialisant le service `Psr\Clock\ClockInterface`. Dix-neuf tests de calendrier ne pouvaient ensuite plus remplacer cette horloge par leur `MockClock` après avoir chargé le sommaire. Le déplacement de cette injection dans le constructeur a supprimé cette initialisation incidente. Le diff consolidé montre donc uniquement l'ajout du service au constructeur, car la variante intermédiaire erronée n'a jamais été commitée. Les 45 tests du contrôleur de rappels sont compris dans la suite complète exécutée.

## Contrôles automatisés locaux

```powershell
vendor\bin\phpunit tests/Controller/Course/CourseBookmarkTest.php --no-progress
php tests/manual/verify_course_bookmark_migration.php
php -d xdebug.mode=off tests/manual/run_course_bookmark_suite.php
php bin/console lint:twig templates/course templates/quiz_results
php bin/console lint:container
git diff --check
```

Les tests ciblés créent des fichiers SQLite isolés. Les deux scripts MySQL refusent tout serveur distant, créent une base au nom aléatoire, vérifient la cible effective puis la suppriment. Le premier vérifie les SQL réels de la migration, la conservation des lignes préexistantes et les suppressions dans les deux sens. Le second utilise le schéma Doctrine actuel pour toute la suite, sans migrer la base de développement ni la base de test habituelle.

## Déploiement après revue et validation manuelle

Confirmer la branche réellement servie par Hostinger et le commit à publier, puis sauvegarder la base. La migration ajoute `user.last_visited_course_id` nullable, son index, une clé étrangère `ON DELETE SET NULL` et `course_bookmark_version` non nullable à zéro ; tous les comptes existants démarrent sans marque-page. La migration de ce lot non déployé a été actualisée ; si sa première version avait été appliquée dans un environnement de recette extérieur à cette session, sa réexécution automatique ne suffirait pas à ajouter la version. Le DDL MySQL n'est pas transactionnel et peut verrouiller la table `user` pendant son exécution : prévoir la fenêtre habituelle.

Déployer le code validé et les assets via le processus existant. `ops/deploy_beta.sh` applique déjà les migrations : ne pas les doubler et ne pas supposer que le script récupère une branche Git. Terminer par les contrôles rapides des étapes 1 à 5. En retour arrière, remettre d'abord le code précédent ; `down` supprime uniquement les deux colonnes, l'index et la contrainte du marque-page, donc perd les reprises enregistrées sans supprimer les cours ou les comptes.

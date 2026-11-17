# Ouverture guidée de la sidebar — recette locale

À tester avec des comptes et contenus de recette, sur ordinateur puis à 390 px.

1. Sur le sommaire d'un utilisateur connecté, seule la première section accessible contenant des cours non terminés est ouverte. Les sections vides et inaccessibles restent repliées. En anonyme, seules « À propos de cette formation » et « Les fondamentaux » sont ouvertes, reconnues par leurs slugs `a-propos-de-cette-formation` et `les-fondamentaux` ; toutes les autres sections sont repliées. La liste centrale est absente pour tous les profils ; les cours gratuits restent accessibles dans la sidebar.
2. Consulter un cours connecté : seule sa section est ouverte par défaut, même terminée. En anonyme, conserver les deux sections publiques ouvertes, même si le cours gratuit consulté appartient à un module différent. Le nom et le chevron d'une section constituent un seul bouton de dépliage, sans navigation. Une ancienne URL de section redirige en 302 vers le sommaire du même programme, après contrôle des autorisations. Vérifier également un lien direct, la recherche et « Reprendre ma formation ».
3. Ouvrir plusieurs autres sections, puis fermer la section courante. Valider un cours sans terminer sa section : le cours reste affiché, les compteurs changent et tous les choix manuels sont conservés. Faire des choix différents dans les menus ordinateur et mobile ; le fragment masqué conserve aussi ses choix.
4. Valider le dernier cours restant : seule cette section se ferme et la prochaine section accessible non terminée contenant des cours s'ouvre, sans navigation. Les autres ouvertures sont conservées. Une section suivante déjà terminée ou vide est sautée. Il n'y a pas de retour automatique vers une section antérieure.
5. Réussir un exercice terminant sa section : le corrigé reste affiché et la prochaine section s'ouvre. Recommencer un exercice déjà réussi, avec succès ou échec : aucune nouvelle transition. Terminer un quiz, même à score nul : son bilan reste affiché et la progression est actualisée. Recommencer le quiz ne rejoue pas la transition. Une fin de quiz enregistrée dont la réponse réseau est perdue doit être récupérée sans rechargement.
6. Pour un compte connecté, quand aucune section suivante n'est à poursuivre, ne rien ouvrir. Si toute la formation est terminée, le sommaire reste replié ; une visite volontaire d'un cours terminé ouvre sa section. Une section vide peut toujours être dépliée manuellement ; son ancienne URL revient au sommaire sans sélectionner un cours.
7. Recharger, naviguer, puis utiliser Retour/Avance : les états par défaut sont recalculés et la progression est relue. Les pages conservent `turbo-cache-control=no-cache` et la protection existante contre le cache natif du navigateur. Aucune mémorisation durable des ouvertures.
8. Vérifier Tab, Entrée et Espace : une seule bascule, `aria-expanded`, classe `collapsed` et chevrons cohérents. Les identifiants ordinateur/mobile sont distincts. Tester aussi Échap, le clic extérieur et les liens du menu mobile.
9. Vérifier une erreur réseau ou un CSRF invalide : aucun repli anticipé. Cliquer sur « Terminé » pour annuler une validation manuelle : la progression change sans forcer l'ouverture. Ajouter un cours à une section terminée : les critères de complétion existants continuent de s'appliquer. Les entrées de dictée, leurs prérequis et les liens de découverte restent disponibles selon les autorisations existantes.

## Recette automatisée

`node tests/manual/sidebar_browser_check.cjs [chemin-vers-Chrome]`

Le lanceur nécessite Node 22+, PHP et Chrome déjà installés, sans paquet npm. Il lance Chrome sans fenêtre et un serveur PHP sur les ports locaux 8851/8852. Chaque exécution crée une base SQLite, un profil Chrome et un rapport sous `var/sidebar-browser-<identifiant>/`. Le routeur refuse un lancement sans identifiant isolé et vérifie le chemin réel de la base avant toute écriture. Les processus lancés sont arrêtés en fin de recette. La base de développement n'est pas utilisée.

Sur ce poste, Node est disponible via `C:\Program Files\Adobe\Adobe Photoshop 2026\node.exe`, sans être dans le PATH.

## Ouverture initiale anonyme — 17 novembre 2026

Départ sur `preprod`, HEAD `e58fab8`, état de travail propre. Aucun commit, push, déploiement ou migration. Les deux slugs ont été confirmés par une requête Doctrine de lecture seule sur les noms/slugs des sections et du programme.

Le seul changement applicatif est dans `templates/course/program_summary/_sidebar_content.html.twig` : si `app.user is null`, l'ouverture dépend exclusivement des deux slugs. Tous les comptes connectés, y compris sans abonnement et remember-me, conservent l'expression précédente `expandedSectionId == section.id`. Aucun ID numérique, nom affiché, position, droit d'abonnement ou statut de cours ne sert à choisir les deux sections anonymes. Les mécanismes Bootstrap/Stimulus, les classes des chevrons et `aria-expanded` restent les mêmes.

L'audit de `course_sidebar_controller.js` et `course_sidebar.js` confirme que le premier écoute les événements Bootstrap pour synchroniser les boutons ; le second intervient sur les mises à jour de progression. Aucun des deux ne force l'ouverture des modules au chargement. Ils n'ont pas été modifiés.

Fichiers modifiés : le template partagé, `tests/Controller/Course/FreeCoursesTest.php`, `tests/manual/sidebar_browser_check.cjs`, `tests/manual/sidebar_browser_router.php` et cette recette. Les fixtures placent les deux sections après les modules ; le test PHP renomme leurs libellés et vérifie les deux variantes de menu sur le sommaire et le lecteur gratuit, avec sept profils. Le routeur de recette ajoute les deux sections vides sans changer la progression des comptes connectés.

Recette courte : en anonyme, ouvrir le sommaire à 1440 puis 390 px ; vérifier les deux sections ouvertes et tous les modules fermés. Fermer puis rouvrir les deux sections publiques et un module, avec la souris puis Entrée/Espace. Le menu mobile reste ouvert. Suivre un cours gratuit d'un module puis revenir par le breadcrumb : mêmes ouvertures initiales. Rejouer connecté sans abonnement, abonné et administrateur : ouvertures guidées et section courante inchangées.

Vérifications exécutées :

- PHPUnit sur `FreeCoursesTest.php` et `SidebarCompletionTest.php` : **56 tests, 3 472 assertions, tous réussis**, sur bases SQLite temporaires. Couverture des profils anonyme, sans abonnement, abonnement expiré/futur, remember-me, abonné et administrateur ; états des deux menus, boutons/aria/chevrons, accès et navigation. Journal : `var/sidebar-anonymous-tests.log`.
- Chrome sans fenêtre : **245 contrôles réussis**, avec quatre profils, 1440/390 px et thèmes clair/sombre. Vérification des deux seules ouvertures après initialisation JavaScript, même dans le lecteur d'un autre module ; fermetures/réouvertures des sections publiques et des autres sections ; clavier Entrée/Espace, menu mobile maintenu ouvert, cours gratuit puis retour au sommaire. Les contrôles connectés de progression, quiz/exercice, mode agrandi, accès réservés et absence de sommaire central restent réussis. Rapport : `var/sidebar-browser-d74da7161026/report.json` ; journal : `var/sidebar-anonymous-browser.log`.
- Lint Twig du template modifié, syntaxe JavaScript du scénario, PHP CS Fixer en simulation sur les deux fichiers PHP modifiés et `git diff --check` : sans erreur.

Non exécutés pour ce changement : suite PHPUnit intégrale, parcours complet de dictée, téléphone physique, Firefox/Safari et lecteur d'écran. Aucun changement des autorisations, du filtrage de publication, des prérequis de dictée ou du JavaScript applicatif.

## Historique du complément du lot 2 — 17 novembre 2026 : tous les profils

Cette consigne remplace la conservation de la liste centrale en découverte décrite dans l'historique du 16 novembre ci-dessous. Travail poursuivi sur `preprod`, HEAD `8902e4f`, en préservant les 17 fichiers déjà modifiés. Aucun commit, push, déploiement ou migration.

### Changements

- `templates/course/program_summary/_main.html.twig` : suppression intégrale du bloc central, de son titre, des compteurs, cartes, listes et espace final, sans condition de profil. Présentation, boutons de découverte et conditions des éléments réservés conservés.
- `templates/course/program_summary/_sidebar_content.html.twig` : même bouton natif pour chaque section de chaque profil, nom et chevron réunis ; aucune ancre centrale. Ouverture initiale, liens de cours et dictées, badges, cadenas et autorisations inchangés.
- `assets/styles/courses.css` : suppression des styles propres au titre du bloc supprimé.
- `src/Controller/Course/ProgramSummaryController.php` : suppression du comptage par section et de la variable Twig utilisés uniquement par la liste centrale. Le total nécessaire à la progression est conservé.
- `tests/Controller/Course/FreeCoursesTest.php` : découverte attendue sans liste centrale, assertions de présentation/boutons/badges/cadenas, bouton unique dans les deux sidebars, absence d'ancres.
- `tests/manual/sidebar_browser_check.cjs`, `tests/manual/sidebar_browser_router.php` : quatre identités de recette distinctes (anonyme, sans abonnement, abonné, administrateur), conservées durant les clics et requêtes, avec sessions séparées sur une base SQLite jetable ; contrôles souris/clavier et accès directs pour chacune.
- Le présent document actualise la recette. Les autres modifications locales du lot 2 sont conservées.

### Recette locale courte

1. Tester `/courses/{slug}` en anonyme, sans abonnement, avec abonnement et administrateur, à 1440 px puis 390 px. Aucune liste centrale ni titre « Contenu de la formation ». La présentation reste visible ; « Découvrir l’offre » et « Se connecter » gardent leurs conditions existantes. Progression, reprise et planification restent réservées aux profils autorisés.
2. En anonyme, vérifier que toutes les sections sont ouvertes au départ. Pour chaque profil, cliquer le nom puis le chevron et tester Tab, Entrée et Espace : une seule bascule, URL inchangée, état aria cohérent. Le menu mobile doit rester ouvert durant ces bascules.
3. Sans accès au programme, vérifier les badges « Gratuit », cadenas et mentions « Réservé aux abonnés ». Ouvrir un cours gratuit par la sidebar, puis tenter une URL réservée : lecture gratuite autorisée, contenu réservé refusé. Les cours dépubliés restent absents.
4. Avec accès, vérifier `/ma-formation`, les retours au sommaire par breadcrumb/sidebar, « Rejeter » et son focus sur la présentation, ainsi que le mode agrandi. Les anciennes URL de section doivent rediriger en 302 vers leur programme après autorisation ; programme/section incohérents restent en 404.
5. Pour les exercices et quiz, vérifier le breadcrumb simplifié et la progression sans rechargement. Tester en clair et sombre. Les tests automatisés utilisent uniquement les données de recette.

### Audit des références

Il ne reste aucune référence de rendu ou de style à `.program-course-content`, à son titre ou aux ancres de cours `#section-…`. Ces noms ne restent que dans les assertions d'absence et l'historique documentaire. Les ancres `#section-avatar`, `#section-access`, etc. de l'écran de profil sont indépendantes et conservées. `app_course_section` ne reste que comme route de compatibilité ; le breadcrumb simplifié et la cible de focus `overview` restent en place.

### Vérifications réellement exécutées le 17 novembre

- **PHPUnit : 230 tests, 4 812 assertions, tous réussis.** Suites FreeCourses, SidebarCompletion, CoursePublication, LearningReminderController, CourseBookmark, ExerciseCompletion, QuizPlayer, CourseInteraction, SectionCompletionService et CourseVoter. Les tests de contrôleurs utilisent des bases SQLite temporaires ; aucune migration n'a été exécutée. Les profils anonyme, sans abonnement, expiré/futur, remember-me, abonné et administrateur sont couverts par les tests appropriés. Journal : `var/lot2-all-profiles-tests.log`.
- **Chrome sans fenêtre : 221 contrôles réussis.** Les quatre profils demandés ont chacun été vérifiés sur le sommaire et le lecteur gratuit, à 1440 et 390 px : absence du bloc et des ancres, état initial des sections, clic réel sur nom et chevron, Entrée/Espace, cohérence aria, maintien du menu mobile, badges/cadenas, accès au cours gratuit par lien et refus des accès directs réservés. Les vérifications de progression, exercice, quiz, focus « Rejeter », reprise, redirection de section, retour Turbo et mode agrandi restent vertes. Clair/sombre sans débordement. Rapport : `var/sidebar-browser-4f9d364e83be/report.json`, journal : `var/lot2-all-profiles-browser.log`.
- Captures générées pour les quatre profils, les deux largeurs et les deux thèmes. Inspection visuelle effectuée sur `anonymous-desktop-light.png` et `unsubscribed-mobile-dark.png` dans le répertoire du rapport.
- **Twig : 46 fichiers valides** dans `templates/course` et `templates/dictation`. Syntaxe JavaScript du scénario navigateur et PHP du routeur valides. PHP CS Fixer en simulation sur ProgramSummaryController, FreeCoursesTest et le routeur : aucune correction. `git diff --check` sans erreur.

Pour reproduire les tests PHP, utiliser `php -d memory_limit=512M -d max_execution_time=0 -d xdebug.mode=off vendor/bin/phpunit` suivi des fichiers des dix suites ci-dessus. Pour le navigateur, exécuter la commande de la section « Recette automatisée » ; les quatre profils sont inclus dans ce même scénario.

Non effectués dans ce complément : suite PHPUnit intégrale, scénario complet de dictée dans le navigateur, téléphone physique, Firefox/Safari et lecteur d'écran. Aucun changement n'a été apporté aux prérequis de dictée. Les vérifications de la veille restent consignées ci-dessous avec leur date, sans être présentées comme rejouées aujourd'hui.

## Historique du lot 2 — 16 novembre 2026 (périmètre remplacé ci-dessus)

Travail local sur `preprod`, HEAD `8902e4f`, état initial propre ; aucun commit, push ou déploiement. Les quatre captures mentionnées dans la demande n'étaient pas accessibles dans le fil de travail.

Le sommaire utilise le booléen `canInteract` existant, fourni par `ProgramSummaryController` à partir de `PROGRAM_VIEW`. Son contrôleur ne nécessite pas de modification. La liste centrale et son titre ne sont plus rendus pour les comptes ayant accès au programme, sur `/ma-formation` comme sur `/courses/{slug}`. La découverte et ses ancres de sections restent disponibles aux autres profils. Le séparateur et l'espacement sous la planification sont retirés. Le retour de focus de « Rejeter » cible la présentation visible et nommée, même si le programme n'a pas de description (la progression reste présente).

### Recette courte

Fichiers modifiés dans ce lot :

- Rendu : `templates/course/program_summary/_main.html.twig`, `templates/course/program_summary/_sidebar_content.html.twig`, `templates/course/show/_main.html.twig`.
- Navigation : `src/Controller/Course/SectionViewController.php`, `src/Controller/Course/DictationController.php`, `templates/dictation/closed.html.twig`, `templates/dictation/completed.html.twig`.
- Interaction et styles : `assets/controllers/learning_reminder_controller.js`, `assets/styles/courses.css`.
- Tests PHP : `tests/Controller/Course/FreeCoursesTest.php`, `SidebarCompletionTest.php`, `CoursePublicationTest.php`, `DictationJourneyTest.php`, `LearningReminderControllerTest.php` dans le même répertoire.
- Recettes : `tests/manual/sidebar_browser_check.cjs`, `tests/manual/dictation_lot6_browser_check.cjs`, `tests/manual/sidebar_completion_recipe.md`.

1. Avec un compte de recette ayant accès au programme, ouvrir `/ma-formation`, puis revenir au sommaire par le breadcrumb et par la sidebar : aucune liste centrale, mais présentation, progression, reprise et planification présentes.
2. À 1440 px puis 390 px, cliquer le nom et le chevron d'une section ; tester Tab, Entrée et Espace. Une seule bascule, aucun changement d'URL, aucune fermeture du menu mobile. Les liens des cours et dictées restent navigables.
3. Tester « Rejeter » : carte masquée, focus visible sur la présentation, aucune erreur JavaScript. Répéter sans description et sans cours repris.
4. Lire un cours, un exercice et un quiz : breadcrumb « Accueil / Sommaire / Cours », titre conservé. Tester précédent/suivant, reprise, signet et actualisation de progression après validation.
5. Ouvrir une ancienne URL de section : 302 vers son propre programme, sans cours choisi. Tester section vide, programme/section incohérents (404), compte sans accès (403 ou connexion selon la session).
6. Vérifier le sommaire sans connexion et avec un compte sans abonnement : découverte et liste centrale présentes, aucun contenu réservé accessible.
7. En clair/sombre et mode agrandi, vérifier lisibilité, focus, absence de débordement et sortie par le bouton ou Échap. Utiliser uniquement les données de recette pour les validations.

### Vérifications réellement exécutées

- PHPUnit : **257 tests, 5 469 assertions, tous réussis** sur FreeCourses, SidebarCompletion, CoursePublication, DictationJourney, LearningReminderController, CourseBookmark, ExerciseCompletion, QuizPlayer, CourseInteraction, SectionCompletionService et CourseVoter. Journal : `var/lot2-final-tests.log`.
- LearningReminderControllerTest utilise désormais SQLite en mémoire avec le schéma courant et une horloge simulée initialisée avant les services ; sa précédente dépendance à une base de test persistante obsolète provoquait des erreurs `is_published`. Aucune migration de la base applicative. Les recettes de dictées utilisent leurs bases MySQL jetables habituelles.
- Twig : les 46 fichiers de `templates/course` et `templates/dictation` sont valides. Syntaxe JavaScript : contrôleur de planification et deux scripts navigateur. PHP CS Fixer en simulation sur les sept fichiers PHP modifiés : aucune correction. `git diff --check` sans erreur.
- Chrome sans fenêtre : **73 contrôles réussis**, vrais Bootstrap/Stimulus/Turbo et fixtures isolées. Souris sur noms/chevrons, clavier Entrée/Espace, menu mobile maintenu ouvert, focus après rejet, navigation de section, validation partielle/complète, exercice et quiz, résultat conservé, retour Turbo, clair/sombre, agrandissement, découverte. Rapport : `var/sidebar-browser-a8206888eee4/report.json` ; captures dans le même répertoire. Deux captures apprenant (ordinateur sombre et mobile clair) ont été inspectées ; le séparateur résiduel observé a ensuite été supprimé.

### Vérifications restant à faire

Le scénario navigateur complet de dictée a été adapté à la sidebar et au retour au sommaire, mais n'a pas pu démarrer dans l'environnement restreint. L'inspection supplémentaire des processus n'a pas été autorisée ; le serveur et sa base temporaire ont été nettoyés (`var/lot2-dictation-server.log`). Les prérequis, états de dictée et liens de retour sont validés par PHPUnit, pas par un parcours navigateur complet dans cette intervention.

Restent la comparaison avec les quatre captures d'origine, la recette avec les vrais contenus longs, un téléphone physique, Firefox/Safari et un lecteur d'écran. Les captures Chrome précèdent le retrait final du séparateur sous la planification. La suite PHPUnit intégrale du dépôt n'a pas été exécutée.

## Vérifications effectuées le 29 octobre 2026

- PHPUnit : 174 tests, 3 385 assertions, sans échec, sur SidebarCompletion, ExerciseCompletion, QuizPlayer, FreeCourses, DictationJourney, CourseInteraction et CourseBookmark. Après ajout du cas découverte inaccessible/vide : 36 tests, 817 assertions, sans échec, sur FreeCourses et SectionCompletionService (les tests FreeCourses communs sont rejoués ; 177 tests distincts au total).
- Chrome réel : 27 contrôles réussis sur les parcours ordinateur et largeur mobile, validations manuelles partielles/complètes, section vide/terminée sautée, exercice et nouvelle tentative, fin de quiz (y compris réponse perdue et nouvelle tentative), formation entièrement terminée, accès direct, page de section, rechargement, retour Turbo, clavier Entrée/Espace, chevrons et identifiants uniques. Rapport : `var/sidebar-browser-8dce75c075f6/report.json`.
- Twig, PHPStan ciblé et PHP CS Fixer ciblé : contrôles réussis. Les critères métier de SectionCompletionService sont inchangés.

La recette navigateur utilise des données synthétiques. Elle ne constitue pas une recette visuelle exhaustive de tous les contenus de formation ni de tous les navigateurs. Les contrôles de dictée et de marque-page sont couverts par les tests PHP existants ; leurs parcours complets et la recherche n'ont pas été rejoués visuellement dans cette recette. La suite PHPUnit intégrale du dépôt n'a pas été exécutée. Les tests ont utilisé PHP avec `-d memory_limit=512M -d xdebug.mode=off`, après dépassement de la limite locale de 128 Mo lors du premier lancement.

## Fichiers concernés

- `templates/course/program_summary/_sidebar_content.html.twig` : état initial partagé et métadonnées de complétion/accessibilité.
- `assets/js/course_sidebar.js`, `assets/controllers/course_sidebar_controller.js` : remplacement des fragments, détection de transition et synchronisation des boutons.
- `src/Services/Courses/CourseProgressRenderer.php` : rendu commun des fragments ordinateur/mobile.
- Contrôleurs PHP CourseConfirmation, Exercise et Quiz ; contrôleurs Stimulus course_completion, click_words et quiz ; template `_completion_button.html.twig` : actualisation sans navigation.
- Tests SidebarCompletion, ExerciseCompletion, QuizPlayer, FreeCourses, lanceur et routeur de recette navigateur, présent document.

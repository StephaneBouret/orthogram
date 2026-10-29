# Ouverture guidée de la sidebar — recette locale

À tester avec des comptes et contenus de recette, sur ordinateur puis à 390 px.

1. Sur le sommaire d'un nouvel utilisateur, seule la première section accessible contenant des cours est ouverte. Les sections vides et inaccessibles restent repliées. En découverte publique, une section contenant un cours gratuit reste accessible. Aucun nom, slug ou identifiant pédagogique n'est codé en dur.
2. Consulter un cours ou une section : seule sa section est ouverte par défaut, même terminée. Vérifier également un lien direct, la recherche et « Reprendre ma formation ».
3. Ouvrir plusieurs autres sections, puis fermer la section courante. Valider un cours sans terminer sa section : le cours reste affiché, les compteurs changent et tous les choix manuels sont conservés. Faire des choix différents dans les menus ordinateur et mobile ; le fragment masqué conserve aussi ses choix.
4. Valider le dernier cours restant : seule cette section se ferme et la prochaine section accessible non terminée contenant des cours s'ouvre, sans navigation. Les autres ouvertures sont conservées. Une section suivante déjà terminée ou vide est sautée. Il n'y a pas de retour automatique vers une section antérieure.
5. Réussir un exercice terminant sa section : le corrigé reste affiché et la prochaine section s'ouvre. Recommencer un exercice déjà réussi, avec succès ou échec : aucune nouvelle transition. Terminer un quiz, même à score nul : son bilan reste affiché et la progression est actualisée. Recommencer le quiz ne rejoue pas la transition. Une fin de quiz enregistrée dont la réponse réseau est perdue doit être récupérée sans rechargement.
6. Quand aucune section suivante n'est à poursuivre, ne rien ouvrir. Si toute la formation est terminée, le sommaire reste replié ; une visite volontaire d'un cours ou d'une section terminée ouvre sa section. Une section vide peut toujours être dépliée manuellement ou consultée directement.
7. Recharger, naviguer, puis utiliser Retour/Avance : les états par défaut sont recalculés et la progression est relue. Les pages conservent `turbo-cache-control=no-cache` et la protection existante contre le cache natif du navigateur. Aucune mémorisation durable des ouvertures.
8. Vérifier Tab, Entrée et Espace : une seule bascule, `aria-expanded`, classe `collapsed` et chevrons cohérents. Les identifiants ordinateur/mobile sont distincts. Tester aussi Échap, le clic extérieur et les liens du menu mobile.
9. Vérifier une erreur réseau ou un CSRF invalide : aucun repli anticipé. Cliquer sur « Terminé » pour annuler une validation manuelle : la progression change sans forcer l'ouverture. Ajouter un cours à une section terminée : les critères de complétion existants continuent de s'appliquer. Les entrées de dictée, leurs prérequis et les liens de découverte restent disponibles selon les autorisations existantes.

## Recette automatisée

`node tests/manual/sidebar_browser_check.cjs [chemin-vers-Chrome]`

Le lanceur nécessite Node 22+, PHP et Chrome déjà installés, sans paquet npm. Il lance Chrome sans fenêtre et un serveur PHP sur les ports locaux 8851/8852. Chaque exécution crée une base SQLite, un profil Chrome et un rapport sous `var/sidebar-browser-<identifiant>/`. Le routeur refuse un lancement sans identifiant isolé et vérifie le chemin réel de la base avant toute écriture. Les processus lancés sont arrêtés en fin de recette. La base de développement n'est pas utilisée.

Sur ce poste, Node est disponible via `C:\Program Files\Adobe\Adobe Photoshop 2026\node.exe`, sans être dans le PATH.

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

# Publication des cours — lot 1

Base contrôlée le 16 novembre 2026 : checkout `preprod` propre avant intervention ; `main` et `preprod` sur GitHub au commit `ae81ca060b97956f629d708466264cc284009fe7`.

## Comportement

- `Courses.isPublished` est un booléen non nullable, vrai par défaut en PHP et en base. Il est indépendant de `isFree` et concerne tous les types de cours.
- EasyAdmin affiche « Publié » en liste. La modification passe par le formulaire existant, avec ses permissions administrateur et son CSRF. Les bascules AJAX sont refusées. Un changement de publication seul ne réordonne pas les cours.
- Les cours dépubliés restent administrables. Le voter maintient l'accès des administrateurs actifs et complètement authentifiés, sans nouvelle prévisualisation.
- Le parcours utilise `Sections.getPublishedCourses()` pour les listes, sidebars et durées. Les requêtes de compteurs, navigation, recherche et validations comptabilisées filtrent aussi la publication. Les collections Doctrine restent complètes : aucun filtre global, aucune association supprimée, aucune modification des requêtes de réordonnancement.
- Les validations et tentatives restent en base. Une republication réintègre les validations acquises ; un cours jamais validé reste à terminer. Le numérateur et le dénominateur de progression suivent le même périmètre.
- Les accès directs, médias GET/HEAD/Range, confirmations, commentaires, signets et quiz passent par le contrôle d'accès serveur. Un apprenant connecté reçoit un refus 403 ; les redirections d'authentification existantes restent applicables aux visiteurs sans session.
- Un exercice lié uniquement à des cours indisponibles est inaccessible, même sans `courseId`. Un exercice partagé est accessible via un cours publié réellement autorisé, y compris ses prérequis de dictée. Une soumission sans contexte ne valide aucun cours ; un contexte dépublié est refusé avant toute tentative ou validation. Un exercice sans aucun cours associé conserve son accès autonome selon les droits existants.
- La reprise conserve son signet historique. S'il est indisponible, elle propose le premier cours publié accessible dans l'ordre du même programme. Si aucun n'est accessible, les liens existants conduisent au sommaire. La simple consultation du repli ne remplace pas le signet ; la visite effective d'un cours utilise le mécanisme habituel de mémorisation.
- Les résultats personnels de quiz restent consultables sous forme de scores historiques. Les liens de lecture/reprise et les corrections protégées suivent le voter : ils ne rouvrent pas un cours dépublié.

## Sections vides et dictées

Une section sans cours publié reste visible avec son état vide, compte zéro cours et zéro minute, et n'est pas considérée comme terminée. Un programme vide ne présente pas de barre de progression ni de lien de reprise vers un cours indisponible.

Cette règle conserve le comportement existant des sections vides : la dictée initiale reste accessible selon ses règles habituelles ; sa reprise et sa finalisation après les cours attendent au moins un cours publié et la validation de tous les cours publiés. Dépublier le dernier cours ne clôture donc pas artificiellement cette étape. Dans une section non vide, les exercices dépubliés ne bloquent plus la complétion. Les validations et signets de cours dépubliés ne comptent pas non plus comme un démarrage des cours dans `DictationCourseProgress`. Les tentatives et prérequis de dictée existants ne sont pas supprimés.

## Migration

`migrations/Version20261116120000.php` cible MySQL/MariaDB et contient uniquement :

```sql
ALTER TABLE courses ADD is_published TINYINT DEFAULT 1 NOT NULL;
```

Tous les cours existants restent publiés. Aucun contenu, lien, résultat ou ordre n'est modifié. La migration n'a pas été appliquée à la base de travail : les exécutions de vérification utilisent uniquement des bases jetables.

Lors d'une application ultérieure validée, sauvegarder la base et vérifier la destination ainsi que les migrations en attente. Prévisualiser cette version, puis l'appliquer avant de servir le code qui utilise la colonne. Le DDL MySQL/MariaDB n'est pas transactionnel. Le retour arrière retire uniquement la colonne mais perd les choix de publication ; avec l'ancien code, tous les cours redeviennent visibles. Aucune commande de déploiement ou intervention Hostinger n'est incluse dans ce lot.

## Vérifications reproductibles

Fichiers concernés :

- Modèle et administration : `src/Entity/Courses.php`, `src/Entity/Sections.php`, `src/Controller/Admin/CoursesCrudController.php`.
- Parcours : `src/Repository/CoursesRepository.php`, `src/Repository/LessonRepository.php`, `src/Form/CoursesAutocompleteField.php`, les services `SectionCompletionService`, `SectionDurationService`, `CourseBookmarkService` sous `src/Services/Courses/`.
- Autorisations et prérequis : `src/Security/Voter/CourseVoter.php`, `src/Services/ExerciceAttemptService.php`, `src/Services/QuizAttemptService.php`, `src/Services/Dictation/DictationCourseProgress.php`, `src/Twig/DictationJourneyExtension.php`.
- Vues : `templates/course/_dictation_entry.html.twig`, `templates/course/program_summary/_main.html.twig`, `templates/course/program_summary/_sidebar_content.html.twig`, `templates/course/section/_main.html.twig`.
- Tests : nouveaux `tests/Controller/Course/CoursePublicationTest.php` et `tests/Integration/CoursePublicationMigrationTest.php` ; extensions de `CoursesFreeAccessTest`, `CourseFilesTest`, `ExerciseCompletionTest` et `CourseVoterTest`.

Les contrôleurs de lecture, recherche, confirmation, commentaires, médias et quiz, ainsi que `CourseProgressRenderer`, bénéficient de ces règles via leurs repositories, leurs services et le voter existants. `SectionsRepository` conserve intentionnellement ses collections complètes.

Les tests HTTP de publication créent une base SQLite nommée aléatoirement sous `var/publication-*.sqlite`, vérifient sa cible effective avant toute écriture et la suppriment en fin de test. Le test de migration utilise `DictationTestDatabase`, qui crée et supprime exclusivement une base MySQL locale aléatoire `orthogram_dictation_2a_<suffixe>_test`.

```powershell
php -d xdebug.mode=off -d memory_limit=512M vendor/bin/phpunit --filter 'CoursePublication|PublicationForm|PublicationOverrides|UnpublishedMedia|LockedSharedCourse' --no-progress
php -d xdebug.mode=off bin/console lint:twig templates/course --env=test
php -d xdebug.mode=off bin/console doctrine:schema:validate --skip-sync --env=test
```

Le test de migration vérifie l'aller-retour du schéma, les valeurs par défaut, la conservation des données de toutes les tables et l'absence de différence entre schéma migré et mapping. `--skip-sync` ci-dessus ne vérifie que le mapping ; la synchronisation est vérifiée séparément dans cette base jetable.

### Résultats du 16 novembre 2026

- Syntaxe PHP : tous les fichiers PHP modifiés et ajoutés contrôlés, sans erreur.
- Twig : les 40 fichiers sous `templates/course` sont valides.
- Mapping Doctrine : valide. Migration MySQL réelle, retour arrière, conservation des données et synchronisation du schéma : test d'intégration réussi.
- Vérifications ciblées de publication, accès, médias, dictées et migration : 13 tests, 275 assertions avant l'ajout du scénario AJAX ; puis 24 tests, 334 assertions sur la publication et l'administration, incluant ce scénario.
- Suite complète finale exécutée avec `php -d xdebug.mode=off tests/manual/run_course_bookmark_suite.php` : **1 308 tests, 39 938 assertions, aucune erreur ni échec, 1 test ignoré**, durée 8 min 36 s. Base locale isolée `orthogram_bookmark_suite_95577af0b8b90e9e_test`, supprimée après exécution.
- Test ignoré : `CourseFilesTest::testEscapingSymlinkIsRefused`, car Windows n'autorise pas cet utilisateur à créer des liens symboliques. Ce test existant n'a pas été désactivé ; son motif a été vérifié par une exécution ciblée avec `--display-skipped`.
- Une première exécution ciblée avec la limite PHP de 128 Mo a épuisé la mémoire pendant une fixture audio de dictée ; la relance à 512 Mo et la suite complète réussissent. Aucun échec préexistant restant constaté.
- `git diff --check` : valide. Recette visuelle dans un navigateur et application de la migration à la base de travail non exécutées. Aucun cours réel dépublié, aucun commit, push, merge ou déploiement.

## Recette locale après application de la migration

1. Avec un apprenant abonné, réussir un exercice et noter son résultat, la progression et l'URL. Conserver sa page ouverte. Mémoriser ce cours comme dernier cours visité. Prévoir aussi un cours jamais validé.
2. Dans une session administrateur distincte, ouvrir le cours dans EasyAdmin, décocher « Publié » et enregistrer. Le cours doit rester visible et modifiable dans la liste d'administration.
3. Côté apprenant, actualiser sommaire, section, sidebars ordinateur/mobile et recherche. Vérifier l'absence du cours, la navigation qui le saute, les durées et les compteurs recalculés. La progression ne doit pas dépasser 100 %.
4. Essayer l'ancienne URL, l'URL autonome `/exercise/{id}` et la soumission depuis la page restée ouverte, avec puis sans `courseId`. Attendre des refus et aucune nouvelle tentative/validation. Pour un exercice partagé, seul un autre cours publié accessible permet encore l'accès ; il ne doit jamais valider le cours dépublié.
5. Vérifier « Reprendre ma formation » : repli accessible, aucune URL de cours dépublié. Vérifier aussi une section dont tous les cours sont dépubliés, puis un programme vide : aucun plantage ni division par zéro. Contrôler le message de dictée après les cours pour la section vide.
6. Republier le cours sans changer ses autres champs. Retrouver son contenu, son résultat et sa validation ; le cours jamais validé doit toujours rester à terminer. Si aucun nouveau cours n'a été visité, le signet historique redevient la cible de reprise.

L'allègement visuel du sommaire et des pages de section reste hors de ce lot.

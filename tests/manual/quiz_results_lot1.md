# Résultats des tests — contrat de lecture (lot 1)

`QuizResultsService::results()` ne prend aucun utilisateur en argument : il lit
le compte connecté via Security et refuse un compte inactif. Il est destiné aux
futurs contrôleurs personnels authentifiés, qui devront répondre avec
`Cache-Control: private, no-store`. Aucun exercice n'entre dans le catalogue.

## Données disponibles pour les lots suivants

Une liste de tableaux, sans entité Doctrine, questions ni réponses :

| Champ | Signification |
| --- | --- |
| `key` | `courseId:quizId`, ou `archived:attemptId` si une association manque |
| `courseId`, `quizId` | Identifiants éventuellement nuls ; ne suffisent pas à autoriser un lien |
| `title` | Titre figé de la dernière terminée ; sinon de la tentative existante ; sinon titre du catalogue |
| `section` | `{id, name}` de la section actuelle, ou `null` |
| `catalogOrder` | Clé de tri pédagogique ajoutée au lot 2 : programme, position et id de section, position et id de cours |
| `archived` | Le couple ne correspond plus au test actuel du cours, ou une association manque |
| `courseUrl` | Lien du lecteur uniquement si le couple est actuel et CourseVoter::VIEW l'autorise ; sinon `null` |
| `status` | `not_started` (« Pas encore passé »), `in_progress` (« En cours »), `completed` |
| `hasInProgress`, `inProgressAttemptId` | Tentative active, indépendante du dernier résultat terminé |
| `completedCount` | Nombre de tentatives terminées uniquement |
| `latest` | Dernière ligne de l'historique, ou `null` : ne jamais substituer le score partiel d'une tentative active |
| `best` | Meilleur ratio comparable à la dernière terminée ; `null` si aucun ratio valide |
| `mixedContent` | Si vrai, préciser « pour ce contenu du test » pour le meilleur score |
| `history` | Toutes les terminées, par `completedAt` puis `id` croissants |
| `gain` | Ajout lot 3 : dernier pourcentage affiché moins le premier, uniquement si plusieurs terminées, toutes comparables et de ratios valides ; sinon `null` |
| `historyUrl` | Ajout lot 3 : historique ancré sur la dernière terminée ; `null` sans résultat terminé |

Un résultat contient `attemptId`, `title`, `score`, `total`, `percentage` et
`completedAt` (`DateTimeImmutable`, à formater avec les conventions Twig de
l'application). L'historique et `latest` ajoutent `contentChanged`, `delta`,
`deltaLabel` et `correctionUrl`. Ce dernier est nul si le cours a disparu ou si
le droit actuel de consultation manque. Une réaffectation ou la suppression du
quiz n'empêche pas la correction si le cours reste accessible.

La comparaison V1 utilise l'égalité stricte du tableau des questions figées :
ordre, identifiants, textes, propositions, bonnes réponses et explications.
Le titre global et `snapshot.version` ne sont pas des versions éditoriales.
`contentChanged` compare deux terminées consécutives (faux pour la première).
Le delta ne saute jamais une tentative au contenu différent.

`QuizScore::percentage()` centralise l'arrondi entier, aussi utilisé par le
lecteur. Un total nul donne `null`, jamais 0 %. Le delta soustrait les deux
pourcentages affichés ; zéro porte le libellé « Stable ». Le meilleur résultat
compare les ratios non arrondis ; en cas d'égalité, la première tentative dans
l'ordre chronologique est conservée.

## Correction historique

GET `/mes-resultats/tentatives/{id}` — route `app_quiz_result_correction`.
Authentification, propriétaire, finalisation et CourseVoter::VIEW obligatoires.
Tentative étrangère, absente, inachevée ou sans cours : 404 ; droit au cours
manquant : 403. La politique globale déconnecte les comptes inactifs.

`QuizResultsService::correction()` fournit uniquement les champs destinés à
cette vue autorisée. Twig les échappe. La page réutilise les classes de
correction du lecteur, sans contrôleur Stimulus de passation. Réponse privée
sans stockage, y compris sur les refus du contrôleur ; cache Turbo désactivé.
Aucune écriture sur les tentatives, leçons ou la progression.

## Validation et limites

Les tests `QuizResultsTest` réutilisent `QuizPlayerFactory` et créent chacun une
base SQLite temporaire sous `var/`, dont le chemin est vérifié avant création
du schéma. `QuizScoreTest` vérifie les arrondis. Relancer aussi `QuizPlayerTest`
pour vérifier la non-régression du lecteur et de ses mutations.

Le service charge l'historique personnel en mémoire via une requête avec
jointures, et le catalogue via une seconde ; les droits restent décidés par le
voter existant. Pas de pagination, cache, migration ou index ajouté en V1.
Le snapshot existant fige le titre du test, mais pas le titre du cours ni de la
section : ces derniers ne sont pas reconstruits historiquement. La section
exposée est l'association actuelle lorsqu'elle existe.

La navbar, les cartes et les graphiques ont été ajoutés aux lots 2 et 3.
Le contrat de `history(anchorId, selectedId)` et la recette du parcours complet
sont documentés dans [quiz_results_lot3.md](quiz_results_lot3.md).

### Contrôles exécutés le 18 septembre 2026

- PHPUnit ciblé (résultats, pourcentage, lecteur) : 41 tests, 385 assertions, OK.
- PHPUnit complet : 366 tests, 29 379 assertions, OK. La base configurée a été
  identifiée par une requête de lecture comme `orthogram_test` ; les tests de
  quiz utilisent leurs bases SQLite isolées habituelles.
- `lint:twig templates/quiz_results`, `lint:container`, syntaxe JavaScript avec
  `node --check`, PHP CS Fixer sur les fichiers PHP concernés et
  `git diff --check` : OK. Route vérifiée avec `debug:router`.
- `composer validate --no-check-publish` : valide ; avertissement existant sur
  la contrainte `*` de `symfony/apache-pack`.
- PHPStan complet : 17 erreurs dans cinq fichiers de tests de calendrier non
  modifiés (`LearningReminderCalendarOccurrencesTest`,
  `LearningReminderCalendarServiceTest`, `LearningReminderVtimezoneTest`,
  `IcalendarOracleTest`, `generate_learning_reminder_calendar_cases.php`).
  Aucune erreur dans les fichiers de ce lot.
- Rendu HTML vérifié par KernelBrowser ; pas de recette visuelle dans un
  navigateur ni de test de charge pour ce lot.

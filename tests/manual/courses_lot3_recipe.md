# Cours gratuits — lot 3

Base : branche `preprod`, commit `96e6ef3c18fc0ba7e7b2d8a99a7e0b37927e0c09`, arbre initial propre. Aucun fichier AGENTS.md applicable trouvé. Aucun commit, push, déploiement, migration ni changement de contenu réel.

## Architecture et fichiers

- `src/Entity/Courses.php` : `isFreeLesson()` distingue le drapeau administrable de la gratuité effective. Liste explicite : Twig, lien, audio, vidéo. Quiz, exercice et type absent sont exclus.
- `src/Security/Voter/CourseVoter.php` : seul `VIEW_COURSE` bénéficie de cette règle, avec rattachement section/formation obligatoire. Un compte identifié doit rester actif et avoir terminé son authentification, ou être reconnu remember-me par le resolver existant. La méthode privée utilisée par les interactions et les exercices associés reste indépendante de `isFree`.
- `src/Controller/Course/ProgramSummaryController.php` : `/courses/{slug}` devient public ; `/ma-formation` conserve `PROGRAM_VIEW`. Les requêtes de progression et de planification ne sont exécutées qu'avec les droits privés. `PROGRAM_VIEW` et `SECTION_VIEW` restent privés pour leurs autres usages.
- `src/Controller/Course/CourseViewController.php` : garde les recherches de section/cours sous leur parent et les chargements privés conditionnels. N'exécute une source Twig que pour un cours Twig/lien. Lecture et sommaire répondent avec `private, no-store` ; Turbo conserve son `no-cache` existant.
- `src/Controller/Course/CourseSearchController.php` : les détails JSON de la recherche exigent désormais `INTERACT_COURSE`, pour conserver la recherche privée. Les réponses publiques de découverte ne sérialisent aucune entité.
- `templates/course/program_summary/{_main,_sidebar_content,_course_item,_discovery}.html.twig` : sommaire commun, badges gratuits uniquement sans `PROGRAM_VIEW`, cadenas et texte « Réservé aux abonnés », liens d'offre/connexion, sections publiques pointant vers une ancre du sommaire. Pas de descriptions privées de section, de progression fictive ni de données de rappel en mode public.
- `templates/course/show/{_main,_content,_navigation}.html.twig` : recherche, commentaires, validation, dictée/correction audio absents sans droit d'interaction ; précédent/suivant conserve les voisins réservés sans lien actif. Aucun lecteur ajouté.
- `src/Twig/ProgramAccessExtension.php` : fonction Twig `can_access_private_program(slug)`, qui retrouve la formation puis délègue à `PROGRAM_VIEW`, sans recalcul d'abonnement ni mémorisation du résultat d'autorisation.
- `templates/shared/_navbar.html.twig` : entrée « Découvrir les cours » uniquement sans accès privé à `formation-en-orthographe`, y compris quand la page ne reçoit pas de variable `program`. Les autres entrées restent inchangées.
- Tests : `FreeCoursesTest.php`, `CourseFilesTest.php`, `CourseVoterTest.php`, `CoursesFreeAccessTest.php`. L'ancienne attente du lot 1 « gratuit mais refusé » devient « lecture autorisée, interactions absentes ».
- `tests/manual/courses_lot3_browser_router.php` : fixtures de recette strictement locales ; base et fichiers sous `var/courses-lot3-browser`, indépendants du stockage et de la base applicative.

Les entités et dépôts actuels n'ont aucun statut de publication/visibilité. La présentation `/program/{slug}` est déjà publique pour les formations. Le catalogue reprend cette portée ; aucun accès administratif n'est ajouté. Les historiques personnels et leurs contrôles de correction ne changent pas.

## Matrice des droits

| Profil | Sommaire public | Cours gratuit admissible | Cours payant, quiz/exercice même `isFree=true` | Interactions | Ma formation, recherche, planification |
|---|---|---|---|---|---|
| Anonyme | Oui | Oui | Refus, connexion | Non | Non |
| Compte actif sans abonnement | Oui | Oui | 403 | Non | Non |
| Compte actif avec abonnement expiré/futur | Oui | Oui | 403 | Non | Non |
| Remember-me actif sans abonnement | Oui | Oui | Refus, réauthentification possible | Non | Non |
| Abonné valide actif, dont remember-me | Oui | Oui | Oui | Oui | Oui |
| Administrateur actif, authentification terminée | Oui | Oui | Oui | Oui | Oui |
| Compte inactif identifié, y compris administrateur | Session invalidée, connexion | Refus | Refus | Non | Non |
| 2FA en cours, y compris administrateur | Redirection 2FA | Redirection 2FA | Refus | Non | Non |

Le téléchargement brut reste exclusivement administratif. Un historique personnel déjà acquis reste consultable après expiration suivant les règles existantes ; les corrections restent soumises à leurs contrôles. Après déconnexion effective, une nouvelle visite anonyme peut naturellement consulter ce qui est public.

### Affichage de découverte — règle révisée

Cette règle remplace l'affichage des badges pour les abonnés prévu initialement dans le lot 3. Le masquage est réalisé par Twig : les éléments ne sont pas présents dans le HTML.

| Profil | Badges « Gratuit » / « Cours gratuit » | Navbar « Découvrir les cours » |
|---|---|---|
| Anonyme | Visibles | Visible |
| Compte actif sans abonnement | Visibles | Visible |
| Compte actif avec abonnement expiré ou futur | Visibles | Visible |
| Abonné actif autorisé | Masqués | Masquée |
| Administrateur actif correctement authentifié | Masqués | Masquée |

Les badges du sommaire principal, des sommaires ordinateur/mobile et de l'en-tête du lecteur suivent tous `PROGRAM_VIEW` sur leur formation. La gratuité reste administrable dans EasyAdmin ; aucune règle d'accès serveur ou d'interaction n'est modifiée par cet ajustement.

## Vérifications automatisées

Toutes les suites ci-dessous utilisent SQLite isolé (mémoire ou fichier temporaire) et le stockage jetable imposé par `tests/bootstrap.php`. Elles n'utilisent pas la base réelle. Les suites d'intégration de planification dépendantes d'une base MySQL `_test` ne sont pas lancées.

```powershell
vendor\bin\phpunit tests/Controller/Course/FreeCoursesTest.php tests/Controller/Course/CourseFilesTest.php tests/Controller/Course/CourseInteractionTest.php tests/Controller/Course/SidebarCompletionTest.php tests/Controller/Course/QuizPlayerTest.php tests/Controller/QuizResultsTest.php tests/Security tests/Entity/CoursesTest.php tests/Controller/Admin/CoursesFreeAccessTest.php tests/Services/Courses tests/Services/QuizCorrectionServiceTest.php tests/Services/ExerciceCorrectionServiceTest.php tests/EventSubscriber/TwoFactorAuthenticationCompleteSubscriberTest.php --display-skipped
```

Couverture : sommaire et lecteur public, HTML sans données privées, ordre inter-sections, badges, deux sommaires responsive, offre/connexion, refus directs et absence d'écritures (comparaison de toutes les tables), comptes et jetons, retrait de gratuité, historique après expiration, corrections, administration, stockage et téléchargement. Médias : fixtures temporaires audio/vidéo, contrôles avant GET/HEAD/Range/conditionnelles et après retrait de gratuité. Les tests d'interaction existants soumettent aussi des jetons CSRF valides après retrait des droits.

Résultat consolidé avant l'ajustement d'affichage : **353 cas, dont 352 réussis et 1 ignoré, 4 218 assertions**, en 1 min 42 s. Le test `CourseFilesTest::testEscapingSymlinkIsRefused` est ignoré sur ce poste Windows, qui n'autorise pas la création du lien symbolique. Les autres protections de traversée de chemin sont exécutées. Rapport local : `var/courses-lot3-final-tests.log`.

Autres contrôles exécutés : PHPStan niveau 6 sur les cinq fichiers applicatifs et les trois tests principaux modifiés ; PHP CS Fixer en dry-run sur les dix fichiers PHP modifiés/ajoutés ; `lint:twig` (36 templates), `lint:container --env=test`, `php -l` du routeur de recette, `git diff --check`.

Pour l'ajustement d'affichage, exécuter les suites ciblées suivantes (résultat dans `var/courses-lot3-display-tests.log`) :

```powershell
vendor\bin\phpunit tests/Controller/Course/FreeCoursesTest.php tests/Controller/Course/SidebarCompletionTest.php tests/Controller/Admin/CoursesFreeAccessTest.php
```

Résultat de l'ajustement : **50 tests réussis, 1 395 assertions**, aucun test ignoré. PHPStan sur l'extension et le test modifié, PHP CS Fixer en dry-run, syntaxe PHP, `lint:twig` (36 templates), `lint:container --env=test` et `git diff --check` passent.

`FreeCoursesTest` couvre aussi l'abonnement futur, les badges dans chaque emplacement, leur absence pour abonné/admin et la navbar sur `/login`, dont le contrôleur ne transmet aucun `program`. La recette visuelle révisée reste manuelle.

## Recette navigateur sur fixtures isolées

Lancer depuis la racine du projet :

```powershell
php -S 127.0.0.1:8813 -t public tests/manual/courses_lot3_browser_router.php
```

Ce routeur refuse tout accès autre que localhost. Ne pas le déployer. Il crée des comptes fictifs, une formation, un cours gratuit entre un cours réservé et un quiz volontairement incohérent (`isFree=true`). Il simule les jetons de connexion ; ce n'est pas une recette du formulaire de connexion ni des cookies d'appareil de confiance. Changer de profil via les URL ci-dessous crée une nouvelle session de fixture. Arrêter le serveur avec Ctrl+C après la recette.

URL de base : `http://127.0.0.1:8813`.

| URL | Attendu |
|---|---|
| `/courses/formation-en-orthographe` | Sommaire public, sections et titres, seul « Lecture gratuite » porte le badge Gratuit pour le visiteur |
| `/courses/formation-en-orthographe/quiz-section/lecture-gratuite` | Texte « CONTENU GRATUIT », aucun commentaire/compteur/formulaire, validation, progression, exercice ni recherche privée |
| `/courses/formation-en-orthographe/quiz-section/cours-reserve` | Refus sans droits privés ; lecture pour abonné/admin |
| `/courses/formation-en-orthographe/quiz-section/quiz-cours` | Quiz réservé malgré son drapeau incohérent ; aucun contenu/correction pour visiteur |
| `/ma-formation` | Privé ; accessible seulement avec droits privés |
| `/course-search/formation-en-orthographe` | Privé, même si la formation contient un cours gratuit |

Sélectionner successivement les profils en visitant ces URL, puis tester les URL de lecture ci-dessus :

| Sélecteur de profil | Résultats attendus |
|---|---|
| `/__lot3/profile/anonymous` | Sommaire et cours gratuit 200 ; réservé vers `/login` ; connexion et offre proposées |
| `/__lot3/profile/unsubscribed` | Sommaire et gratuit 200 ; réservé 403 ; offre proposée |
| `/__lot3/profile/expired` | Même lecture que sans abonnement ; aucun indicateur personnel dans le sommaire public |
| `/__lot3/profile/remember-me` | Gratuit 200 ; pas d'interactions ; réservé refusé avec réauthentification possible |
| `/__lot3/profile/subscriber` | Tous les cours accessibles ; recherche, validation, commentaires et planification présents ; badges et lien de découverte absents |
| `/__lot3/profile/admin` | Même accès pédagogique ; administration disponible ; badges et lien de découverte absents |
| `/__lot3/profile/inactive` | La première requête invalide la session et redirige vers `/login` |
| `/__lot3/profile/2fa` | Sommaire et lecture gratuite redirigent vers `/2fa` |

En affichage 1440 px puis 390 px, ouvrir le menu mobile et les sections au clavier. Vérifier l'ordre « Cours réservé / Lecture gratuite / Quiz cours », les cadenas accompagnés de texte, les badges visibles pour anonyme/sans abonnement/expiré et absents pour abonné/admin, l'absence de débordement et le bouton de fermeture du menu. Contrôler les trois sommaires et « Cours gratuit » dans l'en-tête du lecteur. Dans le cours gratuit, précédent indique « Cours réservé » et suivant « Quiz cours » : pas de lien actif sans droit, aucun saut de cours. Le fil « Section » rejoint le sommaire public. Avec abonnement, ces voisins sont des liens actifs et les indicateurs personnels fonctionnent.

Pour chaque profil, visiter aussi `/login` : cette page n'a pas de contexte `program`. « Découvrir les cours » doit être présent pour anonyme/sans abonnement/expiré et absent pour abonné/admin, sans retirer « Ma formation » ni « Mes résultats » aux comptes connectés. Vérifier l'absence des éléments masqués dans la source HTML. Le cas d'abonnement futur suit le même affichage que sans abonnement et est couvert par les fixtures automatisées.

Dans les outils réseau du navigateur, recharger sommaire puis lecteur gratuit : aucun appel aux commentaires, quiz, exercices, recherche ou rappel. Dans la source HTML publique, rechercher `SECRET`, `data-quiz`, `data-click-words`, `data-learning-reminder`, `completion-button` : aucune donnée privée. Vérifier `Cache-Control` contient `private` et `no-store`, et la meta Turbo `no-cache`.

Pour le retrait de gratuité, conserver le cours gratuit ouvert, sélectionner le profil anonyme ou sans abonnement, puis exécuter dans un autre terminal :

```powershell
Invoke-WebRequest -Method Post -Uri http://127.0.0.1:8813/__lot3/free -Body @{value='0'}
```

Recharger le cours : refus immédiat ; recharger le sommaire : cours verrouillé. Tester aussi retour arrière puis nouvelle navigation. Le contenu déjà reçu n'est pas effacé. Restaurer uniquement la fixture : même commande avec `value='1'`.

Le serveur de fixtures a été vérifié par requêtes HTTP réelles : sommaire et lecture anonyme 200 sans `SECRET`, cache privé sans stockage, affichage des commentaires et de la planification pour l'abonné, retrait de ces éléments au retour anonyme. La recette visuelle, clavier, réseau JavaScript, Turbo et les vrais cookies remember-me/appareil de confiance restent à effectuer dans un navigateur. Pour les appareils de confiance, utiliser un environnement de test dédié avec le véritable formulaire : terminer la 2FA avec appareil approuvé, fermer/rouvrir, vérifier le cours gratuit et un cours réservé selon l'abonnement ; avec 2FA interrompue, aucune lecture ne doit s'ouvrir.

## Correction de la navigation responsive

La règle générique `.btn-row .btn` imposait `white-space: nowrap`. Le parent utilisait déjà `display: flex`, `flex-wrap: wrap` et `justify-content: flex-end` ; le bloc verrouillé conservait néanmoins une largeur intrinsèque supérieure à celle disponible. Dans Chrome à 425 px, la cascade initiale reproduite donne un bloc de 438,55 px commençant à **−33,55 px**.

La correction est limitée à `assets/styles/courses.css` et aux templates `course/show/_main.html.twig` et `_navigation.html.twig` : classe `course-navigation`, largeur maximale de 100 %, minimum à zéro, texte avec retour à la ligne et rupture des mots exceptionnellement longs. Le cadenas dispose d'une zone flex non compressible de 1 rem et suit la couleur du texte. Aucun overflow masquant ni troncature ; aucun changement des boutons globaux, des liens accessibles ou du formulaire de validation. Les voisins réservés restent des `span` sans lien.

Vérifications **réellement exécutées dans Chrome headless**, aux largeurs CSS **320, 390, 425 et 1440 px** :

- Réservé/réservé, accessible/réservé, accessible/accessible, chacun avec et sans bouton « Valider » : **24 combinaisons réussies**.
- Titres longs, mesure des limites du document et de chaque bloc, de toutes les lignes de texte, absence de chevauchement et de troncature, cadenas de 16 × 16 px entièrement dans le bloc et de même couleur que le texte.
- Contrôle des éléments réservés non cliquables et présence du bouton de validation selon le scénario.
- Navigation réelle par les deux flèches et soumission réelle de « Valider », puis annulation, sur les données de fixture exclusivement.
- Captures générées pour les 24 combinaisons ; inspection visuelle de captures mobiles et ordinateur. Il s'agit de viewports Chrome sur ordinateur, pas d'appareils physiques ni d'une recette Safari/Firefox.

Les combinaisons de mise en page sont assemblées dans le DOM du navigateur à partir des fragments Twig réellement rendus pour les fixtures anonymes et abonnées. La présence simultanée de voisins verrouillés et de « Valider » sert uniquement à éprouver la mise en page ; elle n'introduit aucune modification des autorisations serveur.

Script reproductible : `tests/manual/courses_navigation_browser_check.ps1`. Démarrer le serveur de fixtures décrit plus haut et un Chrome isolé avec le port de débogage 8814, puis lancer :

```powershell
pwsh -NoProfile -File tests/manual/courses_navigation_browser_check.ps1
```

Rapports : `var/courses-navigation-browser.log`, `var/courses-navigation-measurements.json`, captures `var/navigation-*.png`. Les processus de recette ont été arrêtés après vérification.

Non-régressions exécutées : `FreeCoursesTest` et `SidebarCompletionTest`, **36 tests réussis, 1 224 assertions** (`var/courses-navigation-phpunit.log`). `lint:twig` sur les 12 templates du lecteur et `git diff --check` passent également.

## Stockage et limites

Les routes médias et de téléchargement du lot 2B, leur ordre de contrôle et leur stockage sont conservés. Aucune source pédagogique réelle n'a été modifiée. Les 12 sources présentes dans `private/courses/files` ont été inspectées : `deroule-formation`, `les-bases`, `un-adjectif-qualificatif`, `un-adverbe`, `un-cc`, `un-cod`, `un-coi`, `un-nom`, `un-pronom`, `un-sujet`, `un-verbe`, `une-preposition`. Aucun appel privé ni ancienne URL de fichier détecté ; seul l'attribut de présentation `data-controller="richtext"` est présent. Les futures sources Twig administratives restent du code de confiance à relire avant publication gratuite.

Les sources du serveur distant et sa configuration web n'ont pas été relues ni modifiées. La protection HTTP des anciennes URL statiques sur Apache/nginx reste une vérification d'environnement du lot 2B, hors du serveur PHP de recette. Aucun lecteur nouveau, aucune modification d'abonnement réel, aucune adaptation de déploiement.

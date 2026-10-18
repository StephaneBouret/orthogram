# Lot 6 — intégration des dictées au parcours de formation

Travail du 17 octobre 2026 sur `preprod`, à partir de
`cabb42d64f3070700c4c26854a8dbff536db3b62`. Aucun commit, push, déploiement,
accès Hostinger ni modification des données de formation de développement.
Les trois ZIP de revue et les réglages locaux sont préservés.
`DICTATION_LEARNER_ENABLED` reste à **0 par défaut**.

## Politique de préservation validée

Faits établis par les contrats 1/2A : `Lesson` n'enregistre pas toutes les
consultations ; `studiedAt` peut changer et le signet ne conserve qu'un cours.
L'absence d'une leçon ne prouve donc jamais qu'une section est nouvelle pour
un utilisateur. Ces données ne servent pas à décider d'une exemption.

Arbitrage explicite de Stéphane pendant ce lot : **inscrire automatiquement
les nouveaux comptes lorsque la fonctionnalité est activée**.

La migration ajoute `user.dictation_prerequisites`, JSON nullable :

| Valeur | Signification durable |
| --- | --- |
| `NULL` | Ancien parcours : aucun prérequis imposé, même après activation |
| `[]` | Compte créé pendant l'activation, sans dictée utilisable à cette date |
| Liste d'identifiants | Dictées publiées avec les deux pistes et les segments au moment de la création du compte |

Le listener Doctrine `prePersist(User)` établit cette liste une seule fois.
Il couvre aussi la création administrative d'un compte. Il ne s'exécute pas
comme réinscription lors d'une connexion, d'un abonnement ou d'une modification
de profil. La méthode d'inscription refuse un compte déjà persistant.
Un administrateur conserve néanmoins ses accès administratifs habituels.

Tous les comptes existants lors de la migration, ainsi que ceux créés pendant
une désactivation, conservent `NULL`. Aucun rattrapage de cohorte, aucune date
de consultation ou progression fictive n'est créé.

Le snapshot conserve les **identités des dictées**, pas leurs versions. Une
première publication absente du snapshot reste facultative pour ce compte :
elle ne referme donc jamais une section auparavant accessible. C'est une
exemption volontairement large, y compris pour les dictées/sections ajoutées
ultérieurement et les comptes sans abonnement au moment de leur création.
Les futurs comptes incluront ces publications. Il n'y a pas d'inscription
rétroactive proposée dans l'administration.

Une nouvelle version de la même dictée ne change ni le snapshot ni la tentative.
La clôture initiale est définitive pour le prérequis ; le score est sans effet.
Les comptes exemptés peuvent réaliser les dictées facultativement sans perdre
leur accès aux cours. Le marqueur de passage tardif conserve le sens limité
prévu au lot 2A ; il ne devient pas une reconstitution de l'historique.

La désactivation masque toutes les nouvelles entrées et désactive le verrou.
La réactivation réutilise les snapshots et clôtures enregistrés ; elle ne
recalcule pas de cohorte à partir des leçons ou signets.

## Accès et navigation

La dictée reste rattachée à `Sections`. Ni `Courses`, ni `CourseContentType`,
ni la création EasyAdmin des cours ne changent. Aucun nom/identifiant de section
n'est fixé dans le code applicatif. Les fondamentaux sans dictée publiée restent
accessibles selon leurs droits ordinaires.

`DictationJourney` lit uniquement les faits nécessaires au prérequis. Ses
lectures répétées sont partagées à l'intérieur d'une requête, jamais entre deux
requêtes. `CourseVoter` ajoute ce prérequis aux contrôles privés existants :
abonnement actif, compte actif, authentification complète/remember-me admissible,
administration. `SECTION_VIEW` et `PROGRAM_VIEW` restent accessibles selon leurs
droits habituels pour pouvoir atteindre le prérequis. Aucun droit n'est accordé
par la réussite ou l'exemption d'une dictée.

| Surface | Comportement |
| --- | --- |
| Cours privé direct | Redirection vers la dictée/tentative si seul le prérequis manque ; refus ordinaire si les droits manquent |
| Cours gratuit lisible | `VIEW_COURSE` reste public selon les règles existantes, y compris anonymement ; cette lecture n'exempte pas les cours protégés |
| Validation, quiz, exercice associé, commentaires, signet | `INTERACT_COURSE` impose aussi le prérequis ; aucune `Lesson` créée par une dictée |
| Médias privés GET/HEAD/Range/conditionnels | Contrôle avant résolution/lecture du fichier et avant toute réponse 206/304 ; refus 403 sans média |
| Recherche/autocomplétion | Titres seuls, filtre sur le nom seulement ; session active et droits d'abonnement/admin exigés |
| Détails JSON de recherche | URL du prérequis pour un cours privé verrouillé, sans extrait ; URL de lecture gratuite conservée pour l'abonné ; refus JSON 403 sans droits |
| Sommaire, section, menus ordinateur/mobile | Entrée avant les cours, entrée distincte après leur dernier élément |
| Précédent/suivant | Lien vers le prérequis quand il manque, sans lien trompeur vers un cours ouvert |
| Liens de reprise existants | Le service de signet réutilise les voters ; les cours historiques exemptés restent repris normalement |

Les refus de médias, détails, likes et soumissions d'exercice sont des réponses
JSON 403 privées, sans stockage en cache. Le quiz conserve son propre refus JSON ;
les formulaires de progression conservent leur refus HTTP et le signet son 403
vide. Les protections de connexion/2FA peuvent intervenir avant le contrôleur.

L'entrée initiale affiche « Commencer la dictée », « Poursuivre l’écriture » ou
« Poursuivre l’autocorrection » selon l'état réel. Le premier clic sur « Voir mes
erreurs » n'ouvre rien si une autocorrection subsiste. L'absence d'erreur utilise
la clôture automatique existante. La page d'entrée explique le prérequis ; après
clôture, « Accéder aux cours de la section » rejoint les cours sans score minimal.

L'entrée après les cours n'est active que si la dictée initiale est clôturée et
si `SectionCompletionService` constate tous les cours validés. Une validation
partielle, un cours gratuit non validé ou une section vide ne suffit pas. Sinon,
le texte explique ce qui manque sans bouton d'action voué à l'échec.

Cette entrée retrouve le GET de la tentative existante : le POST de reprise
reste explicite dans l'écran du lot 5. Reprise éditable, copie sans erreur,
finalisation et résultat définitif réutilisent les mêmes services et la même
version. Aucun GET de navigation ne crée une tentative ou ne déclenche une
transition. La complétion est revérifiée par le workflow sous ses verrous
existants. Une validation retirée masque à nouveau le corrigé selon le lot 5.

Les projections de navigation n'exposent que URL, libellés et booléens. Aucun
texte canonique, variante, copie, signalement ou commentaire de Caroline n'y
est transmis. Les projections apprenant des lots 3–5 restent les seules sources
des écrans de dictée. CSS et composants existants, Stimulus du menu et des phases
existants, retour à la ligne et focus visible sont réutilisés.

## Migration

`Version20261017180000` ajoute uniquement le champ JSON nullable ; elle ne peuple
aucune cohorte et ne touche pas aux contenus ni aux progressions. Elle est
nécessaire pour rendre les exemptions durables, indépendamment de l'historique
incomplet et des publications futures.

Montée, descente, remontée et comparaison Doctrine/SQL ont été exécutées sur des
bases aléatoires `orthogram_dictation_2a_<16 hex>_test`. Le test compare les lignes
utilisateur avant/après, exige `NULL` pour les comptes présents et vérifie
l'absence de leçons/tentatives créées. La descente retire seulement ce champ ;
elle perd les snapshots. Une remontée exempterait alors tous les comptes présents.

**Aucune migration appliquée à la base de développement.** Une future mise en
service devra appliquer le schéma avant d'utiliser ce code avec une base
persistante ; aucune procédure de déploiement n'a été exécutée ici.

## Fichiers modifiés ou ajoutés

- Persistance : `src/Entity/User.php`, `src/EventListener/DictationEnrollmentListener.php`, `migrations/Version20261017180000.php`.
- Lecture du parcours : `src/Services/Dictation/DictationJourney.php`, `src/Twig/DictationJourneyExtension.php`.
- Autorisation et recherche : `src/Security/Voter/CourseVoter.php`, `src/Form/CoursesAutocompleteField.php`.
- Contrôleurs dans `src/Controller/Course/` : `CourseViewController.php`, `CourseSearchController.php`, `CourseFileController.php`, `SectionViewController.php`, `DictationController.php`, `ExerciseController.php`, `CommentController.php`.
- Navigation : `templates/course/_dictation_entry.html.twig`, `templates/course/program_summary/_course_item.html.twig`, `_main.html.twig`, `_sidebar_content.html.twig`, `templates/course/section/_main.html.twig`, `templates/course/show/_navigation.html.twig`.
- Dictée : `templates/dictation/entry.html.twig`, `templates/dictation/closed.html.twig`.
- Styles : `assets/styles/courses.css`.
- Tests : `tests/Controller/Course/DictationJourneyTest.php`, `tests/Integration/DictationEnrollmentMigrationTest.php`, `tests/Support/DictationTestDatabase.php`.
- Recette : `tests/manual/serve_dictation_lot6_browser.php`, `tests/manual/dictation_lot6_browser_check.cjs`, ce contrat.

## Vérifications effectuées

PHP 8.4.15, PHPUnit 13.2.3, MySQL local isolé, SQLite en mémoire pour les tests
existants de cours, Chrome headless réel via CDP. Médias et utilisateurs
synthétiques ; les serveurs automatisés ont confirmé leur nettoyage.

```powershell
vendor\bin\phpunit --do-not-cache-result tests/Services/Dictation tests/Services/Courses/SectionCompletionServiceTest.php tests/Integration/DictationPersistenceTest.php tests/Integration/DictationEditorialMediaPersistenceTest.php tests/Integration/DictationDraftDeletionTest.php tests/Integration/DictationEnrollmentMigrationTest.php tests/Controller/Admin/DictationAdminTest.php tests/Controller/Course/DictationControllerTest.php tests/Controller/Course/DictationJourneyTest.php tests/Security/Voter/CourseVoterTest.php
vendor\bin\phpunit --do-not-cache-result tests/Controller/Course/FreeCoursesTest.php tests/Controller/Course/CourseFilesTest.php tests/Controller/Course/CourseInteractionTest.php tests/Controller/Course/SidebarCompletionTest.php tests/Services/Courses/SectionCompletionServiceTest.php
```

Première passe complète dictées : **389 tests, 2 821 assertions, succès**.
Régression cours : **225 tests, 3 318 assertions, succès, 1 ignoré** (création
de liens symboliques non autorisée pour cet utilisateur Windows).
Ces suites se recouvrent : ne pas additionner leurs nombres comme des cas uniques.
La **passe finale réunissant ces suites sans doublons** comporte **613 tests,
6 154 assertions, aucun échec, le même test ignoré**, en 2 min 40 s. Elle comprend
les derniers cas du lot 6 : recherche par formulaire, accès gratuit par recherche,
navigation précédent/suivant, jeton CSRF de validation valide avant/après clôture,
médias privés et réponses conditionnelles, exercices associés, commentaires et
signets. La passe intermédiaire des écrans de dictée et de la migration avait
également réussi : 37 tests, 729 assertions.

PHPStan niveau 6 sur tous les PHP applicatifs modifiés et les nouveaux tests,
migration et serveur de recette : aucune erreur. PHP CS Fixer ciblé, conteneur
Symfony, 44 templates Twig et `git diff --check` vérifiés. L'avertissement local
Xdebug concernant son fichier de journal n'empêche pas les contrôles.

Recette Chrome automatisée : `/courses/lot6`, affichages 1 440 px et 390 px,
clair/sombre, ordre des entrées, reprise désactivée, menus mobiles et focus
clavier. La dernière recette vérifie aussi un titre de cours long dans le lien
précédent sur mobile, sans débordement, puis l'explication du prérequis.
Parcours réel : URL privée → démarrage → évaluation avec erreur →
autocorrection encore requise → clôture avec erreur → cours accessible → POST
de validation → entrée après les cours → reprise de la même copie → finalisation
→ corrigé et lien du résultat définitif. Captures locales ignorées par Git :
`var/dictation-lot6-{1440,390}-{light,dark}.png`. Captures ordinateur clair et
mobile sombre inspectées visuellement. Aucun serveur ni navigateur de cette
recette conservé. Aucun mode manuel lancé.

Lors de cette passe automatisée, le harnais n'acceptait que `--automated` et refusait un port 8815 occupé. Il utilise
le routeur isolé du lot 3, qui contrôle le nom de base avant toute requête
applicative. Le profil Chrome dédié utilise le port 8816. Le script CDP dépose
le témoin d'arrêt dans `finally`, puis le serveur détruit sa propre base et ses
stockages temporaires. Lancer le serveur et le script CDP dans deux terminaux,
avec Chrome headless et un profil dédié, selon la recette du lot 5.

Incidents corrigés pendant la mise au point : attente du chargement CSS et
événements clavier avant contrôle de `:focus-visible` ; sélecteurs du harnais
mobile visant le contenu visible plutôt que le menu replié. Une première
assertion de comptage `Lesson` sur un EntityManager auxiliaire utilisait un
ServiceEntityRepository lié au noyau de support : elle lisait `orthogram_test`
au lieu de la base aléatoire. Ces lectures de comptage n'ont effectué aucune
écriture. Elles ont été remplacées par du SQL sur la connexion explicitement
isolée, puis les suites ont été relancées avec succès. La base de développement
et ses contenus n'ont pas été utilisés. La régression d'authentification a aussi
permis de conserver la redirection de connexion des sessions invalidées avant
le refus JSON des soumissions d'exercice ; les six scénarios concernés puis la
suite finale passent.

État Git final : `preprod`, HEAD inchangé, **29 fichiers du lot modifiés ou
ajoutés**, aucune modification indexée, trois ZIP de revue toujours non suivis.
Aucun commit ou push. Les ports 8815/8816 de la recette ont été libérés.

## Limites et recette manuelle ultérieure

Aucun arbitrage d'activation restant : le choix ci-dessus a été confirmé.
Pas de test sur mobile physique, lecteur d'écran, voix de Caroline ou hébergement
distant. La largeur mobile est une émulation Chrome sous Windows. Aucun test
de charge ni benchmark global des nouvelles navigations.

Après demande explicite d'une recette manuelle isolée :

1. Préparer une base et des médias jetables avec un ancien compte exempté et un compte créé après publication/activation ; ne pas employer les données locales de formation.
2. Vérifier sommaire, section et menu mobile : dictée avant les cours, reprise distincte après eux, fondamentaux accessibles.
3. Pour le nouveau compte, ouvrir un cours privé directement et par recherche, puis un cours gratuit. Le privé mène à la dictée, le gratuit reste lisible.
4. Évaluer une copie erronée : les cours restent verrouillés. Clôturer sans corriger toutes les erreurs : ils s'ouvrent. Vérifier aussi une première copie sans erreur.
5. Valider tous les cours, y compris le gratuit ; retrouver la copie par l'entrée finale, reprendre et finaliser. Vérifier la copie parfaite et la consultation du résultat définitif.
6. Vérifier clair/sombre, largeur mobile et Tab. Contrôler l'ancien compte sans `Lesson`, une première publication tardive, puis la désactivation du drapeau.
7. Arrêter les processus de recette et supprimer uniquement leurs bases/stockages jetables.

## Harnais manuel isolé (ajout du 18 octobre 2026)

Lancer `php tests/manual/serve_dictation_lot6_browser.php --manual`. Ce mode ne
lance ni Chrome/CDP ni scénario automatisé. Il refuse un port 8815 occupé et
annonce son URL de commande, son échéance Europe/Paris (45 minutes) et son
témoin `var/dictation-6-manual-<session>.stop`. Créer ce fichier arrête seulement
le serveur enfant de cette session et supprime sa base aléatoire et ses deux
stockages temporaires. L'échéance déclenche le même nettoyage. Le manifeste
JSON de session reste disponible avec le statut `cleaned` après nettoyage.

Le routeur `dictation_lot6_manual_router.php`, exclusivement local et réservé
au serveur de recette, propose deux boutons de changement de compte. Il
utilise KernelBrowser et des sessions isolées, sans changement du pare-feu ni
de l'authentification applicative. Les commandes du panneau sont des POST
protégés par un jeton propre à la session ; les GET affichent seulement des
liens, les textes et les comptages SQL de tentatives et de `Lesson`.

Avant les écritures, le support vérifie par `SELECT DATABASE()` la connexion
sans base servant à créer la base jetable, puis chaque connexion isolée.
Le noyau applicatif et ses repositories sont liés à cette base. Le compte
ancien abonné est créé avant activation et reste exempté, sans `Lesson`.
Après publication des deux dictées synthétiques, le compte nouveau abonné
est inscrit par le véritable listener activé ; ses deux prérequis sont
contrôlés. Aucun snapshot n'est injecté manuellement. Les deux comptes ont
zéro tentative et zéro `Lesson` à l'ouverture de la recette.

Le programme comprend les fondamentaux sans dictée, une section avec dictée,
cours privé et cours gratuit, et une section avec dictée mais sans cours.
Les deux boutons « Ajouter le premier cours » et « Ajouter le second cours »
ajoutent seulement des cours synthétiques à cette dernière section. Ils sont
idempotents. Observer l'ordre avant/après chaque ajout : dictée en tête,
reprise après le dernier cours. La reprise reste indisponible sur la section
vide, avant clôture initiale et tant qu'un cours ajouté reste non validé.
Pour vérifier les deux conditions séparément, ajouter les deux cours avant
clôture, clôturer en conservant les erreurs, puis valider un cours et enfin
l'autre. Recharger la section entre les étapes.

Référence : `L’école ouvre ses portes aux enfants chaque lundi matin.`
Copie fautive : `L’ecole ouvre ses porte aux enfants chaque lundi matin.`
Le correcteur pur calcule 10 unités lexicales, 2 erreurs lexicales, aucune
erreur de ponctuation, 80 %. La copie exacte donne 100 %, sans erreur.
Ce calcul de référence ne crée aucune tentative ni évaluation persistée.
Les pistes sont des tonalités synthétiques, pas une lecture vocale du texte.

Pour la section principale : consulter sommaire/menu mobile et recherche,
lire le gratuit, démarrer puis « Voir mes erreurs » avec la copie fautive
(privé encore verrouillé), puis « Terminer » en conservant les erreurs
(privé accessible sans score minimal). Valider les deux cours, reprendre via
l'entrée après le dernier cours, saisir la référence exacte et terminer pour
consulter le résultat définitif. Le compte ancien peut accéder au privé sans
dictée préalable et pratiquer la dictée facultativement.

Cette extension ne modifie que le harnais et son support/documentation.
Les archives de revue existantes restent inchangées et ne contiennent pas
cette adaptation ultérieure du harnais.

## Validation manuelle du 18 octobre et correctif UX avant commit

### Observations manuelles rapportées par l'utilisateur

La recette manuelle isolée du 18 octobre 2026 est terminée. Les validations
ci-dessous ont été effectuées **sur ordinateur, en thème clair, avec contenus
et audios synthétiques**. Elles ne constituent pas une validation sur mobile
physique, avec lecteur d'écran ou avec les médias réels.

- Avant clôture initiale, l'accès privé est orienté vers la dictée, y compris
  depuis la recherche, la navigation précédent/suivant et une URL directe
  ouverte dans un autre onglet.
- Le cours gratuit reste consultable, sans débloquer le privé. Une évaluation
  à 80 % maintient le verrou ; une clôture à 80 % ouvre les cours sans score
  minimal.
- Après validation des deux cours, la reprise permet la correction, puis un
  résultat définitif à 100 %.
- L'ancien compte est exempté du préalable obligatoire, sans `Lesson`
  préfabriquée.
- Dans la section initialement vide, puis enrichie successivement de deux
  cours, la dictée initiale reste en tête et la reprise après le dernier cours.
  La reprise reste indisponible après validation du premier cours et devient
  disponible après validation du second.
- Une copie parfaite dès le premier passage entraîne la clôture directe.
  Après les cours, la reprise est en lecture seule ; la finalisation donne
  100 %, avec corrigé et explications.

La session manuelle précédente n'a été ni réutilisée ni réinitialisée pour
le correctif : son nettoyage programmé reste autonome.

### Cause et correction du message commercial

Sur le cours gratuit d'un nouvel abonné actif encore soumis à la dictée,
`CourseViewController` calcule correctement `canInteract = false` à partir de
`INTERACT_COURSE`. Le template `course/show/_main.html.twig` interprétait
tous les refus de cette autorisation comme un motif commercial en incluant
inconditionnellement `_discovery.html.twig`. La lecture était autorisée,
mais le message « Découvrir l’offre » expliquait incorrectement le refus
de validation et d'échanges.

Le template interroge désormais `dictation_journey(section)` uniquement
lorsque le cours est gratuit et les interactions refusées. Cette fonction
existante vérifie `SECTION_VIEW` avant de retourner le parcours ; son état
`locked` utilise les mêmes règles que le voter. Un compte sans abonnement
actif ne reçoit donc pas cette explication pédagogique. Les administrateurs,
exemptions et dictées désactivées conservent leur comportement.

Quand ce préalable constitue l'obstacle, le bloc affiche exactement :

> Vous pouvez consulter ce cours librement. Pour le valider et participer aux échanges, terminez d’abord la dictée initiale de cette section.

Le lien « Accéder à ma dictée » utilise l'URL du parcours : entrée de la
dictée avant démarrage, puis tentative existante pendant l'écriture et
l'autocorrection. Après clôture, le bloc disparaît et les interactions
normalement permises sont affichées. Les visiteurs anonymes et comptes
sans abonnement actif conservent le bloc commercial et, pour les visiteurs,
le lien de connexion.

Le correctif applicatif est limité au template : aucune modification du
voter, des scores, transitions, inscriptions, règles de complétion ou du CSS.
Le bouton reprend `btn-orthogram-primary`, la disposition existante et
`course-prerequisite-link` pour le focus clavier.

### Vérifications réellement effectuées pour le correctif

- Reproduction par un nouveau test fonctionnel dans une base MySQL jetable :
  échec avant correction (bloc pédagogique absent), puis succès après le
  changement du template.
- `DictationJourneyTest` : **19 tests, 233 assertions, succès**. Les nouveaux
  cas distinguent préalable seul, visiteur, absence d'abonnement, abonnement
  expiré, ancien exempté sans historique, fonctionnalité désactivée et
  administrateur sans abonnement. Le lien est vérifié avant démarrage,
  pendant l'écriture et après évaluation. Avant clôture, un POST de validation
  du cours gratuit avec CSRF valide et un POST de signet restent refusés
  (403), sans création de `Lesson`. Après clôture avec erreurs, le formulaire
  d'échanges revient et la validation du cours réussit.
- Régression `FreeCoursesTest`, `CourseVoterTest`, `CourseInteractionTest` :
  **88 tests, 1 532 assertions, succès**. Total sans recouvrement de ces
  exécutions : **107 tests, 1 765 assertions**, aucun échec.
- PHPStan niveau 6 sur le test modifié : aucune erreur. PHP CS Fixer ciblé,
  syntaxe PHP, lint du template Twig et `git diff --check` : succès.
- Contrôle visuel du nouveau bloc dans Chrome headless réel, via CDP, sur
  une **nouvelle** base et de nouveaux stockages jetables, ports **8825/8826**
  vérifiés libres avant lancement. Captures inspectées à **1 440 et 390 px**,
  en **clair et sombre** : texte et bouton lisibles, aucun débordement,
  navigation par Tab et focus visible. Contrastes calculés du texte ≥ 14,94:1
  et du bouton focalisé ≥ 6,32:1. Il s'agit d'une émulation de largeur mobile
  sur ordinateur, sans essai sur téléphone physique ni lecteur d'écran.
  Les quatre captures sont dans `var/dictation-lot6-ux-{1440,390}-{light,dark}.png`.
  Cette inspection n'a démarré aucune tentative ni créé de `Lesson`.

Le serveur de cette vérification, sa base et ses deux stockages ont été
nettoyés ; les ports 8825/8826 sont libérés et Chrome dédié est fermé. Le
manifeste de la session manuelle précédente confirme également `cleaned`
après son échéance de 11:25:35, sans intervention sur cette session.
Le retrait du profil Chrome jetable
`var/dictation-lot6-ux-chrome-950a34e977244eab91990046c016dd6a` reste partiel :
Windows refuse la suppression de certains fichiers, même hors bac à sable.
Ce résidu ignoré par Git ne contient que les données synthétiques du contrôle.

Fichiers du correctif : `templates/course/show/_main.html.twig`,
`tests/Controller/Course/DictationJourneyTest.php`, ce contrat. Les scripts
temporaires et captures de vérification restent dans `var/`, ignoré par Git.
Les données de développement, médias réels, réglages locaux et archives de
revue sont préservés. Aucun commit, push, déploiement ou migration en
développement/production.

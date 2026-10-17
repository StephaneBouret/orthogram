# Lot 5 — reprise après les cours, finalisation et corrigé

Implémentation et vérifications du **17 octobre 2026**, sur `preprod`, à partir
de `28a607edec98f6cd2773dc0a622a90c448cc9e7b`. Livraison par commit local uniquement,
sans push, déploiement ni migration sur la base de développement. Les réglages locaux, médias réels,
versions publiées, archives ZIP et exports n’ont pas été modifiés. Le réglage
par défaut `DICTATION_LEARNER_ENABLED=0` dans `config/services.yaml` est conservé.
Le lot 6 n’est pas implémenté.

## Contrat et autorisations

La machine à états et le correcteur du lot 2A restent les seuls utilisés.
`DictationCourseProgress` et `SectionCompletionService` restent inchangés :
section non vide et tous ses cours validés par des `Lesson::DONE`. Une visite,
un signet, une leçon `STUDY` ou un indicateur du navigateur ne suffit pas.

- `POST /dictees/tentatives/{id}/reprendre` appelle `Resume` avec la révision et
  `copy: null`. Le serveur initialise exactement le brouillon avec la copie
  clôturée et choisit `resuming` ou `reviewing` à partir de `noErrors`.
- `POST /dictees/tentatives/{id}/finaliser` appelle `Finalize`. En `resuming`,
  la copie affichée est fournie explicitement ; en `reviewing`, `copy: null`
  conserve le résultat clôturé, sans nouvelle évaluation.
- Les GET affichent la phase courante ; ils ne créent aucune tentative et ne
  déclenchent aucune transition. Le démarrage reste un POST distinct.
- Activation, authentification, compte actif, propriété, droits `CourseVoter`,
  CSRF propre à l’action, forme du JSON, révision et reçus sont contrôlés côté
  serveur. Les mutations JSON ne renvoient que révision/état ; SAVE conserve
  son accusé révision/décomptes.
- La complétion est recontrôlée sous les verrous existants. Pour `Resume` et
  `Finalize`, ce contrôle précède désormais **même le rejeu d’un reçu exact**.
  Les autres règles de transition et le calcul du score ne changent pas.
- Les pistes privées sont autorisées en `resuming`, toujours depuis la version
  attachée à la tentative, avec les mêmes contrôles de propriété et de droits.

Le paramètre interne `initialOnly` devient `learnerView`. Il sélectionne une
projection apprenant explicitement étendue, **pas** la projection générale :

| Phase | Données et interface accessibles |
| --- | --- |
| `writing` | Brouillon, décomptes, segments et première évaluation |
| `self_correction` | Première copie et signalements figés, brouillon d’autocorrection |
| `awaiting_courses` | Copie/résultat clôturés sans positions ; reprise proposée seulement si les cours sont complets |
| `resuming` | Brouillon, décomptes et segments ; aucun premier résultat, signalement, position ou corrigé |
| `reviewing` | Copie/résultat clôturés sans positions ; finalisation explicite sans nouvelle copie |
| `completed` | Copie/résultat définitifs ; texte canonique et explications uniquement si la complétion actuelle autorise leur consultation |

Les variantes, métadonnées privées de stockage et premières positions restent
absentes des nouvelles phases, y compris après rejeu d’une ancienne évaluation.
Le corrigé utilise exclusivement la version de la tentative. Le texte canonique
est affiché sous « Texte corrigé » ; les explications, indépendantes et
facultatives, sous « Les explications de Caroline ». Twig échappe les textes et
les blocs existants `white-space: pre-wrap` préservent leurs retours à la ligne.

Sans erreur, la provenance reste `initial_closure` et les champs internes de
nouvelle évaluation finale restent nuls. Un score lexical de 100 % avec une zone
de ponctuation incorrecte passe bien par `resuming`. Après finalisation avec
erreurs restantes, la provenance est `post_courses` ; terminer ne demande pas
un résultat parfait.

Si une validation disparaît ou qu’un cours est ajouté, les nouvelles réponses
masquent le corrigé et les explications. La copie et le résultat définitifs
restent consultables et inchangés ; leur affichage n’accorde pas l’accès au
corrigé. Une nouvelle validation permet de retrouver le même corrigé. Un texte
déjà livré à un navigateur ne peut naturellement pas être retiré rétroactivement.

## Édition et reprise des opérations

L’éditeur et le protocole de sauvegarde des lots 3/4 sont réutilisés : caractères
spéciaux, décompte serveur, lecteurs, segments, raccourcis, récupération du texte,
absence de SAVE doublonné et absence de fusion automatique lors d’un conflit.
« Terminer » annonce que la copie affichée deviendra définitive et ouvrira le
corrigé. Le bouton capture cette copie, verrouille la saisie, attend le reçu de
toute SAVE en vol/incertaine, puis finalise avec la révision confirmée. Il ne
substitue pas un ancien brouillon à la saisie affichée.

Une réponse perdue conserve l’opération exacte à rejouer, copie et révision
incluses. Le nouveau petit contrôleur `dictation-phase` gère seulement les POST
sans saisie de reprise et de consultation sans erreur ; il ne porte aucune
seconde machine à états métier. Un conflit invite à recharger ; une opération
incertaine peut être réessayée à l’identique.

## Vérifications automatisées réellement exécutées

Environnement : PHP 8.4.15, PHPUnit 13.2.3, MySQL local, Chrome headless réel via
CDP sous Windows. Schémas jetables `orthogram_dictation_2a_<16 hex>_test`, médias
MP3 synthétiques et stockages temporaires du support de tests. Les migrations
existantes sont appliquées uniquement à ces schémas. À ce stade, aucun serveur de
recette manuelle n’avait encore été lancé ; la session manuelle autorisée ensuite
et ses observations sont consignées séparément ci-dessous.

```powershell
vendor\bin\phpunit --do-not-cache-result tests/Services/Dictation tests/Services/Courses/SectionCompletionServiceTest.php tests/Integration/DictationPersistenceTest.php tests/Integration/DictationEditorialMediaPersistenceTest.php tests/Integration/DictationDraftDeletionTest.php tests/Controller/Admin/DictationAdminTest.php tests/Controller/Course/DictationControllerTest.php

# Test ajouté ensuite : échappement, provenance et ajout d’un cours obligatoire.
vendor\bin\phpunit --do-not-cache-result --filter testCorrectionEscapingProvenanceAndNewRequiredCourse tests/Controller/Course/DictationControllerTest.php

vendor\bin\phpstan analyse --configuration=tests/Services/Dictation/phpstan.dist.neon --no-progress --error-format=table src/Services/Dictation src/Entity/DictationAttempt.php src/Controller/Course/DictationController.php tests/Controller/Course/DictationControllerTest.php tests/manual/serve_dictation_lot5_browser.php

vendor\bin\php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no src/Controller/Course/DictationController.php src/Entity/DictationAttempt.php src/Services/Dictation/DictationAttemptService.php tests/Controller/Course/DictationControllerTest.php tests/manual/serve_dictation_lot5_browser.php

php bin/console lint:container --env=test
php bin/console lint:twig templates/dictation --env=test
php -l tests/manual/serve_dictation_lot5_browser.php
git diff --check

$node = 'C:\Users\STEF-LEGION\AppData\Local\Logi\LogiPluginService\PluginHosts\node22\node\node.exe'
& $node --check assets/controllers/dictation_writing_controller.js
& $node --check assets/controllers/dictation_phase_controller.js
& $node --check assets/stimulus_bootstrap.js
& $node --check tests/manual/dictation_lot5_browser_check.cjs
```

Résultats : suite complète **366 tests, 2 468 assertions**, succès en 66,128 s ;
test complémentaire **1 test, 31 assertions**, succès en 4,601 s. La suite HTTP
intermédiaire comptait 24 tests et 563 assertions réussis. PHPStan sans erreur,
CS Fixer sans modification restante, Twig (6 fichiers), conteneur et syntaxe
JavaScript valides. L’avertissement local Xdebug sur son journal inaccessible
n’empêche pas ces contrôles.

Les tests HTTP couvrent notamment les validations réelles, refus anticipés,
révisions/rejeux/CSRF, sauvegarde et rechargement, deux parcours sans erreur,
ponctuation à 100 %, erreurs finales restantes, absence d’explications, nouvelle
publication, perte d’une validation, ajout d’un cours, accès croisés, contrôles
d’accès existants et absence des champs confidentiels. Le test de provenance
compare le résultat final présenté au résultat clôturé enregistré.

Recette navigateur automatisée, après vérification que les ports 8815/8816 sont
libres, sans arrêter de session existante :

```powershell
php -d xdebug.mode=off tests/manual/serve_dictation_lot5_browser.php --automated
# Autre terminal, profil dédié :
Start-Process -FilePath 'C:\Program Files\Google\Chrome\Application\chrome.exe' -ArgumentList '--headless=new','--disable-gpu','--remote-debugging-port=8816','--user-data-dir=C:\Formations\orthogram\var\dictation-lot5-chrome','--no-first-run','--no-default-browser-check','about:blank' -WindowStyle Hidden
& $node tests/manual/dictation_lot5_browser_check.cjs
New-Item -ItemType File -Path var/dictation-5-browser.stop

# Nouvelle base, parcours initiaux vierges ; même Chrome de test :
php -d xdebug.mode=off tests/manual/serve_dictation_lot5_browser.php --regression
& $node tests/manual/dictation_lot3_browser_check.cjs
& $node tests/manual/dictation_lot4_browser_check.cjs
New-Item -ItemType File -Path var/dictation-5-browser.stop
```

Le script lot 5 vérifie avec de vraies requêtes/commits : SAVE A en vol puis
Finalize B, double clic, réponse de finalisation perdue et rejeu exact, SAVE
incertaine avant Finalize, deux vrais onglets concurrents et copie locale
récupérable, rechargement du résultat, Resume et Finalize sans copie rejoués,
corrigé avec/sans explications, absence de premiers signalements et HTML inerte.
Clair/sombre à **1 440 px et 390 px**, pas de débordement horizontal, Tab/Entrée,
caractères spéciaux, F8, lecture réelle de la fixture audio, borne à 600 ms et
exclusivité des pistes : succès. Captures `var/dictation-lot5-*.png`, hors Git ;
captures du bilan bureau clair et mobile sombre et de la reprise mobile sombre
inspectées visuellement.

Les scripts navigateur existants des **lots 3 et 4 ont également terminé avec
succès**, sans modification de leurs scénarios, sur une nouvelle base du harnais
en mode `--regression`. Ils couvrent notamment les SAVE sans doublons, limites
422, refus 403/409, récupération/téléchargement, Unicode, premiers signalements,
clôture et raccourcis. Les deux dernières sessions automatisées ont confirmé
`CLEANED isolated MySQL database and test storage.` ; Chrome dédié a été fermé
par CDP. Aucun serveur de ces recettes automatisées n’a été conservé.

### Incidents de construction des tests

- Le premier helper de validations utilisait un repository de service lié au
  manager du conteneur, au lieu du manager jetable : quatre tests ont échoué
  sur une lecture SQL (`is_free` absent), avant toute écriture de leçon. Ce
  premier essai ne respectait donc pas l’isolation de lecture attendue. Le helper
  utilise désormais exclusivement des requêtes DQL du manager isolé ; aucun
  correctif de schéma ni écriture sur cette autre connexion n’a été effectué.
- Une expression CDP contenait un saut de ligne non échappé : premier essai
  interrompu, expression corrigée avec `JSON.stringify`, puis recette complète
  relancée sur une nouvelle base.
- L’ancien lancement via Symfony Process laissait le serveur PHP derrière
  `cmd.exe` à l’arrêt sous Windows. Sa filiation a été vérifiée, puis seul le
  processus de test identifié a été arrêté. Le nouveau harnais utilise un handle
  direct `proc_open`/`proc_terminate` ; son arrêt a ensuite confirmé le nettoyage
  automatique de sa base et de ses stockages.

## Recette manuelle validée par l’utilisateur

Après les vérifications automatisées, l’utilisateur a demandé une session du
harnais en mode `--manual`, sur une nouvelle base et un nouveau stockage isolés.
Les sept parcours étaient préparés avec leurs copies initiales clôturées et les
validations Lesson nécessaires : la recette commençait volontairement après les
cours. Les GET de préparation ont confirmé les copies clôturées et l’action
« Reprendre ma dictée », sans consommer de reprise ni de finalisation. Aucun
scénario navigateur automatisé n’a été exécuté sur cette session. Les textes et
audios étaient synthétiques.

Les observations suivantes ont été effectuées et confirmées par l’utilisateur :

| Parcours | Observations manuelles | Résultat final observé |
| --- | --- | --- |
| Dictée 1 | Modification de la copie après reprise, sauvegarde puis rechargement avec texte conservé ; finalisation ; copie définitive, corrigé et explications affichés | **33,3 %**, **2 erreurs d’orthographe**, **1 zone de ponctuation** |
| Dictée 5 | Copie déjà sans erreur après autocorrection ; consultation sans nouvelle saisie puis finalisation ; aucune rubrique d’explications affichée lorsque les explications sont vides | **100 %** |
| Dictée 6 | Ajout du point final puis finalisation ; corrigé et explications affichés | **100 %**, **0 erreur d’orthographe**, **0 zone de ponctuation** |

La recette manuelle du lot 5 est validée sur ces observations. Cette validation
n’ajoute aucune validation manuelle des conflits entre onglets, du clavier, des
vrais médias, du mobile physique ou d’un lecteur d’écran. Les résultats des tests
automatisés restent ceux consignés dans la section précédente ; ils n’ont pas
été relancés pour cette mise à jour documentaire et la préparation du commit.

## Procédure pour renouveler la recette manuelle — sur demande explicite

Ne pas activer la fonctionnalité dans `.env.local` et ne pas utiliser la base de
développement pour cette recette. Après autorisation explicite, vérifier le port
8815 puis lancer :

```powershell
php -d xdebug.mode=off tests/manual/serve_dictation_lot5_browser.php --manual
```

Ce serveur local authentifie automatiquement un compte synthétique abonné et
prépare sept dictées indépendantes, avec de vraies validations Lesson. Il ne
convient pas à un déploiement. Ouvrir `http://127.0.0.1:8815/dictees/1`.

1. Sur les dictées 1 à 3, vérifier la copie clôturée puis « Reprendre ma dictée ».
   Écrire dans « Votre dictée après les cours », insérer des caractères, attendre
   « Enregistré », recharger et vérifier la copie. Aucun signalement ni corrigé
   ne doit apparaître. Vérifier lecteurs, segments et raccourcis.
2. Terminer avec des erreurs restantes. Vérifier l’annonce du caractère
   définitif, le bilan, la copie, le texte corrigé et les explications. Recharger.
3. Avec une dictée encore modifiable et deux onglets, terminer dans le premier
   puis tenter de terminer une copie différente dans le second. Vérifier le
   conflit, télécharger la copie locale avant de recharger, retrouver la copie
   définitive du premier onglet.
4. Dictée 4 : aucune erreur dès la première évaluation. Dictée 5 : aucune erreur
   après autocorrection et aucune explication éditoriale. Reprendre puis
   « Terminer et voir le corrigé » ; aucune saisie intermédiaire. Le texte corrigé
   doit apparaître dans les deux cas, le titre des explications seulement en 4.
5. Dictée 6 : score 100 % et ponctuation incorrecte ; vérifier la reprise
   modifiable. La dictée 7 fournit un autre parcours modifiable indépendant.
6. Refaire en clair/sombre, ordinateur et mobile physique. Vérifier le clavier
   physique (Fn/F8 selon le matériel), la sortie audio et un lecteur d’écran.
   Les refus avant validation et après perte de complétion sont couverts par les
   tests HTTP ; ne pas modifier la base de développement pour les reproduire.
7. En mode manuel, le harnais affiche un témoin d’arrêt unique
   `var/dictation-5-browser-<identifiant>.stop` et l’expiration exacte en heure de
   Paris. Créer ce fichier avec `New-Item -ItemType File -Path <chemin affiché>` et
   attendre `CLEANED isolated MySQL database and test storage.` Le délai maximal
   du harnais est de vingt minutes. Les modes automatisés gardent leur témoin habituel.

**Limites :** 390 px est une émulation Chrome sous Windows, pas un essai sur mobile
physique. Aucun lecteur d’écran ni clavier physique testé, aucune écoute humaine
des vrais médias ; le MP3 utilisé est synthétique. Les interruptions réseau sont
provoquées dans le harnais après de vrais commits. Comme au lot 3, une fermeture
forcée peut perdre une saisie non confirmée : attendre l’enregistrement ou
télécharger la copie en cas d’incertitude. Chaque session manuelle du harnais
expire et se nettoie automatiquement après vingt minutes, sauf arrêt anticipé.

## Fichiers concernés

- Serveur : `src/Controller/Course/DictationController.php`,
  `src/Services/Dictation/DictationAttemptService.php`, `src/Entity/DictationAttempt.php`.
- Interface : `templates/dictation/write.html.twig`, `closed.html.twig`,
  `completed.html.twig`, `_phase_action.html.twig`,
  `assets/controllers/dictation_writing_controller.js`,
  `assets/controllers/dictation_phase_controller.js`, `assets/stimulus_bootstrap.js`.
- Tests et documentation : `tests/Controller/Course/DictationControllerTest.php`,
  `tests/manual/serve_dictation_lot5_browser.php`,
  `tests/manual/dictation_lot5_browser_check.cjs`, ce contrat.

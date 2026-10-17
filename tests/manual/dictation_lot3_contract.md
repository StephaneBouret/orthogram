# Dictées — lot 3 : écriture, audio privé et sauvegarde

Point de départ : `preprod`, commit `ee31a894331af4ceb8f1fd6a292e1396d86fe6cb`.
Les contrats des lots 1, 2A et 2B ont été consultés. Ce lot ajoute uniquement la phase initiale `writing`. Aucun changement du barème, du tokenizer, des entités, des migrations ou des dépendances. Aucun commit, push ou déploiement réalisé. La base locale de recette et ses vrais médias ne sont pas utilisés par les vérifications ci-dessous. Les trois ZIP existants restent non suivis et intacts.

## Activation et accès

`DICTATION_LEARNER_ENABLED` vaut **0 par défaut**, via `config/services.yaml`. Pages, création, sauvegarde et médias apprenant vérifient ce drapeau côté serveur. Désactivés, ils répondent 404 (le pare-feu existant peut préalablement rediriger une session en cours de 2FA ou invalidée). La présence d’une URL ou d’un bouton ne constitue jamais une autorisation.

Chaque opération exige un utilisateur authentifié actif, puis `CourseVoter::SECTION_VIEW` sur la section de la dictée : abonnement actif ou accès administratif selon la politique existante ; authentification incomplète refusée. Aucun changement du voter, des cours gratuits, de la recherche, de l’autocomplétion, des accès historiques ou de la progression. Une tentative étrangère reste introuvable même pour un administrateur utilisant ces routes apprenant. Un compte désactivé est expulsé par le mécanisme de session existant ; une 2FA incomplète est redirigée vers sa validation. Le client refuse de suivre silencieusement une redirection de sauvegarde.

| Route | Méthode | Effet |
| --- | --- | --- |
| `/dictees/{dictationId}` | GET | Formulaire de démarrage, ou redirection vers sa tentative existante ; aucune création |
| `/dictees/{dictationId}/commencer` | POST | CSRF spécifique à la dictée, démarrage/reprise via le service 2A, redirection 303 |
| `/dictees/tentatives/{attemptId}` | GET | Copie persistée, compteurs, titre, segments et liens audio |
| `/dictees/tentatives/{attemptId}/enregistrer` | POST JSON | CSRF spécifique à la tentative ; seulement `revision` entier positif ou nul et `copy` chaîne |
| `/dictees/tentatives/{attemptId}/audio/{complete\|writing}` | GET, HEAD | Fichier privé de la version attachée, Range autorisé après contrôle de propriété et de formation |

La sélection de la dernière version publiée, l’unicité utilisateur/dictée et les verrous restent ceux de `DictationAttemptService`. Une tentative existante conserve sa version. Le mode interne `writingOnly` contrôle la phase sous le même verrou que la sauvegarde, **avant** le rejeu d’un reçu, puis utilise une projection distincte. Une ancienne version publiée sans pistes ou sans segments est refusée explicitement ; aucun rattachement silencieux à une autre version. Une première tentative n’est pas créée dans ce cas. Aucun prérequis de complétion des cours n’est activé.

Les seuls champs de la projection d’écriture sont `attemptId`, `revision`, `title`, `draft`, `wordCount`, `referenceWordCount`, `segments`. La réponse de sauvegarde ne contient que `revision`, `wordCount`, `referenceWordCount`. Le navigateur ne reçoit ni référence, ni variantes, ni commentaire de Caroline, ni résultat, ni score, ni signalement, ni clés de stockage. Pas de sérialisation d’entités. Les autres transitions n’ont pas de route. Tout champ supplémentaire dans une sauvegarde, notamment `action`, est refusé.

Réponses privées `no-store`, médias `audio/mpeg` et `nosniff`. Les erreurs inattendues donnent un message générique 503 et sont journalisées côté serveur ; le profiler est désactivé pour ces réponses afin de ne pas distribuer de données éditoriales de diagnostic. Le stockage privé et la résolution confinée du lot 2B sont réutilisés.

## Saisie et sauvegarde

Écran fondé sur `base.html.twig`, Bootstrap et les styles du site. Deux colonnes sur ordinateur, empilement sur mobile, thèmes clair/sombre. Label explicite, statuts accessibles, boutons de caractères utilisables avec Tab/Entrée. Une sélection est remplacée par le caractère choisi ; le curseur et le focus reviennent dans la copie. Sans JavaScript, la saisie reste désactivée avec une explication pour éviter une fausse impression d’enregistrement.

Zone de texte simple, `spellcheck=false`, `autocorrect=off`, `autocapitalize=off`, `autocomplete=off`. Ces attributs ne permettent pas de bloquer les extensions ni les aides du système. Les caractères prévus sont tous présents. Pas de normalisation Unicode ni de troncature côté serveur ; la zone de texte du navigateur utilise ses sauts de ligne natifs LF. Le template fournit un LF initial supplémentaire absorbé par le parseur HTML, afin de préserver un véritable saut de ligne initial dans la copie. La recette vérifie accents décomposés, caractère hors BMP, espace insécable, ligature et saut de ligne initial.

Le compteur est calculé par `DictationTokenizer::countWords()`, sans implémentation concurrente en JavaScript. Les élisions et mots composés gardent le contrat du lot 1. À la moindre modification, le compteur indique qu’il attend l’enregistrement. Il n’affiche une valeur à jour que pour le texte confirmé actuellement visible. X peut dépasser Y. Limites existantes : 750 unités de copie, 20 000 unités UTF-16 ; protection supplémentaire de 125 000 octets sur le JSON avant décodage (compatible avec les échappements Unicode).

Une pause de **600 ms** déclenche SAVE. Une seule requête en vol, avec `{revision, copy}` figé ; les frappes suivantes sont regroupées. La réponse ne remplace jamais le champ. La révision confirmée est avancée uniquement à réception d’un accusé cohérent ; « Enregistré » n’apparaît que si la copie visible correspond exactement à celle confirmée. Une nouvelle copie en attente est ensuite envoyée.

Chaque départ de sauvegarde annule la temporisation de frappe encore programmée. Si le texte visible est déjà confirmé et qu’aucune opération incertaine n’attend son accusé, aucune nouvelle opération n’est créée. Le dernier décompte serveur confirmé est mémorisé avec la copie, même lorsque d’autres frappes sont déjà visibles : revenir au texte confirmé rétablit immédiatement son compteur et le statut propre, sans requête. Une opération incertaine garde toujours la priorité sur cette comparaison de textes.

Après réponse perdue, erreur réseau, délai de 15 secondes ou refus temporaire, le bouton **Réessayer l’enregistrement** rejoue d’abord la même opération (même révision et mêmes octets). Le reçu 2A résout une réponse perdue après commit. Aucun nouvel identifiant ou révision n’est inventé. Une validation 413/422 signifie un refus explicite : la copie reste visible et une nouvelle édition permet une nouvelle demande avec la révision précédente.

Pour ce seul refus explicite de validation sans écriture, la copie refusée est comparée à la copie désormais visible. Si elles sont identiques, l’erreur reste affichée jusqu’à correction. Si elles diffèrent, la saisie a déjà été corrigée pendant la requête : elle est traitée dès la fin de celle-ci, avec la révision inchangée, même si sa temporisation est déjà expirée. Si cette correction revient au texte confirmé, l’état propre est restauré sans écriture. Ce déblocage ne s’applique ni aux conflits 409, ni aux refus d’accès, ni aux issues inconnues.

Un 409 arrête les sauvegardes automatiques. Il conserve le texte et la révision locale, explique le conflit et propose **Télécharger ma copie** (texte UTF-8). Pas de fusion, de rechargement ou d’écrasement automatique. Après récupération et rechargement volontaire, la copie persistée de l’autre onglet est affichée. Un jeton/session expiré nécessite récupération puis reconnexion/rechargement ; aucun contournement CSRF.

Le téléchargement de secours est **masqué dans le parcours normal**, y compris pendant la saisie et l’enregistrement ordinaires. Il apparaît après un conflit, un refus d’accès ou un échec de sauvegarde non résolu, dont une limite de validation ou une confirmation perdue. Il reste disponible pendant la tentative de récupération jusqu’à confirmation de la copie actuellement visible, puis disparaît. Il télécharge exactement le contenu courant du textarea, sans déclencher de SAVE ni changer la révision serveur.

`beforeunload` protège la sortie tant qu’une opération ou une saisie n’est pas confirmée ; `turbo:before-visit` protège les navigations Turbo. Le cache de page Turbo est désactivé. Une restauration depuis le cache natif du navigateur recharge une page propre ; si elle contient une saisie non confirmée, elle demande sa récupération. Aucune sauvegarde à la fermeture ni stockage persistant automatique dans le navigateur. Une fermeture forcée ou la suppression d’un onglet par le système peut donc perdre les frappes non confirmées : attendre « Enregistré » ou télécharger la copie.

## Audio

Deux éléments audio privés, `preload=none`, sans lecture automatique. Lecture/pause complète, sélection numérotée des segments, précédent/suivant, lecture/pause et réécoute du segment. Le changement de sélection suspend la lecture ; il ne lance pas le segment suivant. Une seule piste joue à la fois. Les promesses de lecture sont protégées par un compteur de génération ; les changements rapides ne réactivent pas une ancienne sélection. Erreurs de chargement visibles, écouteurs et minuteries supprimés à la déconnexion Stimulus.

L’intitulé visible **Écoute complète** précède son bouton à icône. La piste d’écriture conserve son sélecteur et la disposition **Précédent / icône lecture-pause / Suivant**, ainsi que **Réécouter le segment**. Les pictogrammes réutilisent Bootstrap Icons déjà présent ; aucun ajout de dépendance, aucun lecteur vertical supplémentaire. Le code change les classes de l’icône et les attributs du bouton, sans remplacer son contenu. Noms accessibles et infobulles décrivent l’action disponible et le numéro du segment. L’état vient de `paused`/`ended` du média actif et des événements `play`, `playing`, `pause`, `ended`, `emptied` et `error`. Le contrôle de borne démarre à la lecture effective. Cibles de commande et sélecteur d’au moins 44 px, focus clavier explicite, thèmes clair/sombre conservés.

La borne est contrôlée par `timeupdate` et une minuterie de 20 ms pendant la lecture, puis la piste est suspendue et replacée sur la fin. Comme au lot 2B, il s’agit de la précision normale du lecteur HTML, pas d’une coupe audio à l’échantillon ; les onglets en arrière-plan peuvent subir la limitation des minuteries du navigateur. Aucun passage automatique au suivant.

Raccourcis désormais annoncés dans la saisie : **F8**, lecture/pause du segment ; **Maj + F8**, segment précédent ; **Ctrl + F8**, segment suivant. Selon le clavier, Fn peut être nécessaire. Les anciennes combinaisons Alt+Maj+Espace/←/→ restent reconnues lorsqu’elles parviennent au navigateur. Le code utilise `key`, avec repli sur `code` si `key` est vide ou `Unidentified`. Les autres touches, les raccourcis usuels d’édition, AltGraph, les répétitions et la composition IME ne sont pas interceptés. Les boutons restent disponibles si le système réserve une combinaison de touches. La vérification CDP sous Windows ne constitue pas une validation du clavier physique et des raccourcis globaux du système.

## Vérifications du 17 octobre 2026

PHP 8.4.15, MySQL 8.4.7, PHPUnit 13.2.3, Chrome headless réel via CDP. Bases `orthogram_dictation_2a_<16 hex>_test` et stockages temporaires de `DictationTestDatabase` / `DictationAudioFixture`. Le suffixe historique « 2a » désigne l’infrastructure partagée, pas la base de développement. Les migrations existantes sont appliquées uniquement à ces bases jetables ; aucune nouvelle migration. Le serveur de recette vérifie le nom de connexion avant toute requête applicative. Fixture MP3 synthétique existante, aucune voix de Caroline.

Commandes exécutées depuis la racine :

```powershell
vendor\bin\phpunit --do-not-cache-result tests/Services/Dictation tests/Services/Courses/SectionCompletionServiceTest.php tests/Integration/DictationPersistenceTest.php tests/Integration/DictationEditorialMediaPersistenceTest.php tests/Controller/Admin/DictationAdminTest.php tests/Controller/Course/DictationControllerTest.php

vendor\bin\phpstan analyse --configuration=tests/Services/Dictation/phpstan.dist.neon --no-progress --error-format=table src/Services/Dictation src/Entity/DictationAttempt.php src/Controller/Course/DictationController.php tests/Services/Dictation tests/Controller/Course/DictationControllerTest.php tests/manual/serve_dictation_lot3_browser.php tests/manual/dictation_lot3_browser_router.php

vendor\bin\php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no src/Controller/Course/DictationController.php src/Services/Dictation/DictationAttemptService.php tests/Controller/Course/DictationControllerTest.php tests/manual/serve_dictation_lot3_browser.php tests/manual/dictation_lot3_browser_router.php

php bin/console lint:container --env=test
php bin/console lint:twig templates/dictation --env=test
git diff --check

$node = 'C:\Users\STEF-LEGION\AppData\Local\Logi\LogiPluginService\PluginHosts\node22\node\node.exe'
& $node --check assets/controllers/dictation_writing_controller.js
& $node --check tests/manual/dictation_lot3_browser_check.cjs

php -d xdebug.mode=off tests/manual/serve_dictation_lot3_browser.php
# Dans un autre terminal, navigateur de test avec profil distinct :
Start-Process -FilePath 'C:\Program Files\Google\Chrome\Application\chrome.exe' -ArgumentList '--headless=new','--disable-gpu','--remote-debugging-port=8816','--user-data-dir=C:\Formations\orthogram\var\dictation-lot3-chrome','--no-first-run','--no-default-browser-check','about:blank' -WindowStyle Hidden
& $node tests/manual/dictation_lot3_browser_check.cjs
# Nettoyage du serveur, de sa base et de son stockage :
New-Item -ItemType File -Path var/dictation-3-browser.stop
```

Résultat de la suite PHP : **339 tests, 1 959 assertions**, 34,612 s, 68 Mio, succès. Les 12 cas HTTP supplémentaires couvrent création POST/CSRF, GET sans création, unicité et version conservée, données confidentielles absentes, SAVE et rejeu, révision concurrente, décompte et Unicode, limites, accès croisés, deux rôles audio/HEAD/Range, désactivation, compte/session/abonnement/2FA et refus des autres phases. Une ancienne version publiée sans média ne crée aucune tentative. PHPStan sans erreur ; PHP CS Fixer sans correction restante ; conteneur, Twig et syntaxe JavaScript valides. L’avertissement local Xdebug sur son fichier de journal inaccessible n’empêche pas ces contrôles.

Recette navigateur : reprise Unicode exacte, compteur 5/3, insertion au curseur, réponse réellement retardée après commit, réponse perdue après commit puis rejeu strict, modifications regroupées, **deux vrais onglets** en conflit, téléchargement UTF-8 de la copie locale, sortie refusée, copie distante intacte après rechargement volontaire, réponse 401 simulée et limite de mots réellement refusée par le serveur, lecture bornée à 600 ms, exclusivité des pistes et changements rapides. Contrôles en 1 440 px et 390 px, clair/sombre, sans débordement horizontal ; Tab/Entrée et raccourci pendant la frappe. Captures sous `var/dictation-lot3-*.png`, téléchargements et profil Chrome sous `var/`, tous ignorés par Git. Les erreurs de récupération de téléchargement et de synchronisation du deuxième onglet rencontrées pendant la construction du script ont été corrigées dans le banc de test. Le défaut du saut de ligne initial trouvé dans le template a été corrigé et vérifié dans Chrome. Aucun résultat simulé n’est présenté dans l’application.

Vérifications complémentaires réussies : sélection du troisième segment avant le chargement initial du MP3, arrêt à sa borne de 1 800 ms, erreur réelle de chargement audio affichée sans perte de copie, insertion de « À » par Tab/Entrée avec retour au champ. Le contrôle d’espaces Git a aussi été appliqué aux dix nouveaux fichiers avec `git diff --no-index --check` (prise en compte des fins de ligne Windows). Le serveur de recette et Chrome ont été arrêtés ; le serveur a confirmé `CLEANED isolated MySQL database and test storage`. Aucun écouteur de test ne reste sur 8815/8816. Les captures, téléchargements et profils restent des artefacts ignorés sous `var/`.

## Recette locale à effectuer volontairement

La fonctionnalité reste désactivée dans l’environnement de développement. `.env.local` n’a pas été modifié. Pour l’activer seulement lors de la recette, définir **`DICTATION_LEARNER_ENABLED=1`** dans l’environnement du serveur PHP local, ou ajouter cette variable dans `.env.local`, puis redémarrer le serveur si nécessaire. Ne modifier ni `DATABASE_URL` ni le stockage ; aucune migration supplémentaire n’est requise. Revenir à `0` (ou retirer la surcharge) pour fermer l’accès.

1. Se connecter avec un compte actif ayant accès à la formation, terminer sa 2FA si nécessaire.
2. Ouvrir **`http://127.0.0.1:8000/dictees/<identifiant-de-la-dictee>`**. L’identifiant est celui de la dictée dans l’URL EasyAdmin `/admin/dictees/{id}`, pas le numéro de version ni l’identifiant de section. Aucun contenu de développement n’a été interrogé pour en déduire cet identifiant.
3. Cliquer « Commencer ou retrouver ma copie ». Cette action crée, si nécessaire, la tentative de ce compte sur la dernière version publiée ; le brouillon éditorial existant est ignoré et reste intact.
4. Écouter les deux vrais MP3, sélectionner les segments, vérifier la pause à leur fin et les raccourcis. Essayer sur mobile réel, avec son clavier et la sortie audio habituelle.
5. Saisir des élisions, accents et caractères spéciaux, attendre « Enregistré », recharger et vérifier la copie. Essayer deux onglets : sauvegarder dans le premier puis dans le second, récupérer le texte du second avant de recharger.
6. Vérifier clair/sombre et navigation clavier. Aucune évaluation, correction, clôture, reprise après cours ou finalisation n’est proposée dans ce lot.

## Fichiers et suites du parcours

- Serveur : `src/Controller/Course/DictationController.php`, `src/Services/Dictation/DictationAttemptService.php`, `config/services.yaml`.
- Interface : `templates/dictation/entry.html.twig`, `templates/dictation/write.html.twig`, `assets/controllers/dictation_writing_controller.js`, `assets/styles/dictation.css`, enregistrement dans `assets/stimulus_bootstrap.js`.
- Tests : `tests/Controller/Course/DictationControllerTest.php`, `tests/manual/serve_dictation_lot3_browser.php`, `tests/manual/dictation_lot3_browser_router.php`, `tests/manual/dictation_lot3_browser_check.cjs` et ce contrat.

Pas de lien ajouté aux cours existants ni de verrou pédagogique imposé. Les prochains lots devront intégrer les entrées du parcours et le prérequis aux accès directs, recherche/autocomplétion, extraits et actions serveur en préservant les accès historiques et les cours gratuits selon les décisions des contrats 1/2A. Ils devront aussi brancher explicitement les autres transitions et leurs projections. Aucun de ces travaux n’est commencé ici.

## Correctif ciblé des courses de sauvegarde — 17 octobre 2026

Reproductions réalisées **avant modification du contrôleur**, dans Chrome avec le serveur MySQL/stockage isolé du lot 3. Le script retient la livraison de la première réponse après une véritable requête au serveur ; il ne simule pas le commit ni le refus de longueur.

- Doublon observé : après une sauvegarde de préparation, requêtes `{revision:1, copy:"A"}`, `{revision:2, copy:"B"}`, puis `{revision:3, copy:"B"}`. La copie persistée était B, révision 4, compteur 1/3. Le `finally` lançait B sans annuler la temporisation restante ; `save()` ne vérifiait pas si B était déjà confirmée.
- Refus observé : la copie de 751 mots était envoyée à la révision 5 ; la saisie était remplacée par « Copie corrigée » avant livraison du 422. Une seule requête était envoyée. Le navigateur gardait le message de limite et le compteur en attente ; la base conservait « Avant refus », révision 5, compteur 2/3. Le blocage de validation s’appliquait indistinctement à la copie refusée et à la nouvelle saisie.

Après correction, vérifications par assertions sur les requêtes, leurs révisions, les réponses, le texte visible, le statut, le compteur et une nouvelle consultation GET de la copie persistée :

| Séquence | Résultat réellement vérifié |
| --- | --- |
| A en vol, saisie B, réponse A avant 600 ms | Deux requêtes seulement, révisions attendues r puis r+1 ; B persistée à r+2 ; « Enregistré », 1/3 ; aucune requête supplémentaire après l’échéance |
| Modification puis retour au texte confirmé avant temporisation | Zéro requête ; compteur confirmé et « Enregistré » restaurés immédiatement |
| Copie corrigée avant réception du 422 | Deux requêtes à la même révision r, réponses 422 puis 200 ; « Copie corrigée » persistée à r+1, compteur 2/3 et « Enregistré ». Vérifié avec temporisation encore en attente puis déjà expirée |
| 422, copie refusée toujours visible | Une seule requête ; erreur et compteur en attente conservés jusqu’à correction |
| Retour à la copie confirmée avant ou après le 422 | Aucune écriture supplémentaire ; révision inchangée, compteur 2/3 et état propre |
| Réponse perdue après commit, texte visible revenu à la valeur précédemment confirmée | Pas de fausse confirmation ; trois requêtes : opération initiale, rejeu strictement identique, puis réenregistrement du texte visible à r+1 ; copie finale persistée à r+2 |
| 403 réel retardé, nouvelle saisie avant livraison | Une seule requête, révision persistée inchangée, refus visible ; aucune sauvegarde automatique de la nouvelle saisie |
| Conflit entre deux vrais onglets, 409 retardé après une nouvelle frappe | Une seule requête de l’onglet en conflit ; texte local conservé, compteur en attente ; copie distante intacte après récupération et rechargement |

Commandes réellement exécutées pour ce correctif (Node désigne l’exécutable local indiqué plus haut) :

```powershell
# Avant correction du contrôleur :
& $node tests/manual/dictation_lot3_browser_check.cjs --observe-races-before
# Après correction, cas ciblés puis recette navigateur complète existante :
& $node tests/manual/dictation_lot3_browser_check.cjs
& $node --check assets/controllers/dictation_writing_controller.js
& $node --check tests/manual/dictation_lot3_browser_check.cjs
git diff --check
# Ces trois fichiers du lot 3 sont encore non suivis : contrôler aussi leur contenu.
git -c core.whitespace=blank-at-eol,blank-at-eof,space-before-tab,cr-at-eol diff --no-index --check -- /dev/null assets/controllers/dictation_writing_controller.js
git -c core.whitespace=blank-at-eol,blank-at-eof,space-before-tab,cr-at-eol diff --no-index --check -- /dev/null tests/manual/dictation_lot3_browser_check.cjs
git -c core.whitespace=blank-at-eol,blank-at-eof,space-before-tab,cr-at-eol diff --no-index --check -- /dev/null tests/manual/dictation_lot3_contract.md
```

Résultats : recette navigateur complète réussie, y compris réponse perdue avec nouvelles frappes, deux onglets, Unicode, récupération, navigation, audio, clavier, clair/sombre et mobile ; aucune exception JavaScript. Syntaxe JavaScript et contrôles d’espaces conformes. La suite PHP et les analyses PHP ne sont pas relancées pour ce correctif exclusivement JavaScript/documentation ; leurs résultats précédents restent ceux de la section initiale, et aucun code serveur n’est modifié.

Seuls trois fichiers ont été retouchés : `assets/controllers/dictation_writing_controller.js`, `tests/manual/dictation_lot3_browser_check.cjs` et le présent contrat. Le serveur a confirmé le nettoyage de sa base et de son stockage isolés ; Chrome a été fermé et les ports 8815/8816 n’ont plus d’écouteur. Les données locales, médias, archives et autres travaux sont préservés. Aucun commit, push, déploiement ou travail du lot suivant.

## Ajustements audio, récupération et clavier — 17 octobre 2026

### Diagnostic clavier avant modification

Observation avec le focus dans `#dictation-copy`, écouteur de diagnostic après le gestionnaire applicatif et commandes CDP `Input.dispatchKeyEvent`. Navigateur réellement exécuté : **Chrome 155.0.8059.80 headless, Windows NT 10.0 / Win64**. Les essais ont utilisé uniquement le serveur de recette isolé.

| Événement reçu | Résultat avant correction |
| --- | --- |
| `key=ArrowRight`, `code=ArrowRight`, Alt+Maj, sans Ctrl/Meta | Segment suivant sélectionné, événement traité (`defaultPrevented=true`), focus et sélection conservés |
| `key=ArrowLeft`, `code=ArrowLeft`, mêmes modificateurs | Segment précédent sélectionné, événement traité |
| `key=" "`, `code=Space`, mêmes modificateurs | Lecture du segment démarrée, événement traité |
| `key=ArrowRight`, `code=""`, Alt+Maj | Aucune action, `defaultPrevented=false` |
| `key=ArrowRight`, `code=ArrowRight`, Alt+Maj+Ctrl | Aucune action, `defaultPrevented=false` |

**Conclusion et limite :** le défaut manuel « les trois commandes ne fonctionnent pas » n’est pas reproduit lorsque les événements complets attendus arrivent au navigateur. Le défaut de code établi est l’usage exclusif de `event.code`, qui ignore un événement ayant seulement un `key` exploitable. Le refus lorsque Ctrl est aussi actif est une protection volontaire contre d’autres combinaisons, notamment AltGr, et n’est pas supprimé. Le cas avec `code` vide a été injecté explicitement pour vérifier cette fragilité ; il ne prouve pas que le clavier physique de la recette émet ce cas.

Les recherches dans les gestionnaires de touches du projet n’ont pas révélé de blocage en amont du contrôleur sur cet écran. Les lectures seules des réglages de disposition/changement de langue Windows n’ont fourni aucune valeur exploitable pour attribuer l’échec à un raccourci système. **Aucune réservation Windows d’Alt+Maj n’est donc affirmée comme cause démontrée.** CDP injecte les événements dans Chrome et ne teste pas leur interception préalable par le système, un utilitaire clavier ou une touche Fn. Aucun réglage Windows ou Chrome local n’a été modifié.

L’alternative F8/Maj+F8/Ctrl+F8 a été vérifiée dans ce navigateur avec une sélection non vide, de direction arrière, dans le textarea. Les trois actions et la pause fonctionnent sans déplacer le focus, le curseur ni la sélection et sans modifier le texte. L’ancien raccourci avec `code` vide fonctionne maintenant grâce à `key` ; le repli inverse (`key=Unidentified`, `code=F8`) est aussi vérifié. L’aide visible et `aria-keyshortcuts` annoncent la nouvelle combinaison. Il reste à essayer ces touches sur le **clavier physique** de l’utilisateur ; cela n’est pas présenté comme une validation système Windows complète.

### Changements et vérifications

Cinq fichiers retouchés pour cet ajustement :

- `assets/controllers/dictation_writing_controller.js` : mise à jour des icônes/noms/infobulles depuis les événements des médias, téléchargement contextuel, repli `key`/`code` et raccourcis F8 ; algorithme de sauvegarde préservé.
- `templates/dictation/write.html.twig` : intitulé Écoute complète, deux boutons à pictogrammes, aide et attributs accessibles, récupération masquée initialement.
- `assets/styles/dictation.css` : dimensions tactiles et focus visible.
- `tests/manual/dictation_lot3_browser_check.cjs` : diagnostic clavier avant correction et assertions de non-régression.
- Le présent contrat.

Commandes réellement exécutées, avec le même Node local que ci-dessus :

```powershell
# Avant modification du contrôleur :
& $node tests/manual/dictation_lot3_browser_check.cjs --observe-keyboard-before
# Après modification : recette complète sur données et stockage isolés.
& $node tests/manual/dictation_lot3_browser_check.cjs
php bin/console lint:twig templates/dictation --env=test
& $node --check assets/controllers/dictation_writing_controller.js
& $node --check tests/manual/dictation_lot3_browser_check.cjs
git diff --check
```

Résultats : recette navigateur complète réussie, sans exception JavaScript. Icônes, noms accessibles et `title` cohérents au repos, pendant la lecture, après pause par bouton/raccourci, pause directe du média, fin naturelle de piste, fin de segment, changement rapide de piste et erreur réelle de chargement. Aucun autoplay et exclusivité des pistes conservée. Récupération absente dans le parcours normal, présente après 403/409/422 ou réponse perdue, masquée après confirmation ; fichier téléchargé identique au texte local non enregistré, aucune nouvelle requête SAVE ni modification de la copie/révision persistée lors du clic.

Les courses A/B sans doublon, la correction avant 422 avec temporisation en attente ou expirée, le rejeu strict d’une opération incertaine et le conflit entre deux vrais onglets ont tous été rejoués. Les contrôles de sélection, Ctrl+flèche et saisie ordinaire confirment la préservation des commandes d’édition. Affichage examiné à 1 440 px et 390 px, en clair/sombre, sans débordement ; cibles de lecture et sélecteur mesurés à au moins 44 × 44 px, focus clavier vérifié dans les quatre configurations. Captures sous `var/dictation-lot3-*.png`, ignorées par Git. Deux templates Twig valides et syntaxe JavaScript conforme. Pas de modification PHP, de migration ou de dépendance ; les tests et analyses PHP précédents ne sont pas présentés comme relancés pour cet ajustement.

La validation humaine du clavier, postérieure à ces tests automatisés, est consignée ci-dessous. Les données, médias, archives et réglages locaux restent préservés. Aucun commit, push, déploiement ni travail du lot suivant.

Les sessions de serveur de test ont confirmé `CLEANED isolated MySQL database and test storage`, et Chrome a été fermé. Vérification finale en lecture seule hors bac à sable : aucun écouteur sur 8815/8816 et aucun processus PHP de recette lot 3. Les cinq fichiers retouchés, encore non suivis, ont aussi passé le contrôle d’espaces `git diff --no-index --check` avec prise en compte des fins de ligne Windows ; l’index Git reste inchangé.

## Validation manuelle locale du lot 3 — 17 octobre 2026

L’utilisateur confirme avoir validé les raccourcis sur son clavier physique, avec le focus dans le textarea « Votre copie » : F8 pour lire/suspendre le segment, Maj + F8 pour le précédent et Ctrl + F8 pour le suivant. Cette confirmation complète les essais CDP précédents pour son environnement ; elle ne constitue pas une validation de toutes les configurations clavier/système.

L’aide affichée précise désormais de cliquer dans « Votre copie » avant d’utiliser les raccourcis et rappelle la possibilité d’utiliser les boutons du lecteur. Seul ce texte du template a changé ; aucun comportement JavaScript n’a été modifié.

L’utilisateur confirme également la recette manuelle de sauvegarde et de conflit. Résultats validés sur son poste :

- Raccourcis avec le focus dans le textarea : **validés par l’utilisateur**.
- Sauvegarde puis reprise de la copie après rechargement : **validées par l’utilisateur**.
- Conflit entre deux onglets A et B : **validé par l’utilisateur**.
- Récupération du texte local non enregistré de B par téléchargement : **validée par l’utilisateur**.
- Après rechargement de B, restauration de la copie enregistrée dans A : **validée par l’utilisateur**.

La recette manuelle locale est donc validée pour ces scénarios. **Aucune recette sur mobile physique n’a été réalisée ou déclarée comme telle** : les vérifications à 390 px décrites plus haut correspondent uniquement à un viewport de navigateur automatisé. Cette validation ne s’étend pas à toutes les configurations de clavier, de navigateur ou de système.

Cette confirmation autorise le commit local du lot 3 sur `preprod`, avec ses correctifs et sa documentation, sous le message `feat(dictation): ajouter l’écriture apprenant et la sauvegarde automatique`. La fonctionnalité reste désactivée par défaut (`DICTATION_LEARNER_ENABLED=0`). Les archives, médias privés, sauvegardes, fichiers temporaires et réglages locaux restent exclus ; aucune modification de base ou de contenu de recette, aucun push, déploiement ou travail du lot 4.

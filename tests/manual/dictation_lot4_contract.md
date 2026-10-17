# Dictées — lot 4 : premier signalement, autocorrection et clôture

Livraison du 17 octobre 2026, depuis `preprod`, commit de départ
`0aed570b320d6df3fdd93246b3653914f0922455` (lot 3). Les contrats
[1](dictation_lot1_contract.md), [2A](dictation_lot2a_contract.md),
[2B](dictation_lot2b_contract.md) et [3](dictation_lot3_contract.md) restent les
références pour le moteur, les données, les médias et la sauvegarde.

## Parcours et périmètre

Seul le parcours `writing → evaluate → self_correction → close → awaiting_courses`
est exposé, avec clôture directe si le résultat initial porte `noErrors=true`.
Le contrôleur appelle les transitions existantes de `DictationAttempt` via le
service transactionnel du lot 2A. Aucun second workflow métier, nouveau calcul
de score, modèle, migration ou dépendance. Le moteur reste
`orthogram-dictation-v1`. SAVE ne fait que valider les limites et enregistrer le
brouillon ; il ne fait pas appel au correcteur. Le décompte utilise le tokenizer
existant. Les tests contrôlent l’absence de résultat après SAVE et l’immuabilité
du résultat initial pendant les sauvegardes d’autocorrection.

La configuration par défaut reste `DICTATION_LEARNER_ENABLED=0`. Aucun réglage
local, accès aux cours/recherche/autocomplétion, prérequis d’accès ou contenu de
développement n’a été modifié. La reprise après les cours et les explications de
Caroline restent hors de ce lot. Le prérequis est enregistré comme satisfait à
la clôture, quel que soit le score, sans activer son contrôle dans les cours.

## Interface

- « Voir mes erreurs » invite à relire et annonce un premier signalement unique.
- Le bilan affiche le pourcentage réel, les erreurs d’orthographe (accords compris)
  et les zones de ponctuation. Un score de 100 avec ponctuation incorrecte reste
  en autocorrection ; seule la valeur serveur `noErrors` décide de la clôture directe.
- « Votre première copie — erreurs repérées » reste figée, dans un panneau
  repliable ouvert initialement, dont la hauteur est bornée. L’éditeur conserve
  les deux tiers de la largeur sur grand écran ; les blocs se superposent sur
  petit écran. La première copie peut être repliée sans quitter l’autocorrection.
- « Votre autocorrection » conserve caractères spéciaux, décompte serveur,
  sauvegarde automatique, deux pistes exclusives, segments et F8/Maj+F8/Ctrl+F8.
  Pas de lecture automatique, de nouvel indice ni de validation en vert.
- « Terminer » annonce la fermeture des modifications jusqu’à la reprise après
  les cours. Aucune obligation de modifier tous les signalements. La page suivante
  affiche la copie clôturée et son bilan, sans annotations ni corrigé. Le retour
  utilise `app_course_section` avec les droits existants.

## Rendu fidèle et accessible

Le module `dictation_feedback.js` découpe **la copie originale** aux frontières
UTF-16 des intervalles. Les caractères sont ajoutés une seule fois, par nœuds
texte ou `textContent`, jamais par interprétation HTML. Chaque portion couverte
par plusieurs intervalles porte les descriptions de tous les signalements
concernés. Les repères de longueur nulle sont des boutons au point d’insertion,
ordonnés par position puis ordre du résultat serveur. Plusieurs omissions au même
point restent plusieurs repères. Leur symbole ↧ est généré en CSS : il ne modifie
pas le texte original du conteneur. Aucun signe ou mot attendu n’est fourni.

L’orange possède un contraste distinct en clair/sombre. Les soulignements, les
pointillés pour la ponctuation, les noms accessibles et les infobulles évitent
une distinction fondée uniquement sur la couleur. Les boutons natifs sont
activables au clavier et ont un focus visible. La copie annotée conserve espaces,
espaces insécables, accents décomposés et caractères hors BMP.

La copie initiale est également transportée dans un attribut JSON échappé : les
retours CR/CRLF d’une ancienne copie ou d’un appel API restent ainsi exacts,
contrairement au texte directement extrait du HTML, dont le parseur normalise
les fins de ligne. Les offsets s’appliquent à cette valeur originale. Le textarea
conserve le comportement natif du navigateur pour ses fins de ligne.

L’activation d’un signalement donne le focus à l’éditeur. Le module
`dictation_navigation.js` compare uniquement les deux copies de l’apprenant et
retrouve les passages inchangés dont la correspondance est unique dans tous les
alignements de coût minimal. Une sélection fiable est appliquée et rendue visible,
y compris après modification puis rechargement. Sinon, la sélection existante
reste intacte et un message accessible invite à retrouver le passage. Les
signalements restent ceux du premier calcul ; ce repérage ne juge jamais si une
erreur est corrigée. Les limites sont précisées dans le compte rendu UX ci-dessous.

## Projections et sécurité

Le paramètre interne `initialOnly` remplace l’ancienne frontière `writingOnly`.
Il ne donne pas accès à la projection générale : `initialProjection()` impose une
liste explicite de trois phases, vérifiée sous verrou avant le rejeu d’un reçu.

| Phase | Projection de la page |
| --- | --- |
| `writing` | Identifiant, état, révision, titre, brouillon, décomptes et segments |
| `self_correction` | Même base, plus `initialCopy` et le résultat public initial enregistré |
| `awaiting_courses` | Identifiant, état, révision, titre, copie clôturée, résumé réel sans `issues`, prérequis satisfait |

Les phases `resuming`, `reviewing` et `completed` sont refusées sur ces routes.
Le mode initial refuse également les actions Resume/Finalize. Les pistes restent
accessibles uniquement en écriture et autocorrection, avec propriété et droit
de section vérifiés. Aucun texte canonique, variante, réponse attendue, référence
privée de stockage ou commentaire de Caroline n’est projeté.

Deux routes POST spécifiques sont ajoutées : `/tentatives/{id}/evaluer` et
`/tentatives/{id}/terminer`, sous le préfixe `/dictees`. Chacune possède son jeton
CSRF lié à l’action et à la tentative. Il n’existe pas de route acceptant une
action arbitraire. Leur réponse contient seulement `revision` et `state` : après
accusé, la page est rechargée avec la projection serveur de la phase courante.
Un ancien reçu Evaluate rejoué après clôture ne restitue jamais les annotations.
SAVE conserve sa réponse limitée aux trois champs de révision/décomptes.

Authentification, compte actif, propriété, droit de section, limites JSON/texte,
réponses privées `no-store`, `nosniff` et masquage des exceptions restent actifs.
Les versions éditoriales et tentatives existantes restent attachées à leurs données.

## Coordination et concurrence

Au clic, le navigateur capture le texte visible, annule la temporisation SAVE,
verrouille l’édition et les boutons d’insertion/transition. Une sauvegarde déjà
envoyée doit être accusée avant d’attribuer la révision à Evaluate/Close. Une
nouvelle sauvegarde redondante du texte capturé n’est pas nécessaire : la
transition le soumet explicitement. Exemple vérifié : SAVE A à révision 0, puis
Evaluate B à révision 1 ; B devient la première copie à révision 2.

Une opération incertaine garde son corps exact `{revision, copy}` et son action.
« Réessayer l’opération » rejoue ce corps avant toute nouvelle opération. Un SAVE
incertain précédant Evaluate est lui aussi rejoué à l’identique, même si le texte
visible a changé. Pendant une transition incertaine, la saisie reste verrouillée
et le téléchargement de récupération reste disponible. Aucun délai ne vaut
confirmation. La protection contre la navigation considère aussi la transition
en attente. Les reçus restent ceux du serveur, sans nouvelle évaluation au rejeu.

Un refus 413/422 explicite libère l’édition, sans modifier la révision ni consommer
l’évaluation. Une nouvelle frappe permet une nouvelle soumission. Un 409 conserve
le texte local et impose une récupération/relecture volontaire, sans adoption
automatique de révision. Les refus d’accès et issues inconnues ne sont pas traités
comme une validation autorisant une nouvelle opération. Les doubles clics sont
bloqués dans l’interface ; les révisions, états et reçus garantissent aussi
l’unicité côté serveur, y compris entre onglets. Une sauvegarde périmée après
changement de phase est refusée. Après clôture, SAVE est refusé même avec la
révision courante.

## Vérifications réellement exécutées

Exclusivement sur MySQL local **isolé** `orthogram_dictation_2a_<16 hex>_test`
et stockage temporaire de fixtures, avec vérification du nom avant les requêtes
applicatives. Le serveur du lot 4 réutilise le routeur isolé du lot 3 ; ses sept
dictées synthétiques servent à des parcours indépendants. Aucun média réel ou
contenu de développement n’a été consulté/modifié.

Environnement : PHP 8.4.15, PHPUnit 13.2.3, Chrome headless sous Windows via CDP.
Node local utilisé dans les commandes ci-dessous :

```powershell
$node = 'C:\Users\STEF-LEGION\AppData\Local\Logi\LogiPluginService\PluginHosts\node22\node\node.exe'

vendor\bin\phpunit --do-not-cache-result tests/Services/Dictation tests/Services/Courses/SectionCompletionServiceTest.php tests/Integration/DictationPersistenceTest.php tests/Integration/DictationEditorialMediaPersistenceTest.php tests/Controller/Admin/DictationAdminTest.php tests/Controller/Course/DictationControllerTest.php

vendor\bin\phpstan analyse --configuration=tests/Services/Dictation/phpstan.dist.neon --no-progress --error-format=table src/Services/Dictation/DictationAttemptService.php src/Controller/Course/DictationController.php tests/Controller/Course/DictationControllerTest.php tests/manual/serve_dictation_lot4_browser.php
vendor\bin\php-cs-fixer fix --config=.php-cs-fixer.dist.php --using-cache=no src/Services/Dictation/DictationAttemptService.php src/Controller/Course/DictationController.php tests/Controller/Course/DictationControllerTest.php tests/manual/serve_dictation_lot4_browser.php
vendor\bin\php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no src/Services/Dictation/DictationAttemptService.php src/Controller/Course/DictationController.php tests/Controller/Course/DictationControllerTest.php tests/manual/serve_dictation_lot4_browser.php
php bin/console lint:twig templates/dictation --env=test
php bin/console lint:container --env=test
& $node --check assets/controllers/dictation_writing_controller.js
& $node --check assets/js/dictation_feedback.js
& $node --check tests/manual/dictation_lot4_browser_check.cjs

php -d xdebug.mode=off tests/manual/serve_dictation_lot4_browser.php
Start-Process -FilePath 'C:\Program Files\Google\Chrome\Application\chrome.exe' -ArgumentList '--headless=new','--disable-gpu','--remote-debugging-port=8816','--user-data-dir=C:\Formations\orthogram\var\dictation-lot4-chrome','--no-first-run','--no-default-browser-check','about:blank' -WindowStyle Hidden -PassThru
& $node tests/manual/dictation_lot4_browser_check.cjs
& $node tests/manual/dictation_lot3_browser_check.cjs
# Arrêt du serveur et nettoyage de sa base et de son stockage isolés :
New-Item -ItemType File -Path var/dictation-4-browser.stop

git diff --check
# Répété pour chacun des six nouveaux fichiers listés ci-dessous :
git -c core.whitespace=blank-at-eol,blank-at-eof,space-before-tab,cr-at-eol diff --no-index --check -- /dev/null assets/js/dictation_feedback.js
```

Résultat final PHP : **350 tests, 2 143 assertions**, succès, 46,238 s, 78 Mio.
Les contrôles ajoutés couvrent les transitions HTTP, les trois phases futures
refusées, CSRF/propriété/session/compte/abonnement/2FA/désactivation, résultats
réels, omissions/ajouts/élisions/liaisons/Unicode, validation refusée, SAVE tardif,
rejeux et confidentialité après clôture. Le moteur et les suites précédentes
passent. PHPStan : aucune erreur. PHP CS Fixer : aucun fichier à corriger sur les
quatre contrôlés. Quatre templates Twig et conteneur valides ; syntaxe des trois
fichiers JavaScript contrôlés valide. L’avertissement local Xdebug sur son fichier
de journal inaccessible n’empêche pas les contrôles PHP.
`git diff --check` ne signale aucun défaut ; le contrôle des six nouveaux fichiers
avec `--no-index --check` ne produit aucun diagnostic (code 1 attendu pour les
différences avec `/dev/null`). Aucun fichier n’a été indexé.

| Recette navigateur lot 4 | Résultat observé |
| --- | --- |
| SAVE A retardé, saisie B puis double clic Evaluate | Une sauvegarde et une évaluation ; B exacte, révision 2 ; saisie verrouillée pendant l’attente |
| SAVE en vol puis Close sur une autre copie visible | Close attend l’accusé et clôture le texte capturé, sans reprendre le brouillon précédent |
| Réponse Evaluate perdue après commit réel | Rejeu exact ; révision 1 conservée ; pas de deuxième signalement |
| Réponse Close perdue après commit réel | Rejeu exact ; copie clôturée réelle, sans annotations ni éditeur |
| SAVE incertain avant Evaluate | Rejeu SAVE identique, puis Evaluate du texte capturé avec la nouvelle révision |
| Deux vrais onglets Evaluate puis Close | Second onglet en conflit pour les deux actions, saisie locale intacte et récupération disponible |
| Évaluation vide 422 | Édition libérée ; correction et soumission ensuite possibles |
| Copie parfaite / score 100 avec ponctuation | Clôture directe positive / autocorrection conservée, respectivement |
| Autocorrection sans modifier tous les repères | Clôture autorisée ; bilan conserve la zone de ponctuation restante |
| Rendu HTML, Unicode, omissions, superpositions, repères au même point | Texte original intact, HTML inerte, ordre déterministe, CRLF/CR conservés |
| Changement de longueur avant activation clavier d’un repère | Passage inchangé retrouvé à sa nouvelle position ; sélection existante conservée en cas d’ambiguïté |
| Audio et F8/Maj+F8/Ctrl+F8 en autocorrection | Accès autorisé, navigation, lecture/pause et arrêt à 600 ms, sélection conservée |
| 1 440 px / 390 px, clair / sombre | Pas de débordement, focus visible ; captures examinées |

La recette complète du lot 3 a également été rejouée avec succès : sauvegardes
A/B sans doublon, retour au texte confirmé, corrections avant/après 422, rejeu
exact après réponse perdue, 403/409, récupération UTF-8, protection de navigation,
rechargement, caractères spéciaux, compteur, limites, exclusivité/bornes/erreurs
audio, raccourcis et thèmes. Aucune exception JavaScript dans les recettes finales.

Pendant la mise au point du banc navigateur, l’activation Entrée échouait parce
que le pilote envoyait seulement `rawKeyDown`/`keyUp`, sans événement de caractère.
L’événement complet a validé le bouton natif, sans changement applicatif pour ce
diagnostic. L’import du module de test a aussi été placé dans une fonction async
pour Runtime.evaluate. Ces échecs du banc ne sont pas présentés comme des tests
réussis ; les recettes ont été relancées sur de nouvelles bases isolées.

Les captures `var/dictation-lot4-*.png`, profils et téléchargements restent sous
`var/`, ignorés par Git. Les serveurs arrêtés confirment le nettoyage de leur base
et de leur stockage. Chrome a été fermé par CDP ; la vérification finale des ports
8815/8816 en lecture seule confirme l’absence d’écouteur. Aucun commit, push,
déploiement ni migration de développement
ou production. Les trois ZIP de revue restent non suivis.

## Fichiers du lot

- Modifiés : `src/Controller/Course/DictationController.php`,
  `src/Services/Dictation/DictationAttemptService.php`,
  `assets/controllers/dictation_writing_controller.js`, `assets/styles/dictation.css`,
  `templates/dictation/write.html.twig`,
  `tests/Controller/Course/DictationControllerTest.php`.
- Ajoutés : `assets/js/dictation_feedback.js`,
  `templates/dictation/_summary.html.twig`, `templates/dictation/closed.html.twig`,
  `tests/manual/serve_dictation_lot4_browser.php`,
  `tests/manual/dictation_lot4_browser_check.cjs`, et ce contrat.

## Recette manuelle et limites

Utiliser le serveur isolé ci-dessus pour ne pas consommer la première évaluation
d’une tentative locale réelle. Démarrer une nouvelle session de ce serveur pour
la recette manuelle, sans lancer les scripts automatiques qui consomment leurs
propres tentatives. Le mobile physique reste **non testé** ; les
390 px sont un viewport Chrome automatisé. Les essais CDP ne remplacent ni une
écoute humaine ni un essai de lecteur d’écran ou de clavier physique pour le
nouveau parcours. La validation physique du lot 3 reste celle rapportée par
l’utilisateur et n’est pas étendue artificiellement à ce lot.

1. Ouvrir `/dictees/1` sur le serveur isolé avec son compte de fixture. Saisir une
   copie avec erreurs puis cliquer « Voir mes erreurs », immédiatement après une
   frappe. Vérifier texte, bilan, repères et conservation après rechargement.
2. Activer un repère au clavier, modifier la longueur du texte avant une autre
   erreur, réactiver un repère : le passage encore identifiable est sélectionné
   et visible. Un passage ambigu ou remanié laisse la sélection intacte avec une
   aide accessible. Vérifier sauvegarde, caractères spéciaux et commandes audio.
3. Cliquer « Terminer ». Vérifier la copie clôturée, le bilan sans signalements,
   l’absence d’édition après rechargement et le retour à la section.
4. Sur une autre dictée synthétique encore neuve, saisir `L’école ouvre.` :
   clôture directe. Sur une troisième, saisir `L’école ouvre` : autocorrection
   malgré 100 %. Essayer deux onglets et récupérer le texte local après conflit.
5. Compléter volontairement par un essai mobile physique et clavier/lecteur
   d’écran. Arrêter ensuite le serveur isolé avec son fichier témoin.

## Correctif des noms accessibles et nouvelle recette — 17 octobre 2026

Le nom accessible des boutons d’intervalle non vide reprend maintenant le
passage réellement affiché avant les descriptions. Exemple :
`« chatt ». Signalement 1 : orthographe à revoir. Rejoindre l’autocorrection.`
La valeur provient exclusivement de la portion de copie déjà rendue ; aucune
réponse attendue n’est ajoutée. Les omissions gardent leur description de point
d’insertion, sans passage inventé. Les descriptions de ponctuation et de tous
les signalements superposés restent présentes. Le texte rendu, les offsets, les
infobulles et le callback de sélection ne changent pas.

Trois fichiers concernés par cet ajustement : `assets/js/dictation_feedback.js`,
le nouveau test `tests/manual/dictation_feedback_accessibility_check.cjs` et ce
contrat. Le test importe uniquement le module dans une page Chrome `about:blank`,
sans URL applicative, sans base et sans tentative. Avant correction, il reproduit
l’absence de « chatt » dans le nom accessible. Après correction, les cinq cas
passent : passage lexical, ponctuation écrite, omissions au même emplacement,
superposition après un caractère hors BMP et texte contenant des balises HTML.
Il vérifie les attributs DOM et les **noms calculés dans l’arbre d’accessibilité
de Chrome**, ainsi que l’intégrité du texte et les offsets transmis à l’activation.
Cela ne constitue **pas un essai avec lecteur d’écran**. Le mobile physique reste
non testé.

Commandes réellement exécutées pour cet ajustement, avec `$node` défini plus haut :

```powershell
# Exécuté avant puis après le correctif, dans un Chrome de test sur le port 8816 :
& $node tests/manual/dictation_feedback_accessibility_check.cjs
& $node --check assets/js/dictation_feedback.js
& $node --check tests/manual/dictation_feedback_accessibility_check.cjs
git diff --check
git -c core.whitespace=blank-at-eol,blank-at-eof,space-before-tab,cr-at-eol diff --no-index --check -- /dev/null assets/js/dictation_feedback.js
git -c core.whitespace=blank-at-eol,blank-at-eof,space-before-tab,cr-at-eol diff --no-index --check -- /dev/null tests/manual/dictation_feedback_accessibility_check.cjs
git -c core.whitespace=blank-at-eol,blank-at-eof,space-before-tab,cr-at-eol diff --no-index --check -- /dev/null tests/manual/dictation_lot4_contract.md
```

Résultat final : cinq cas DOM/arbre d’accessibilité réussis, syntaxe JavaScript
valide, aucun diagnostic d’espacement. La suite PHP et les recettes complètes
ne sont pas relancées pour cette modification de nom accessible. Le Chrome
de contrôle, avec profil séparé sous `var/`, a été fermé avant la session manuelle.

### Session manuelle laissée disponible

Contrôle préalable en lecture seule : aucun écouteur sur 8815/8816, aucun ancien
processus PHP de recette et aucun témoin d’arrêt des lots 3/4. Puis lancement de
`php -d xdebug.mode=off tests/manual/serve_dictation_lot4_browser.php` : nouvelle
base MySQL aléatoire isolée et nouveau stockage temporaire, conformément aux
gardes du harnais. Le serveur écoute sur `127.0.0.1:8815`. Le processus serveur a
démarré le **17 octobre 2026 à 15:52:22, heure de Paris (UTC+02)** ; expiration
automatique après 30 minutes, **vers 16:22:22**. Terminer la recette avant
**16:22**. Le serveur est volontairement laissé actif pour l’utilisateur.

Seuls trois GET de vérification ont été effectués : les trois URL ci-dessous
répondent HTTP 200 et présentent le formulaire initial « Commencer ou retrouver
ma copie ». **Aucun POST, aucune tentative créée ou consommée par ces contrôles**.
Les scripts `dictation_lot3_browser_check.cjs` et `dictation_lot4_browser_check.cjs`
n’ont pas été lancés sur cette session. Le compte synthétique est connecté par
le routeur de test ; les MP3 sont des sons de fixture, pas des enregistrements
parlés de la phrase.

Texte de référence synthétique commun : **`L’école ouvre.`**, trois unités de
référence (`L’`, `école`, `ouvre`). Ne pas saisir les guillemets de présentation.

| Parcours | URL exacte | Copie à saisir | Résultat attendu |
| --- | --- | --- | --- |
| Erreur puis autocorrection | http://127.0.0.1:8815/dictees/1 | `L’ecole ouvre.` | 66,7 %, une erreur d’orthographe, zéro zone de ponctuation ; autocorrection. Le bouton sur `ecole` reprend ce passage dans son nom accessible. Remplacer par `L’école ouvre.` puis Terminer : clôture à 100 %, zéro erreur. |
| Copie parfaite | http://127.0.0.1:8815/dictees/2 | `L’école ouvre.` | 100 %, aucune erreur, clôture directe positive, sans autocorrection imposée. |
| Ponctuation seule | http://127.0.0.1:8815/dictees/3 | `L’école ouvre` | 100 %, zéro erreur d’orthographe, une zone de ponctuation ; autocorrection avec repère au point d’insertion final. Ajouter le point puis Terminer : clôture à 100 %, aucune zone restante. |

Pour arrêter plus tôt et déclencher le nettoyage automatique de **cette base et
de ce stockage de test uniquement**, exécuter depuis PowerShell :

```powershell
New-Item -ItemType File -Path 'C:\Formations\orthogram\var\dictation-4-browser.stop'
```

Le harnais affiche ensuite `CLEANED isolated MySQL database and test storage.`
et retire son témoin. Les données de cette session sont jetables. La base de
développement, ses tentatives, les médias réels, réglages locaux et archives
restent intacts. Aucun commit, push, déploiement ou travail du lot suivant.

## Correctif UX : navigation et ponctuation — 17 octobre 2026

### Recette manuelle rapportée par l’utilisateur

La recette avec le texte de Caroline a confirmé la première évaluation à
**90,5 %, 12 erreurs d’orthographe et 2 zones de ponctuation**. Après remplacement
de `consciense` par `conscience`, sauvegarde et rechargement, l’autocorrection est
conservée ; la première copie et son bilan restent inchangés. Après remplacement
de `améliorerer` par `améliorerez`, puis « Terminer », le résultat est
**92,1 %, 10 erreurs d’orthographe et 2 zones de ponctuation**. La copie clôturée
reste consultable sans édition ni nouveau signalement. Il s’agit de la recette
manuelle déclarée par l’utilisateur, pas d’un essai mobile physique ou avec
lecteur d’écran.

À la fin de la nouvelle recette isolée, l’utilisateur confirme également la
navigation vers un passage après modification et rechargement, ainsi que les
pointillés et l’aide « Ponctuation à revoir » visibles en clair et en sombre.
Le parcours réel **90,5 % → 92,1 % après deux corrections** et ces deux correctifs
UX sont donc validés manuellement. Une capture atteste uniquement du rendu
visible : elle ne constitue pas une preuve de navigation au clavier. Les essais
clavier automatisés décrits plus bas restent distincts de cette observation.
**Le mobile physique et le lecteur d’écran restent non testés.**

### Défauts reproduits avant modification

La recette navigateur dédiée utilise les deux textes privés déjà préparés sous
`var/dictation-caroline-lot4/`, sans relire la base de développement. Le serveur
de test accepte désormais en argument le JSON de référence et crée une huitième
dictée indépendante, sans variante ni explication, dans sa nouvelle base isolée.
Les sept fixtures synthétiques précédentes restent disponibles. Le fichier
privé et son rapport ne sont ni modifiés ni ajoutés au dépôt.

Avant correction, `consciense` est sélectionné correctement. Après sa correction,
sauvegarde et rechargement, l’activation de `améliorerer` laisse une sélection
vide `[0,0)` : le contrôleur exigeait l’égalité intégrale entre les deux copies.
Le point après « cette idée. » porte bien la classe de ponctuation et le style
calculé `dotted`. Sa largeur de **4,375 px** rend les pointillés indiscernables :
ce n’est ni une mauvaise classe ni une surcharge de la cascade. Les captures
avant correction ont été examinées.

### Comportement et limites du repérage

Le nouveau module de navigation utilise exclusivement la copie initiale et la
copie courante de l’apprenant. Une distance d’édition par caractères Unicode
(insertion, suppression, substitution de coût 1) tient compte du contexte de
toute la copie. Un passage est sélectionné seulement si ses caractères restent
inchangés, contigus et associés à la même occurrence dans **tous** les chemins
de coût minimal. Les mots répétés ne sont donc pas retrouvés par une recherche
de leur première occurrence. Un mot allongé à sa frontière n’est pas sélectionné
comme si son ancienne sous-chaîne constituait encore le même mot.

Les offsets retournés restent UTF-16. Les paires de substituts ne sont pas
découpées ; CRLF/CR et LF peuvent correspondre malgré la normalisation native du
textarea. Les accents décomposés sont conservés sans normalisation. Un point
d’insertion exige un emplacement unique et des voisins inchangés contigus.
Une ponctuation écrite suit les mêmes règles qu’un passage non vide.

Le calcul est borné à **20 000 unités UTF-16** par copie, avec une bande de
distance au plus 256, rétrécie pour limiter chaque matrice à **2 000 000 cellules**.
Les deux matrices Uint16 occupent au plus 8 Mo au total, plus les tableaux
linéaires. Le coût est borné par la taille de cette bande. Si le coût optimal
dépasse la bande, le repérage est refusé ; aucun chemin concurrent de coût
admissible n’est écarté. Le résultat est mis en cache tant que la copie courante
ne change pas et se reconstruit après rechargement, sans historique des frappes.
Le cas proche de la limite (19 997 → 19 999 unités, ajout initial) a pris
**113,1 ms** dans Node sur cette machine ; cette mesure n’est pas une garantie
de délai sur tous les appareils.

Une suppression, une réécriture importante, une occurrence ambiguë ou un budget
dépassé déclenche le repli prudent : focus à l’éditeur, sélection et direction
préservées, message accessible invitant à retrouver le passage. Le repérage
est une convention déterministe de correspondance textuelle, pas une preuve de
l’intention de l’apprenant ni une validation pédagogique. Il ne marque jamais
une erreur comme corrigée. Quand la correspondance est fiable, une mesure dans
un miroir DOM inerte permet de rendre la sélection visible dans le textarea.
Aucun clic de navigation ne modifie la copie ni n’émet de requête serveur.

### Ponctuation et accessibilité

La ponctuation possède maintenant une bordure inférieure pointillée de 3 px
et une largeur minimale de 1,5 em : le point isolé mesure **24 px** dans la recette
et présente plusieurs points visibles. L’orthographe garde un soulignement
continu ; une superposition conserve les deux signes. Les omissions restent
des repères distincts, avec pointillés lorsqu’elles concernent la ponctuation.
Ces dimensions modifient seulement la présentation, jamais les caractères,
les offsets ni le nombre de signalements.

Une aide « Ponctuation à revoir » apparaît au survol et au focus, positionnée
dans la largeur du panneau pour éviter sa coupure aux bords. Elle est générée
en CSS dans un élément masqué à l’arbre d’accessibilité : le nom du bouton
contient déjà cette information, le passage saisi et les signalements concernés.
Le texte original du conteneur reste exact. Les captures du point, des omissions
et des superpositions ont été examinées en clair/sombre, à 1 440 et 390 px.

### Fichiers concernés par ce correctif

- `assets/js/dictation_navigation.js` : correspondance bornée et sélection visible.
- `assets/controllers/dictation_writing_controller.js` : activation des repères.
- `assets/js/dictation_feedback.js` et `assets/styles/dictation.css` : ponctuation,
  superpositions et aide au survol/focus.
- `templates/dictation/write.html.twig` : message de navigation accessible.
- `tests/manual/dictation_navigation_check.cjs` : tests purs et oracle exhaustif.
- `tests/manual/dictation_lot4_navigation_browser_check.cjs` : recette UX dédiée.
- `tests/manual/dictation_lot4_browser_check.cjs` : attente de navigation adaptée.
- `tests/manual/serve_dictation_lot4_browser.php` : huitième fixture optionnelle.
- Le présent contrat.

Le moteur, les scores, droits, transitions et données applicatives ne changent
pas dans ce correctif. Aucun ajout de dépendance ou migration. Les travaux
préexistants du lot 4 et les archives restent conservés et non indexés.

### Vérifications du correctif

Commandes exécutées, avec `$node` défini plus haut et Chrome CDP sur 8816 :

```powershell
php -d xdebug.mode=off tests/manual/serve_dictation_lot4_browser.php var/dictation-caroline-lot4/input.json
& $node tests/manual/dictation_lot4_navigation_browser_check.cjs var/dictation-caroline-lot4/input.json --observe-before
& $node tests/manual/dictation_navigation_check.cjs var/dictation-caroline-lot4/input.json
& $node tests/manual/dictation_lot4_navigation_browser_check.cjs var/dictation-caroline-lot4/input.json
& $node tests/manual/dictation_feedback_accessibility_check.cjs
& $node tests/manual/dictation_lot4_browser_check.cjs
& $node tests/manual/dictation_lot3_browser_check.cjs
vendor\bin\phpunit --do-not-cache-result tests/Services/Dictation tests/Services/Courses/SectionCompletionServiceTest.php tests/Integration/DictationPersistenceTest.php tests/Integration/DictationEditorialMediaPersistenceTest.php tests/Controller/Admin/DictationAdminTest.php tests/Controller/Course/DictationControllerTest.php
vendor\bin\phpstan analyse --configuration=tests/Services/Dictation/phpstan.dist.neon --no-progress --error-format=table tests/manual/serve_dictation_lot4_browser.php
vendor\bin\php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no tests/manual/serve_dictation_lot4_browser.php
php bin/console lint:twig templates/dictation --env=test
& $node --check assets/controllers/dictation_writing_controller.js
& $node --check assets/js/dictation_feedback.js
& $node --check assets/js/dictation_navigation.js
& $node --check tests/manual/dictation_navigation_check.cjs
& $node --check tests/manual/dictation_lot4_navigation_browser_check.cjs
& $node --check tests/manual/dictation_lot4_browser_check.cjs
git diff --check
```

Résultats : **350 tests PHP, 2 143 assertions**, succès en 44,279 s (78 Mio).
PHPStan sans erreur, PHP CS Fixer sans correction, quatre templates Twig valides.
Les tests purs couvrent insertions/suppressions/remplacements successifs,
répétitions contextualisées et ambiguës, passages modifiés/supprimés, omissions,
ponctuation, hors BMP, accent décomposé, CRLF et refus au-delà des budgets.
Un oracle indépendant énumère tous les alignements optimaux de **961 couples**
de petites chaînes, soit **3 999 vérifications** de caractères et de points.

La recette dédiée confirme la reproduction exacte corrigée après sauvegarde et
rechargement, les décalages successifs, la bonne occurrence répétée, les replis
avec sélection préservée, le défilement du textarea, la première copie et son
bilan figés, et **zéro requête pendant la navigation**. Le texte n’est jamais
modifié par l’activation. Les cinq contrôles DOM/noms calculés dans l’arbre
d’accessibilité Chrome passent. Les recettes complètes des lots 3 et 4 passent,
y compris SAVE sans doublon, 422, 403/409, conflits entre onglets, rejeux exacts
après réponse perdue, clôture, audio et raccourcis.

Lors du dernier rejeu, l’ancien contrôle comparant tout le HTML du panneau a
détecté le style de positionnement ajouté à l’aide au focus. Le test compare
désormais un clone dont seuls ces styles temporaires sont retirés ; tous les
textes, descriptions et attributs des signalements restent contrôlés. Cette
adaptation du test ne retire aucune vérification de la copie ou des offsets.

Les captures restent sous `var/dictation-lot4-ux-*.png`, hors Git. Les tests
utilisent uniquement des bases et stockages jetables ; ils ne touchent pas aux
sessions manuelles préexistantes. Le viewport étroit est simulé dans Chrome
headless sous Windows : **aucun mobile physique ni lecteur d’écran testé**.

Syntaxe des six fichiers JavaScript contrôlée, `git diff --check` et contrôle
`--no-index --check` des nouveaux fichiers sans diagnostic. Les empreintes SHA-256
des fichiers privés `reference.txt` et `copie-fautive.txt` restent identiques.
Au dernier nettoyage, le processus PHP enfant du serveur de test est resté actif
malgré le témoin d’arrêt. Après vérification de sa commande et de sa filiation,
son arrêt ciblé a permis au harnais de confirmer
`CLEANED isolated MySQL database and test storage.` Chrome de test a été fermé
par CDP ; le contrôle final des ports 8815/8816 confirme leur libération.
Aucune ancienne session manuelle n’a été arrêtée par ces opérations.

### Courte recette manuelle du correctif

1. Démarrer une nouvelle session du serveur isolé avec l’argument privé ci-dessus,
   après vérification que 8815 est libre. Ne pas lancer les scripts automatisés
   sur cette session. Ouvrir `http://127.0.0.1:8815/dictees/8`, commencer et saisir
   la copie fautive du fichier privé, puis demander le premier signalement.
2. Corriger `consciense`, attendre « Enregistré », recharger, activer `améliorerer`
   dans la première copie : le mot doit être sélectionné et visible. Ajouter ou
   supprimer du texte avant lui puis réessayer. La première copie et le bilan
   doivent rester inchangés. Sur un passage remanié ou ambigu, vérifier le message
   et la conservation de la sélection courante.
3. Examiner le point après « cette idée. », au survol et au clavier, en clair et
   sombre sur grand et petit viewport : plusieurs points visibles et aide
   « Ponctuation à revoir ». Examiner également les repères d’omission.
4. Pour retrouver le résultat manuel de 92,1 %, conserver uniquement les deux
   corrections indiquées plus haut, attendre l’enregistrement, puis « Terminer ».
   Vérifier la copie clôturée en lecture seule. Arrêter cette session avec
   `New-Item -ItemType File -Path var/dictation-4-browser.stop` ; le serveur doit
   confirmer le nettoyage de sa base et de son stockage isolés.

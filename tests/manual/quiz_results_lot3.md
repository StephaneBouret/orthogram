# Mon évolution et mes tentatives — lot 3

## Parcours et contrat

Chaque carte terminée propose « Voir mon évolution et mes tentatives → » avant
les actions et l'anneau. GET `/mes-resultats/historique/{anchor}`
(`app_quiz_results_history`) utilise une tentative terminée du compte connecté
comme ancrage du couple cours + quiz. Une association manquante reste une
archive indépendante. Le paramètre GET optionnel `attempt` sélectionne une
terminée de ce même groupe ; absent, il sélectionne la dernière terminée.
Identifiant absent, étranger, mal formé, hors groupe ou inachevé : 404, sans
repli silencieux. Aucun utilisateur ne peut être choisi par l'URL.

`QuizResultsService::history()` réutilise les calculs du lot 1 et expose le
groupe, `anchorId` et `selected`. Les requêtes chargent l'ancrage puis son
groupe, sans requête par tentative. Les GET n'écrivent rien. Les réponses sont
privées sans stockage et le cache Turbo est désactivé. Les droits actuels sur
le cours contrôlent les liens du lecteur et de correction ; les propres
chiffres restent lisibles après expiration de l'accès.

La courbe suit `completedAt`, puis `id`, en ordre croissant. Ses catégories
internes uniques représentent les tentatives ; seules les dates `jj/mm` sont
affichées sur l'axe, sans échelle de temps proportionnelle. Axe 0–100 %, segments droits, animation désactivée, date/heure
et score dans l'infobulle. Chaque changement de contenu ouvre un nouveau jeu
de points sans liaison avec le précédent, même si un ancien contenu revient.
Le gain soustrait les pourcentages affichés de la première et de la dernière
terminées seulement si toutes sont comparables et valides. Le tableau inversé
conserve les deltas calculés dans l'ordre chronologique par le service.

« Voir » est un lien GET utilisable sans JavaScript : ligne sélectionnée,
bilan, anneau et correction suivent l'URL, y compris après rechargement.
La correction propose un retour interne vers son propre historique et sa
sélection, sans paramètre de redirection libre. Aucun snapshot ni réponse
détaillée n'est envoyé avec l'historique. Aucun changement du lecteur,
de sa finalisation ou de son idempotence ; aucune nouvelle dépendance.

## Fichiers concernés

- `src/Services/QuizResultsService.php` : lecture du groupe et sélection,
  gain comparable, URL d'historique et retour de correction.
- `src/Controller/QuizResultsController.php` : route GET, validation des
  identifiants, courbe segmentée et constructeur d'anneau partagé.
- `templates/quiz_results/history.html.twig`, `index.html.twig`, `_ring.html.twig`,
  `_card.html.twig`, `correction.html.twig` et `templates/shared/_navbar.html.twig` :
  nouvelle page, anneau partagé et liens du parcours.
- `assets/controllers/result_chart_controller.js`, `assets/styles/quiz_results.css` :
  thèmes, infobulles et présentation adaptative ; UX conserve le cycle de vie
  des graphiques. Sur mobile, le tableau défile horizontalement avec une
  indication visible, sans supprimer de colonne ni d'ancienne tentative.
- `tests/Controller/QuizResultsTest.php` : sélection, sécurité, chronologie,
  comparabilité, archives et absence d'écriture ; scripts navigateur ci-dessous.

## Recette courte des trois lots

1. Se connecter et ouvrir « Mes résultats » dans la navbar. Vérifier les états
   non passé/en cours, les scores 0/50/100 %, les légendes et le maintien du
   dernier score lorsqu'une nouvelle tentative est active.
2. Ouvrir l'historique du test à cinq résultats 40, 60, 50, 70 et 90 %.
   Vérifier les cinq points, les deltas chronologiques —, +20, −10, +20, +20,
   le gain +50 et le tableau du plus récent au plus ancien. Les passations
   du même jour restent distinctes par numéro et heure.
3. Au clavier, sélectionner une ancienne tentative : ligne identifiée,
   bilan et anneau cohérents. Ouvrir sa correction figée, utiliser le retour
   explicite puis recharger : la sélection et son lien de correction restent
   exacts. Répéter les allers-retours Turbo.
4. Vérifier séparément un contenu modifié : rupture de liaison, mention dans
   le tableau, aucun gain global. Vérifier une première tentative à 0 % :
   un seul point et aucun gain fictif.
5. Changer de thème et tester sur mobile (320/390 px) : anneau réorganisé,
   tableau défilable, aucune largeur de page excessive. Désactiver JavaScript
   et refaire sélection/correction : tous les chiffres et liens restent lisibles.
6. Sans droits actuels sur le cours, les chiffres restent disponibles mais
   les actions interdites disparaissent. Avec un autre compte, modifier
   l'ancrage ou la sélection doit produire une 404. Les GET ne créent aucune
   tentative et ne modifient ni leçons ni progression.

### Données isolées et navigateur automatisé

Dans un terminal depuis la racine du dépôt :

```powershell
php -S 127.0.0.1:8803 -t public tests/manual/quiz_history_browser_router.php
```

Ce routeur est limité au serveur PHP local et vérifie le chemin de sa seule
base `var/quiz-history-browser.sqlite` avant création du schéma. Il utilise les
fixtures existantes et conserve cette base ainsi que les cookies entre les
exécutions. Il ne peuple ni ne purge la base configurée dans `.env`. Les trois
tests de recette sont le groupe comparable avec tentative active, le groupe
avec contenu modifié et une première tentative à zéro.

Lancer un Chrome dédié sans fenêtre puis le contrôle CDP natif (Node 22 ou
compatible ; adapter son chemin si nécessaire) :

```powershell
Start-Process 'C:\Program Files\Google\Chrome\Application\chrome.exe' -WindowStyle Hidden -ArgumentList '--headless=new','--disable-gpu','--no-first-run','--remote-debugging-port=8804','--user-data-dir=C:\Formations\orthogram\var\results-ui-chrome','about:blank'
node tests/manual/quiz_history_browser_check.cjs
```

Arrêter ces deux processus de test après la recette. Pour vérifier aussi les
anneaux du lot 2, utiliser ensuite son routeur et son script, décrits dans
[quiz_results_lot2.md](quiz_results_lot2.md), sur les mêmes ports libérés.

## Contrôles exécutés le 18 septembre 2026

- PHPUnit ciblé résultats + lecteur : **57 tests, 582 assertions**, OK.
- PHPUnit complet : **383 tests, 29 582 assertions**, OK. Base configurée
  identifiée comme `orthogram_test` ; tests quiz sur SQLite isolée.
- Route, Twig (six templates résultats), conteneur, syntaxe PHP/JS,
  PHP CS Fixer des quatre fichiers PHP concernés et `git diff --check` : OK.
- AssetMapper : **107 assets compilés** ; seul le dossier généré pour ce
  contrôle a été retiré ensuite pour conserver les assets dynamiques en dev.
- Chrome avec AssetMapper, Stimulus et Turbo réels : parcours complet,
  sélection au clavier, retour/rechargement, navigations répétées sans
  doublons, véritable infobulle, thèmes, mobile 320/390 px, contenu modifié,
  singleton zéro et sélection sans JavaScript : OK, aucune erreur console.
  Captures : `var/history-desktop-light.png`, `var/history-desktop-dark.png`,
  `var/history-mobile-dark.png`.
- Recette navigateur du lot 2 relancée : anneaux 0/50/100 %, thèmes, largeurs
  320/390/768/1024/1366 px et Turbo : OK.
- PHPStan : **17 erreurs préexistantes dans les tests de calendrier**,
  aucune dans les fichiers de ce lot. L'import global Google Maps depuis
  unpkg signalé au lot 2 reste inchangé ; les graphiques sont servis localement.

Pas de pagination ni test de charge : toute l'histoire du groupe personnel
est affichée. Les limites de titres de cours/section non figés et de
comparaison conservatrice restent celles du lot 1. Aucun commit, push ou
déploiement effectué pour ce lot.

## Retouches visuelles avant commit

- Sections du catalogue ouvertes par défaut avec `details/summary`, titre H2,
  chevron et compteur de **cartes** (singulier/pluriel), ligne entière activable
  au clavier. Les archives gardent leur présentation. Orange `#f3971b`, couleur
  dominante du O dans `assets/img/logo.png`, dans la variable locale
  `--results-section-title` : la variable globale `--orthogram-brand` utilise
  une autre teinte (`#f59e0b`) et reste inchangée.
- Mentions « par rapport à la précédente » entièrement colorées selon le signe,
  flèche décorative masquée aux lecteurs d'écran, aucun fond. Couleurs adaptées
  aux thèmes ; même traitement du gain « depuis la première tentative ».
- Pourcentages dessinés par un plugin local du contrôleur Chart.js existant,
  sans dépendance. Marges pour 0/100 %, halo discret et détection de collision.
  Si nécessaire, seules certaines **étiquettes** sont omises ; sélection et
  extrémités sont prioritaires, tous les points et toutes les lignes restent.
  L'identité des catégories est séparée du format des graduations : plusieurs
  dates identiques conservent des positions distinctes et leur infobulle complète.

Pour la recette élargie, définir `$env:QUIZ_HISTORY_RETOUCHES='1'` dans les deux
terminaux avant de lancer le routeur et le script CDP ci-dessus. Cette variante
crée uniquement `var/quiz-history-retouches.sqlite` et ses propres cookies,
sans effacer la recette précédente. Elle contient deux tests dans « Les
fondamentaux », une seconde section indépendante et les cas stable, baisse,
première tentative à zéro, 100 % et 40 tentatives le même jour.

Contrôles des retouches réellement effectués :

- `QuizResultsTest` : 31 tests, 341 assertions ; `QuizPlayerTest` : 26 tests,
  243 assertions. Twig, conteneur, syntaxe PHP/JS, PHP CS Fixer et diff : OK.
- Compilation AssetMapper : 107 assets, dossier généré retiré après contrôle.
- Chrome : thèmes clair/sombre, ordinateur 1366 px, mobile 390/320 px,
  clavier et focus visible, repli indépendant sans JS, réouverture des anneaux
  à 96 px, navigation Turbo sans doublon. Vérification des textes et d'un
  contraste supérieur à 4,5:1 pour les mentions hausse/baisse/stable.
- Vérification des textes effectivement dessinés sur canvas, des dates seules,
  des cinq positions distinctes le même jour, du maintien des ruptures et de
  la sélection ; infobulle, 0/100 % sans découpe, 40 points/lignes conservés
  avec étiquettes espacées dans les deux thèmes. Aucune erreur console.
- Captures actualisées dans `var/` : `history-results-1366-light.png` et
  `history-results-1366-dark.png` (cartes), `history-results-390-light.png` et
  `history-results-390-dark.png` (cartes mobiles), `history-desktop-light.png`,
  `history-desktop-dark.png`, `history-mobile-light.png`,
  `history-mobile-dark.png` (évolution), `history-dense-mobile-light.png` et
  `history-dense-mobile-dark.png` (historique dense).

Pour vérifier manuellement : replier la première section au clavier, constater
que la seconde reste ouverte, rouvrir puis suivre une carte vers son historique.
Changer le thème, sélectionner une ancienne tentative, revenir et rouvrir la
section. Refaire sans JavaScript et sur mobile. Les règles de calcul et de
comparabilité restent celles du service du lot 1.

### Vérification du tableau mobile en thème clair

Le blanc de l'ancienne `history-mobile-light.png` est un artefact intermittent
de capture pleine page : le tableau défilable était hors de la zone visible.
À code applicatif inchangé, le tableau est visible après défilement réel et
la capture pleine page régénérée le montre également. Aucun correctif CSS,
Twig ou JavaScript applicatif n'a été apporté pour ce signalement.

Le script CDP amène maintenant le tableau dans la zone visible avant la
capture mobile pleine page et produit aussi des captures de cette seule zone
(`captureBeyondViewport: false`). À **390 puis 320 px**, vérifications réalisées
après rechargement en clair puis changement clair → sombre → clair : en-têtes,
cinq lignes, défilement horizontal par événements d'entrée du navigateur,
cinq liens « Voir » visibles et atteignables, clic effectif et bonne sélection.
Les captures ont été examinées visuellement. Recette navigateur complète,
syntaxe du script et `git diff --check` : OK ; aucune erreur console.

Captures de référence dans `var/`, pour chaque largeur `390` et `320` :
`history-table-{largeur}-reload-light-dates.png` (après rechargement),
`history-table-{largeur}-toggle-light-details.png` (colonnes Évolution/Détail
après retour au clair). Les étapes `toggle-dark` et les deux positions
horizontales sont aussi conservées. Ces captures de la zone visible servent
à contrôler le tableau ; la vue pleine page donne seulement une vue d'ensemble.

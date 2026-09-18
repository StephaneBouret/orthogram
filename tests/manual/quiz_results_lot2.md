# Mes résultats — lot 2

## Livraison

GET `/mes-resultats`, route `app_quiz_results`, protégée par `ROLE_USER`.
Les données viennent exclusivement de `QuizResultsService::results()` pour le
compte connecté. Réponse `private, no-store` et cache Turbo désactivé.

Les cartes reprennent le dernier résultat terminé, les indicateurs de
comparabilité, les droits et les liens de correction du lot 1. Le service
expose simplement `catalogOrder` pour trier par programme, section et cours.
Les tentatives archivées sont présentées à part. Aucun exercice n'est ajouté.

Les boutons « Commencer », « Continuer » et « Repasser » ouvrent le lecteur du
cours : l'action effective s'effectue ensuite dans ce lecteur. Ce parcours en
deux étapes n'ajoute aucune mutation au GET ni de démarrage automatique.
Le lien vers l'évolution reste réservé au lot 3.

## Fichiers concernés

- `src/Controller/QuizResultsController.php` : page et construction des charts.
- `src/Services/QuizResultsService.php` : clé de tri pédagogique, sans nouvelle
  règle métier de score ou de comparabilité.
- `templates/quiz_results/index.html.twig`, `_card.html.twig` : sections,
  archives, cartes, états, légendes HTML et liens autorisés.
- `templates/shared/_navbar.html.twig` : entrée après « Ma formation », active
  aussi sur la correction ; cette navbar couvre ordinateur et mobile.
- `assets/controllers/result_chart_controller.js` : couleurs CSS résolues,
  observation de `data-bs-theme`, nettoyage des écouteurs et de l'observateur.
- `assets/styles/quiz_results.css`, `assets/app.js`,
  `assets/stimulus_bootstrap.js` : styles adaptatifs et enregistrement.
- `composer.json`, `composer.lock`, `symfony.lock`, `config/bundles.php`,
  `assets/controllers.json`, `importmap.php` : dépendance et recette Flex.
- `tests/Controller/QuizResultsTest.php` : contrôles HTTP et données des charts.
- Les deux scripts de navigateur et cette recette ; contrat du lot 1 complété.

## Dépendances et cycle de vie

Installation ciblée de `symfony/ux-chartjs` **3.2.0**, contrainte `~3.2.0`.
Un seul paquet Composer ajouté ; aucune dépendance existante mise à jour.
Flex active `Symfony\UX\Chartjs\ChartjsBundle` et le contrôleur UX eager.
Importmap ajoute `chart.js` **4.5.1** et `@kurkle/color` **0.3.4**, téléchargés
localement par AssetMapper. Aucun pipeline npm, Encore ou Vite ajouté.

La [documentation officielle UX Chart.js](https://symfony.com/bundles/ux-chartjs/current/index.html)
et le contrôleur installé dans `vendor/symfony/ux-chartjs/assets/dist/controller.js`
ont été consultés. `ChartBuilderInterface` construit les doughnuts ; Twig
utilise `render_chart`. Le contrôleur de couleurs est connecté avant le
contrôleur UX sur le canvas et écoute `chartjs:pre-connect`, `chartjs:connect`
et `chartjs:disconnect`. UX garde seul la création et la destruction du chart.

Les valeurs sont exactement `[score, total - score]`, sans secteur fictif à
0/100 %. Un total nul n'affiche pas de graphique. Le score central est du HTML,
la légende reste lisible sans JavaScript, et le canvas a un nom accessible.
Animations, interactions et légende canvas sont désactivées sur ces anneaux.

## Contrôles exécutés le 18 septembre 2026

- PHPUnit ciblé : **25 tests, 244 assertions**, OK.
- PHPUnit complet : **377 tests, 29 487 assertions**, OK. Base configurée
  identifiée comme `orthogram_test` ; tests quiz sur SQLite temporaire isolée.
- Route, `lint:twig`, `lint:container`, syntaxe JS et PHP CS Fixer des fichiers
  concernés : OK.
- `importmap:install`, `debug:asset-map` et `asset-map:compile` : OK,
  **107 assets compilés**. Le dossier de compilation nouvellement créé a été
  retiré après contrôle pour laisser le serveur de développement dynamique.
- Chrome sans interface, avec le véritable AssetMapper/Stimulus/Turbo : anneaux
  0/50/100 %, thèmes clair et sombre, changement de thème, écrans 1366 et 390 px,
  absence de débordement aussi à 320/768/1024 px, boutons mobiles de 44 px
  minimum, navigation mobile et deux allers-retours Turbo sans double instance.
  Aucune erreur console. Captures produites sous `var/results-*.png`.

Points préexistants, hors de ce lot :

- PHPStan : 17 erreurs dans les tests de calendrier déjà signalés au lot 1 ;
  aucune dans les fichiers du lot 2.
- Composer valide les manifests, avec l'avertissement existant sur
  `symfony/apache-pack: *`. Son audit signale EasyAdmin
  `CVE-2026-81892` ; ce paquet n'a pas été mis à jour par cette installation.
- Le contrôleur global d'adresse importe déjà Google Maps depuis unpkg sur
  toutes les pages. Cet import reste inchangé ; **les graphiques de ce lot sont
  entièrement servis localement**. Le test réseau distingue explicitement cet
  import existant des ressources des graphiques.

## Recette manuelle courte

1. Se connecter, ouvrir « Mes résultats » après « Ma formation ». Vérifier
   l'ordre des sections et les états « Pas encore passé » / « En cours ».
2. Avec des résultats 0/50/100 %, comparer score, pourcentage et légende de
   l'anneau. Une reprise en cours conserve le dernier résultat terminé.
3. Ouvrir « Revoir ma correction » : le titre et le score doivent correspondre
   exactement à la carte. Revenir, changer de thème et répéter sur mobile.
4. Utiliser « Repasser le test » : le lecteur s'ouvre et propose l'action
   existante. Aucun test ne démarre à la simple ouverture du lien.
5. Avec un compte sans accès au cours, contrôler que ses anciens scores restent
   visibles mais que les liens interdits disparaissent. Sans tentative ni test
   accessible, vérifier l'état vide et son lien vers l'abonnement.

### Reproduire la recette navigateur automatisée

Depuis la racine du dépôt, dans un terminal dédié :

```powershell
php -S 127.0.0.1:8803 -t public tests/manual/quiz_results_browser_router.php
```

Ce routeur est réservé à `cli-server` et aux requêtes locales. Il crée uniquement
`var/quiz-results-browser.sqlite`, vérifie son chemin avant création du schéma,
utilise les fixtures existantes et connecte un compte de test. Il ne purge ni
ne modifie la base configurée dans `.env`. Le fichier et les cookies de test
sont conservés entre exécutions.

Lancer Chrome sans fenêtre avec un profil dédié et CDP sur 8804, par exemple :

```powershell
Start-Process 'C:\Program Files\Google\Chrome\Application\chrome.exe' -WindowStyle Hidden -ArgumentList '--headless=new','--disable-gpu','--no-first-run','--remote-debugging-port=8804','--user-data-dir=C:\Formations\orthogram\var\results-ui-chrome','about:blank'
node tests/manual/quiz_results_browser_check.cjs
```

Adapter le chemin de Node si absent du PATH. Le contrôle utilise CDP natif et
ne nécessite aucun framework supplémentaire. Arrêter le serveur et le Chrome
de test après la recette.

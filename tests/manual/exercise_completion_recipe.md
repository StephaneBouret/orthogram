# Validation automatique des exercices

## Règle et périmètre

Depuis le lecteur d’un cours Exercise, « Valider les réponses » enregistre la tentative et termine ce cours dès la première réussite complète : total strictement positif, score exactement égal au total, aucun item incorrect ou oublié. Un pourcentage arrondi à 100 ne suffit pas. Le barème et les réponses virtuelles noAnswer sont inchangés ; un exercice vide, une phrase sans mots exploitables ou des identifiants de mots ambigus ne permettent pas la complétion.

La soumission transmet le cours explicitement. Le serveur vérifie son type, son association à l’exercice et INTERACT_COURSE, y compris les prérequis des dictées. Sans contexte, la page autonome continue à enregistrer uniquement la tentative. Un exercice partagé ne valide que le cours soumis. Les anciennes tentatives ne sont pas rattrapées et les GET ne créent aucune progression.

Tentative et Lesson sont écrites dans une transaction avec le même verrou utilisateur que les quiz. Une lecture verrouillée et rafraîchie protège aussi la première insertion ; la contrainte unique utilisateur/cours reste en place. Plusieurs soumissions peuvent enregistrer plusieurs tentatives, mais une seule Lesson. Une complétion acquise n’est jamais retirée, y compris par la route manuelle désormais interdite aux exercices.

Après commit, la réponse fournit l’état terminé et les fragments de progression ordinateur/mobile. Le corrigé reste visible, sans redirection. Le dernier corrigé est restauré au rechargement ; le lecteur conserve sa politique Turbo no-cache et ignore les réponses de composants déconnectés. Le marque-page conserve son fonctionnement par POST séparé.

## Recette courte

1. Ouvrir un cours Exercise non terminé. Vérifier l’absence de bouton manuel « Valider » et la présence de « Valider les réponses ».
2. Faire une erreur : corrigé et score visibles, aucune nouvelle complétion. Repasser l’exercice puis réussir : message de progression enregistrée, état « Terminé » sans action, compteur et marqueur actualisés ; la section se replie si tous ses cours sont terminés.
3. Vérifier que le corrigé reste affiché. Repasser l’exercice et échouer : la complétion reste acquise. Recharger : dernier corrigé et état terminé cohérents.
4. Utiliser suivant, précédent, retour navigateur puis le sommaire et « Reprendre ma formation ». Vérifier que le lecteur et le marque-page restent cohérents.
5. Répéter à 390 px et sur ordinateur, en thèmes clair et sombre. Sur un cours encore incomplet, simuler une panne réseau : aucune confirmation de complétion sans réponse serveur réussie.
6. Corriger le même exercice via `/exercise/{id}` : aucune nouvelle progression. Un POST manuel `/course/confirmation/{id}` sur un cours Exercise doit retourner 403, même si ce cours est déjà terminé.

## Vérifications du 24 octobre 2026

Départ : dépôt propre sur preprod, commit `49390bf`, comme les références locales origin/main et origin/preprod. Aucun AGENTS.md trouvé. L’ancienne recette de barre latérale décrivait le comportement manuel remplacé ici, sans imposer de règle pédagogique contradictoire.

Les connexions résolues ont été contrôlées avant les tests : développement `orthogram`, tests `orthogram_test`, MySQL local ; `SELECT DATABASE()` confirmé. Les nouveaux tests HTTP contrôlent aussi la connexion SQLite effective avant création du schéma. Le test de concurrence utilise une base MySQL temporaire créée et supprimée par le mécanisme isolé existant, avec deux processus PHP indépendants. Aucun schéma ni aucune donnée de développement n’ont été modifiés.

- Tests ciblés : 133 tests, 1 796 assertions, succès (`var/exercise-targeted.log`).
- Concurrence et rollback MySQL : 1 test, 15 assertions, succès. Deux réussites concurrentes produisent une seule Lesson ; une erreur SQL forcée sur Lesson annule aussi la nouvelle tentative.
- Suite PHPUnit complète : **1 097 tests, 36 709 assertions, aucune erreur ni échec, un test ignoré**, code de sortie 0, en 5 min 19 s (`var/exercise-full.log`). Le seul test ignoré est `CourseFilesTest::testEscapingSymlinkIsRefused` : Windows refuse les liens symboliques à ce compte. Aucune exclusion ajoutée.
- Recette Chrome locale sur SQLite isolé : succès, échec, nouvel essai, rechargement, retour Turbo, précédent/suivant, progression ordinateur/mobile et erreurs réseau/HTTP 500. Quatre captures à 1440/390 px, clair/sombre, sans débordement horizontal. Le sommaire propose « Reprendre ma formation » vers le cours visité. Artefacts locaux : `var/exercise-browser-check.cjs`, `var/exercise-*-light.png`, `var/exercise-*-dark.png`.
- Recette complémentaire : réponse serveur retenue puis libérée après navigation aller/retour Turbo, sans écraser la nouvelle sélection ; ouverture du menu mobile ; clic « Reprendre ce cours » avec restauration du corrigé et de la complétion. Aucun incident JavaScript. Le serveur de recette et le navigateur isolé ont été arrêtés après vérification.
- Twig : 14 fichiers valides ; conteneur Symfony valide ; syntaxe JavaScript valide ; PHPStan sans erreur sur les sept fichiers PHP du chantier.
- Syntaxe PHP, PHP CS Fixer en dry-run sur les sept fichiers PHP et `git diff --check` : succès.
- PHPStan global : 34 erreurs dans des fichiers non modifiés (tests et outils existants), sans nouvelle exclusion (`var/exercise-phpstan-final.log`).
- Doctrine : mapping valide ; écart de nom d’index préexistant sur le marque-page, déjà décrit dans `test_database_recipe.md`. Aucun changement de schéma effectué.
- Composer valide avec deux avertissements préexistants sur les contraintes de versions de getid3 et apache-pack.

Aucune migration, aucun rattrapage, commit, push, déploiement ou accès Hostinger.

Message de commit proposé : `feat: valider automatiquement les cours exercices après réussite complète`

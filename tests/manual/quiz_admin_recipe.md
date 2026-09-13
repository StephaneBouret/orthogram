# Quiz — lot 1

Le modèle est `Quiz → QuizQuestion → QuizAnswer`, avec des collections ordonnées par
position puis identifiant. `Courses.quiz` est une association facultative many-to-one,
sans cascade de suppression vers le quiz. Les questions appartiennent à un seul quiz.
Les repositories génériques Doctrine suffisent pour ce lot.

Les propositions se modifient exclusivement dans le formulaire de leur question.
La validation Symfony contrôle la collection finale, indépendamment d’EasyAdmin :
consigne et propositions non vides, deux propositions minimum, positions renseignées
et non négatives, parents cohérents, exactement une bonne réponse en mode unique,
au moins une en mode multiple. Le thème est libre ; phrase et explication sont du
texte simple. Un quiz nommé peut rester vide pendant sa préparation.

Dans les formulaires, seule la position de la **question** se saisit numériquement.
Les propositions se déplacent avec **Monter** et **Descendre** ; leur position interne
est recalculée automatiquement. La liste affiche **Oui/Non** pour « Plusieurs réponses »,
sans interrupteur. Les formulaires conservent le choix « Une seule réponse / Plusieurs réponses ».

Le contrat du formulaire transmet `answers[clé_de_ligne][position]` dans un champ caché.
Pour les N lignes soumises, ces valeurs doivent être les chaînes décimales canoniques
de 0 à N−1, chacune une seule fois. Une position manquante, dupliquée, hors limites,
non numérique ou imbriquée provoque une erreur de formulaire. Les clés des lignes
restent celles du formulaire Symfony : ce ne sont pas des identifiants de base de
données. Aucun identifiant de proposition ou de question parente n’est accepté dans
une ligne. Le serveur contrôle cet ordre avant le mapping et la validation métier.
Il trie uniquement les vues de formulaire au réaffichage, sans vider/recréer les
collections Doctrine ni échanger les contenus entre propositions.

`QuizCorrectionService::correct()` retourne un booléen : une sélection bien formée
mais inexacte retourne `false`. Les sélections vides, non listes, contenant des valeurs
autres que des entiers positifs, des doublons ou des identifiants étrangers provoquent
une `InvalidArgumentException`. Plusieurs sélections sont refusées en mode unique.
Une configuration incohérente ou des propositions sans identifiants persistés
provoquent une `LogicException`. En mode multiple, seule l’égalité exacte des ensembles
donne `true`. Le futur parcours devra réutiliser cette règle.

## Migration initiale du lot 1, après revue

Le correctif d’administration n’ajoute aucune migration. Ne pas relancer cette
migration ni le script de vérification pour appliquer le seul correctif.

Relire `migrations/Version20260913110000.php` : trois tables et la seule association
`courses.quiz_id`. SQL généré depuis les métadonnées Doctrine, avec périmètre limité
aux nouvelles tables et à cette association ; retour arrière ordonné pour retirer
les clés étrangères avant les tables. Le retour arrière supprime les contenus des quiz.

Depuis la racine, pour la base locale configurée, prévisualiser puis appliquer cette
migration précise après revue :

```powershell
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20260913110000' --up --dry-run
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20260913110000' --up
```

La base de travail n’est pas migrée par les tests. Le script suivant vérifie les SQL
`up` et `down`, les cascades et la conservation d’un cours existant sur MySQL/MariaDB.
Il refuse un hôte non local, crée une base au nom aléatoire, puis supprime uniquement
cette base jetable. Les identifiants de connexion locaux doivent autoriser sa création.

```powershell
php tests/manual/verify_quiz_migration.php
```

## Recette EasyAdmin

1. Se connecter comme administrateur. Dans **Quiz**, créer « Révision des cinq thèmes ».
   L’enregistrement doit réussir sans question.
2. Dans **Questions des quiz**, créer une question rattachée à ce quiz. Renseigner la
   consigne, la phrase, l’explication, le thème et la position de la question. Choisir **Une seule
   réponse**, ajouter deux propositions et cocher une
   seule bonne réponse. Enregistrer puis rouvrir.
3. Ajouter une autre question en mode **Plusieurs réponses**, avec trois propositions
   dont deux bonnes. Vérifier la sauvegarde des cases et de l’ordre. Ce mode accepte
   aussi une seule bonne proposition.
4. Modifier une question unique : supprimer sa bonne proposition et en ajouter une
   nouvelle, correcte, dans le même formulaire. L’enregistrement doit réussir si
   l’état final conserve au moins deux propositions et une seule bonne réponse.
5. Cocher deux bonnes propositions en mode unique, retirer toutes les bonnes
   propositions en mode multiple, vider un contenu ou saisir une position de question négative.
   Chaque cas doit afficher une erreur et conserver en base le dernier état valide.
6. Dans **Cours**, créer une entrée de type **Quiz**, choisir le quiz et sa section.
   Placer cette entrée après l’exercice sur les adverbes et avant les prépositions
   au moment de l’intégration éditoriale. Le sélecteur de quiz apparaît uniquement
   pour ce type. Changer le type puis enregistrer : l’association est effacée mais
   le quiz reste dans son menu. Le comportement du sélecteur d’exercice reste identique.
7. Avec un compte ordinaire, essayer `/admin/quiz/new` et `/admin/quiz-question/new` :
   accès refusé. La page apprenant du cours conserve son message d’attente.

## Tests ciblés

Les tests HTTP utilisent SQLite en mémoire, les vrais formulaires EasyAdmin et la
sécurité Symfony. Ils ne nécessitent aucune migration de la base de travail.

```powershell
vendor/bin/phpunit tests/Entity/QuizQuestionTest.php tests/Services/QuizCorrectionServiceTest.php tests/Controller/Admin/QuizAdminTest.php tests/Entity/ExerciceTest.php tests/Form/ExerciceSentenceValidationTest.php tests/Services/ExerciceCorrectionServiceTest.php
```

## Recette navigateur du correctif

Utiliser une question réservée aux essais, sans modifier les contenus du quiz « Test ».

1. Ouvrir la liste avec au moins une question de chaque mode : vérifier les deux lignes,
   les valeurs **Non/Oui** et l’absence d’interrupteur dans la colonne du mode.
2. Créer une question avec trois propositions A, B et C, dont A correcte. Aucun champ
   numérique ne doit apparaître dans les propositions. Chaque ajout apparaît à la fin.
3. Modifier le texte de B, puis déplacer B par **Monter** et **Descendre**. Vérifier
   que texte et case cochée suivent leur proposition. À la première place, Monter est
   désactivé ; à la dernière, Descendre est désactivé.
4. Avec Tab puis Entrée ou Espace, actionner un bouton. Un seul déplacement doit se
   produire, sans soumission. Le focus reste sur la proposition déplacée ; lorsque
   le bouton devient désactivé en limite de liste, le focus passe à l’autre bouton.
5. Supprimer la proposition du milieu, puis en ajouter une. Vérifier son placement
   à la fin, déplacer à nouveau et enregistrer. La liste d’arrivée doit s’afficher
   sans erreur 500. Rouvrir : ordre, textes et cases doivent correspondre à la saisie.
6. Déplacer une proposition puis cocher une deuxième bonne réponse en mode unique.
   Enregistrer : l’erreur doit conserver ordre et saisies. Corriger puis enregistrer.
   Essayer aussi de retirer toutes les bonnes réponses en mode multiple.
7. Naviguer ailleurs puis revenir, y compris via Précédent/Suivant du navigateur.
   Vérifier que chaque proposition n’a qu’une paire de boutons et qu’un clic ne la
   déplace que d’une place. Répéter avec une collection initialement vide.

Le module est une entrée AssetMapper chargée seulement par le CRUD des questions.
Il utilise les événements `ea.collection.item-added` et `ea.collection.item-removed`
de la version EasyAdmin installée et gère les retours Turbo sans doubler les écouteurs.
Références : [CollectionField](https://symfony.com/bundles/EasyAdminBundle/current/fields/CollectionField.html)
et [BooleanField](https://symfony.com/bundles/EasyAdminBundle/current/fields/BooleanField.html).

Vérification du correctif le 13 septembre 2026 : Chrome sans fenêtre, avec les vrais
assets et formulaires EasyAdmin sur une instance locale à base SQLite jetable.
Création depuis une collection vide, ajouts, suppression puis ajout, déplacements
par Entrée/Espace, focus aux limites, conservation des textes/cases, enregistrement,
liste Oui/Non, réouverture et erreur métier ont été vérifiés. Les événements Turbo
ont été déclenchés pour contrôler l’absence de boutons et d’écouteurs dupliqués.
Le quiz « Test » de la base de travail n’a pas été utilisé.

## Raccordements à prévoir au lot 2

- Refuser le lancement d’un quiz vide ou incohérent, sans imposer dix questions au moteur.
- Créer une tentative appartenant au compte, avec état explicite en cours/terminé,
  liste et ordre figés des questions, total et données de correction historiques.
- Persister chaque validation, correcte ou fausse, puis reprendre à la première
  question non validée ; ne pas dépendre du navigateur ni du score pour reconnaître
  une tentative en cours. Prévoir la validation concurrente et la finalisation idempotente.
- Protéger les mutations par CSRF, propriété de la tentative et `CourseVoter` ;
  ne livrer la correction qu’après validation. Prévoir la récupération de l’état
  serveur après une erreur réseau et une nouvelle tentative explicite pour recommencer.
- Construire l’interface Stimulus et le bilan sans seuil bloquant. Avant d’autoriser
  des suppressions administratives avec des tentatives existantes, définir leur
  conservation historique et leurs clés étrangères : les cascades actuelles ne
  concernent que les contenus éditoriaux du quiz.
- Sélectionner et relire séparément les dix questions alternant les cinq thèmes.
  Aucun contenu pédagogique ni import n’est inclus dans ce lot.

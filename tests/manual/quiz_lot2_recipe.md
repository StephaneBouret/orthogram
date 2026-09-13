# Quiz — lot 2

## Mise en service locale, après revue

Branche : `preprod`. Ce lot ne modifie ni les données éditoriales ni la migration du lot 1. La base de travail n’a pas été migrée automatiquement.

La nouvelle migration ajoute seulement `quiz_attempt`, ses index et ses clés étrangères. Depuis la racine du dépôt, après revue et sauvegarde habituelle de la base locale :

```powershell
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20260913160000' --up --dry-run
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20260913160000' --up
php bin/console doctrine:schema:validate
```

Ne pas relancer `Version20260913110000`. La commande ciblée ci-dessus évite d’appliquer d’autres migrations en attente. Le retour arrière de la migration du lot 2 supprime les tentatives : il ne constitue pas une fonction de réinitialisation du quiz.

## Recette avec les deux questions du quiz « Test »

1. Ouvrir `/courses/formation-en-orthographe/les-fondamentaux/quiz-test-des-natures-de-mots` avec un compte autorisé. Vérifier le titre, le total **2**, l’absence du bouton générique « Valider » et la présence de la navigation/commentaires.
2. Commencer. La question sur les noms arrive en premier. Sélectionner au clavier (Tab, flèches pour les radios, Espace pour les cases). La sélection seule ne révèle aucune correction. Valider volontairement une mauvaise réponse ; vérifier le message, les bonnes propositions omises et l’explication.
3. Recharger pendant cette correction, puis choisir « Reprendre le quiz ». La question sur les adverbes, **2 sur 2**, doit apparaître. Une réponse fausse compte bien comme validée.
4. Valider la seconde question puis recharger avant de cliquer sur « Voir mon résultat ». Ce bouton doit rester proposé. Finaliser : bilan sur 2, détails consultables et étape terminée dans le sommaire, même avec 0/2.
5. Revenir sur le cours : le bilan est conservé. Cliquer sur « Recommencer le quiz » : nouvelle tentative, ancien résultat conservé, cours toujours terminé. Une leçon déjà terminée pendant le lot 1 reste terminée.
6. Sur une nouvelle tentative, ouvrir deux onglets sur la même question. Valider dans l’un puis une autre sélection dans l’autre. Le second onglet se resynchronise sans remplacer la première réponse enregistrée.
7. Avec les outils réseau du navigateur, couper la connexion après avoir coché une proposition puis valider. Le choix doit rester coché et un message permettre de réessayer. Rétablir le réseau : le client vérifie d’abord l’état enregistré. Si une réponse POST a été reçue par le serveur mais sa réponse HTTP perdue, le client retrouve la progression sans ajouter de point.
8. Vérifier à 390 px de large, en thème clair/sombre et en plein écran (sortie par Échap). Suivre le cours suivant puis revenir avec le navigateur : une seule interface et une seule soumission par clic. Les corrections longues doivent rester entièrement lisibles.

Pour tester une modification éditoriale, utiliser un **quiz dédié** : commencer, modifier/supprimer une proposition ou une question dans EasyAdmin, puis reprendre. La tentative conserve les anciens textes et corrections ; la suivante utilise la nouvelle configuration. Les tests automatisés n’utilisent jamais le quiz « Test ».

## Modèle et conservation

`QuizAttempt` appartient à un utilisateur, un cours et un quiz. `snapshot` contient un objet JSON version 1, typé dans l’entité : titre du quiz, liste ordonnée des questions, identifiants d’origine, consigne, phrase, mode, thème, explication et propositions avec leur correction. `responses` est une liste ordonnée de sélections validées, avec résultat et date serveur. Les identifiants éditoriaux sont des valeurs, sans FK vers les questions/propositions.

Le snapshot n’est jamais sérialisé intégralement vers le client. L’état public fournit la première question non validée sans explication ni indicateur de correction ; seuls les éléments déjà validés apparaissent dans `review`. Les textes sont échappés dans Twig et insérés par `textContent`. Le score final peut être zéro.

La suppression d’un cours ou d’un quiz conserve les tentatives via `ON DELETE SET NULL`. Elles deviennent inaccessibles depuis le parcours : cours supprimé ou sans quiz → 404 ; ancienne tentative sur un cours rattaché à un autre quiz → 404. Si le rattachement initial est rétabli, les anciennes tentatives de ce couple sont à nouveau disponibles. La suppression du compte supprime ses tentatives. Aucune interface générale d’archives n’est ajoutée ; la lecture d’un ancien identifiant reste soumise aux mêmes droits et au rattachement actuel.

## Contrat HTTP

Toutes les URLs sont générées par Symfony dans `_quiz.html.twig`. Toutes les actions exigent une connexion, `CourseVoter::VIEW` sur le cours et la propriété de la tentative.

| Méthode / chemin | Corps ou paramètre | Effet |
| --- | --- | --- |
| GET `/course/{id}/quiz` | `attemptId` facultatif en query | Dernière tentative, ancienne tentative autorisée, ou introduction ; aucune écriture. |
| POST `/course/{id}/quiz/start` | `{}` | Renvoie la dernière tentative ou crée la première avec contenu figé. |
| POST `/course/{id}/quiz/answer` | `attemptId`, `questionId`, `selectedIds: [entiers]` | Valide la question attendue ; sauvegarde même une réponse fausse. |
| POST `/course/{id}/quiz/finish` | `attemptId` | Exige toutes les réponses ; termine la tentative et met ce cours à DONE. |
| POST `/course/{id}/quiz/restart` | `attemptId` de la tentative terminée | Crée/renvoie son unique tentative suivante. |

Chaque POST exige un objet JSON et le header `X-CSRF-TOKEN`, validé contre le token de session `quiz_{courseId}`. Réponses privées `no-store`. Erreur d’entrée → 400, CSRF/droits → 403, ressource incohérente ou étrangère → 404, progression incompatible ou sélection déjà validée différente → 409. L’authentification conserve la redirection Symfony ; le client détecte les redirections/réponses HTML et invite à recharger.

La comparaison des ensembles reste unique dans `QuizCorrectionService::correctSelection`, appelée aussi par l’API historique sur entité. Aucune correction côté JavaScript. Un même ensemble d’identifiants rejoué conserve le résultat et la date initiaux ; une autre sélection ne remplace jamais la première.

## Transactions et concurrence

Chaque mutation ouvre une transaction et verrouille la ligne utilisateur existante (`PESSIMISTIC_WRITE`), y compris avant la première création. Les lectures des tentatives, de leur successeur et de la leçon utilisent aussi des lectures verrouillées et rafraîchissent les entités : sous MySQL `REPEATABLE READ`, une simple lecture pourrait sinon conserver une ancienne vue. Le verrou utilisateur sérialise seulement les mutations de quiz de ce compte ; les autres comptes restent indépendants.

L’index unique `(user_id, course_id, quiz_id, active_slot)` avec `active_slot = 1` pendant la tentative et NULL après finalisation protège l’unicité de la tentative active. L’index unique `previous_attempt_id` protège les redémarrages répétés. La réponse, son point éventuel et la progression sont écrits ensemble. Fin de tentative et leçon DONE sont écrites dans la même transaction. Le statut et la date d’une leçon déjà DONE sont conservés.

Le client relit l’état après un échec ambigu et avant un renvoi. Il ne remplace pas les champs cochés lorsque ni la mutation ni la lecture n’ont abouti. Une navigation après finalisation rafraîchit le sommaire. Les contrôleurs sont isolés sur leur racine et interrompent les requêtes lors des déconnexions/Turbo.

## Contrôles effectués

- PHPUnit ciblé : **95 tests, 543 assertions**, incluant administration, validation métier et parcours apprenant. Les nouveaux tests HTTP utilisent chacun un fichier SQLite jetable et redémarrent le noyau entre les requêtes.
- `php tests/manual/verify_quiz_lot2_mysql.php` : nouvelle base MySQL locale aléatoire, mappings et SQL de la seule migration du lot 2, montée/descente, FK historiques. Deux processus PHP indépendants, connexions distinctes et barrière par verrou réellement bloquante pour cinq courses : démarrage, même réponse, réponses différentes, finalisation, redémarrage. Base supprimée en fin de vérification.
- Chrome réel, données SQLite jetables : radios/cases au clavier, corrections, rechargement à mi-parcours et avant finalisation, bilan, redémarrage, panne réseau avant envoi, réponse HTTP perdue après enregistrement, réponse HTML de connexion simulée, JSON invalide simulé, double clic, deux onglets, mobile 390 px, plein écran, commentaires et retour Turbo. Aucun scénario n’a modifié la base de travail. Les simulations HTML/JSON vérifient le traitement client ; les droits anonymes sont testés par HTTP côté Symfony.
- Lints YAML (36), Twig (84), conteneur et syntaxe JavaScript réussis.
- PHPStan : 17 diagnostics déjà présents hors quiz, aucun nouveau dans ce lot. PHP-CS-Fixer ciblé et vérification des espaces du diff.

L’historique stocke volontairement les textes complets par tentative. Ce lot ne prévoit ni purge d’historique, ni tirage, ni import éditorial. La recette sur le quiz local « Test » reste à exécuter après application de la migration par l’utilisateur.

## Vérification directe du cache Turbo

Le raccordement corrigé est `turbo:before-cache@document->quiz#beforeCache`. Le contrôleur annule la requête, retire `aria-busy`, masque l’erreur et remplace la zone interactive par « Chargement du quiz… » avant que Turbo clone le DOM.

`tests/manual/quiz_turbo_cache_check.cjs` vérifie ce comportement dans Chrome, avec le serveur isolé `var/quiz_lot2_browser_router.php` sur le port 8793 et Chrome CDP sur le port 8794. Il nécessite une base navigateur jetable neuve et les fixtures `QuizPlayerFactory` ; ne pas le diriger vers la base de travail. Exécution : `node tests/manual/quiz_turbo_cache_check.cjs` avec Node 22.

Le test suit réellement le lien du cours suivant. Il observe `turbo:before-cache` sur `window`, après le gestionnaire Stimulus sur `document`, puis intercepte l’appel réel à `Turbo.session.view.snapshotCache.put` pour examiner le snapshot stocké. Il n’appelle pas `beforeCache()` directement et n’émet pas d’événement Turbo synthétique.

Trois cas ont été vérifiés : correction déjà affichée ; validation suspendue avant envoi, annulée via son `AbortSignal` ; POST réellement enregistré, dont la réponse réussie est retenue puis libérée après le retour au quiz, indépendamment de l’annulation réseau. Les snapshots contiennent uniquement le chargement, sans contrôles ni `aria-busy`. Après annulation sans envoi, la question reste attendue ; après enregistrement, la progression serveur est retrouvée. La réponse tardive ne modifie ni l’ancienne racine ni l’interface reconnectée. Aucune exception JavaScript.

# Renouvellement des quiz — administration (lot 2)

## Procédure pour Caroline

1. Dans **Quiz**, créez le quiz en mode **Fixe**, en laissant le quota vide pendant sa préparation. Il peut alors être enregistré sans question. Les quiz existants restent fixes tant que vous ne choisissez pas explicitement un autre mode.
2. Dans **Questions des quiz**, choisissez le quiz, puis le rôle **Question initiale**. Renseignez la consigne, la phrase, le corrigé, le thème, la position et les propositions. Une réponse unique exige exactement une bonne proposition ; le mode multiple en exige au moins une. Il faut au moins deux propositions.
3. Ajoutez ensuite les **Questions de réserve**, avec un thème présent dans le jeu initial. Une réserve ne rallonge pas le test. Modifier le jeu initial peut modifier la composition des futures tentatives. Dans la liste, utilisez les filtres **Quiz** et **Rôle** pour retrouver les questions concernées.
4. La catégorie de la phrase se choisit explicitement : **Non renseignée**, **Phrase correcte** ou **Phrase contenant une erreur**. Elle n’est jamais déduite des propositions ni de la bonne réponse.
5. Revenez dans le formulaire du quiz. Le tableau de composition donne les nombres d’initiales et de réserves par thème, ainsi que les catégories de toute la banque. Le nombre d’initiales détermine la longueur d’une tentative et les quotas par thème. La liste et la fiche détaillée affichent aussi les nombres et le mode.
6. Si vous souhaitez imposer un équilibre, renseignez **Phrases contenant une erreur** une fois le jeu initial constitué. Le quota s’applique **aussi en mode fixe**. Un champ vide signifie **aucune contrainte d’équilibre** ; **0** signifie que toutes les phrases présentées doivent être correctes. Toutes les questions initiales doivent alors avoir une catégorie et leur composition doit respecter le quota. En mode fixe, les réserves restent hors de cette composition et leurs catégories peuvent rester vides ; avec renouvellement, les réserves doivent aussi avoir une catégorie. Les thèmes restent facultatifs en mode fixe, avec ou sans quota.
7. Choisissez **Avec renouvellement**, puis enregistrez. Le premier passage utilise le jeu initial ; les suivants renouvellent les questions selon les possibilités de la réserve et la politique du lot 1. Une réserve absente ou insuffisante n’est pas bloquante : certaines questions pourront être réutilisées. Il n’y a pas d’interrupteur d’activation dans la liste.
8. En cas de refus, l’erreur apparaît dans le formulaire, près du champ concerné lorsque possible. La saisie reste affichée et rien n’est enregistré. Corrigez le champ indiqué puis enregistrez à nouveau. Les catégories, rôles, suppressions et déplacements sont vérifiés aussi pour les quiz fixes avec quota. Un déplacement vérifie les deux quiz concernés ; une suppression invalide affiche un message dans la liste. Les suppressions groupées sont désactivées.
9. Pour réorganiser un quiz en plusieurs étapes incompatibles entre elles, repassez en **Fixe** et, si nécessaire, videz temporairement le quota. Préparez les modifications, remettez le quota une fois le jeu initial compatible, puis réactivez le renouvellement si souhaité. La désactivation seule ne retire pas la contrainte du quota et conserve les réserves. Les tentatives déjà commencées ou terminées conservent leur contenu, leurs réponses et leur score ; les changements s’appliquent aux nouvelles tentatives.

## Recette réalisée le 25 octobre 2026

Destination vérifiée avant les écritures : `orthogram_quiz_renewal_recette_646344e9279b_test`, sur MySQL local. La migration du lot 1 était présente et n’a pas été rejouée. `.env.local` est inchangé. Courriels neutralisés (`null://null`), Messenger en mémoire, serveur limité à `127.0.0.1:8873`. Aucun worker externe lancé.

Le quiz synthétique **9**, ses questions **115 et 116**, le cours **67** et le compte synthétique **6** ont servi à la recette. Le compte, le cours et les deux questions ont été préparés par ORM sur la copie. La création du quiz vide, les modifications éditoriales et les actions apprenant ont été réalisées dans **Chrome 155 headless**, avec le DOM réel, les formulaires CSRF et le JavaScript de l’application :

- création progressive en mode fixe ; édition des rôles, du thème et des catégories ;
- tableau indiquant une question présentée et une réserve ; activation explicite avec quota zéro ;
- suppression de la catégorie d’une réserve refusée en HTTP 422, saisie conservée et absence d’écriture constatée en rouvrant le formulaire ;
- première tentative **28** sur la question initiale ; modification de son texte et de son corrigé pendant la tentative ; comparaison SQL stricte de la tentative avant/après édition, puis reprise identique ;
- finalisation avec score **1/1**, puis nouvelle tentative **29** utilisant la réserve, toujours une question ;
- ouverture du corrigé historique déplié : texte et corrigé antérieurs conservés ;
- désactivation : réserve conservée et tentative renouvelée en cours strictement identique.

Pas d’exception JavaScript ni de réponse HTTP 500 ; le seul HTTP 422 était le refus attendu. Le test a repris dans une nouvelle session Chrome pour déplier le corrigé avant de vérifier son texte visible.

Les requêtes externes du navigateur étaient interceptées. L’import Google Maps du bootstrap global a été remplacé localement par un module neutre afin de charger Stimulus sans réseau externe. Les assets du quiz et d’EasyAdmin n’ont pas été modifiés. La connexion utilisait une session locale de recette préauthentifiée ; le formulaire de connexion et la double authentification n’étaient pas dans cette recette. Les autorisations administrateur sont couvertes par les tests HTTP.

Les huit quiz réels ont été comparés avant/après : quiz, questions, propositions, positions, catégories et rattachements aux cours. Ils restent strictement identiques et en mode fixe. Tailles : fondamentaux **10, 12, 12, 22** ; module 1 **14, 12, 12, 20**. Les tentatives et complétions préexistantes sont inchangées. La base source `orthogram` a été vérifiée en lecture seule : schémas et données inchangés.

Les captures et journaux locaux se trouvent dans `var/quiz-renewal-lot2/` ; le bilan durable est dans [quiz_renewal_lot2_report.json](quiz_renewal_lot2_report.json). Le serveur et Chrome ont été arrêtés après recette. Les données synthétiques sont conservées uniquement sur la copie.

## Validation technique

### Correctif de cohérence du quota en mode fixe

Le test ajouté avant correction a démontré le défaut : un quiz fixe exploitable, avec catégories nulles, acceptait le quota zéro dans EasyAdmin (redirection après enregistrement), puis refusait une nouvelle tentative avec un HTTP 409. `QuizEditorialService` ignorait tous les quiz fixes, alors que `QuizConfigurationValidator`, utilisé au démarrage, appliquait déjà leur quota. Le test de non-régression attendant un refus HTTP 422 a ensuite échoué avant le correctif.

La validation éditoriale appelle désormais le même validateur dès qu’un quota est renseigné, y compris zéro, ou que le renouvellement est activé. Un quiz fixe sans quota conserve son parcours de préparation libre. Pour fixer un quota, il faut un jeu initial non vide et compatible ; retirer la dernière initiale, supprimer ou déplacer une question indispensable au quota est refusé. Les deux quiz d’un déplacement sont vérifiés. En mode fixe, seules les initiales sont soumises à la composition ; les réserves restent conservées et leurs catégories peuvent rester vides. Aucune règle du sélecteur ni du validateur de démarrage n’a été modifiée par ce correctif.

La recette complémentaire dans Chrome 155 sur la même copie isolée utilise le quiz synthétique **10**, la question **117** et le cours **68** : quota zéro refusé près du champ avec saisie conservée et comparaison SQL stricte ; démarrage sans quota réussi ; catégorie « Phrase correcte » puis quota zéro acceptés ; effacement de la catégorie refusé. La tentative **30**, avec une réponse validée, conserve strictement son snapshot, sa réponse et son score pendant les modifications éditoriales. Elle a ensuite été finalisée, puis la tentative **31** a démarré correctement en mode fixe avec quota zéro. Deux réponses HTTP 422 attendues, aucun HTTP 500 ni exception JavaScript. La recette a repris dans une seconde session pour utiliser le bouton de finalisation directement affiché lorsque toutes les réponses sont déjà validées.

Les données éditoriales, tentatives et complétions préexistantes de la copie sont inchangées, y compris celles de la recette précédente. Les huit quiz réels restent fixes, `.env.local` et le journal des migrations sont identiques. Les courriels et tâches externes restent neutralisés, avec les mêmes limites de session préauthentifiée et d’import Google Maps local que la recette initiale. Journaux et captures complémentaires : `var/quiz-fixed-quota/`. Les résultats du correctif sont regroupés dans la section `fixed_quota_correction` du rapport JSON.

Contrôles après correctif : **174 tests ciblés, 1 899 assertions** ; suite complète **1 131 tests, 37 506 assertions**, sans échec, avec le même test Windows préexistant ignoré. Les quatre cas de concurrence MySQL couvrent suppressions et déplacements, en mode renouvellement comme en mode fixe avec quota. PHPStan conserve exactement les **34 diagnostics antérieurs**, sans exclusion ajoutée. Syntaxe PHP, conteneur Symfony, style des fichiers PHP concernés et `git diff --check` conformes.

### Vérifications initiales du lot 2

Résultats : **169 tests ciblés, 1 794 assertions** ; suite complète **1 126 tests, 37 401 assertions**, sans échec, avec un test préexistant ignoré car Windows n’autorise pas la création de son lien symbolique (`CourseFilesTest`). Syntaxe PHP/Twig, conteneur Symfony, mapping Doctrine, style des fichiers PHP modifiés et `git diff --check` : conformes. Composer valide le projet et signale seulement les deux avertissements préexistants de contraintes de versions (`getid3`, `apache-pack`).

Les formulaires d’écriture prennent une transaction avant leur soumission, verrouillent les quiz concernés dans l’ordre de leurs identifiants, puis rechargent leurs questions et propositions. La validation finale porte donc sur la composition actuelle. Les erreurs de formulaire ne sont pas flushées. Une suppression refusée est annulée. Les écritures AJAX directes et les suppressions groupées ne contournent pas ce parcours.

Le test de concurrence utilise deux processus et deux connexions MySQL avec des collections volontairement chargées avant l’obtention du verrou : deux suppressions ou déplacements concurrents ne peuvent pas vider le quiz actif. Une opération réussit, l’autre est refusée ; la tentative commencée reste identique. Les tests MySQL créent leurs propres bases temporaires isolées, jamais la base de travail.

Commandes PowerShell utilisées pour les contrôles :

```powershell
php -d memory_limit=512M -d xdebug.mode=off vendor/phpunit/phpunit/phpunit --filter Quiz
php -d memory_limit=512M -d xdebug.mode=off vendor/phpunit/phpunit/phpunit
php -d memory_limit=512M -d xdebug.mode=off vendor/bin/phpstan analyse --no-progress --memory-limit=512M --error-format=json
php -d xdebug.mode=off bin/console lint:twig templates/admin/quiz --env=test
php -d xdebug.mode=off bin/console lint:container --env=test
composer validate --no-check-publish
git diff --check
```

PHPStan est comparé à l’état local du lot 1 validé (`5b25b6adde1255f36a01a5913674b4052072065c`), avant les changements du lot 2, avec les mêmes dépendances, configuration et commande. Les **34 diagnostics sont identiques**, fichiers, lignes, messages et identifiants compris. Aucune exclusion ajoutée. Le rapport JSON contient les diagnostics normalisés et les différences.

La sélection et sa politique de rotation du lot 1 sont inchangées. Aucun import des questions de Caroline, aucune activation des huit quiz réels, aucune migration sur la base de travail, aucun commit, push ou déploiement n’a été effectué.

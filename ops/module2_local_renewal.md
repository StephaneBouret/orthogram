# Module 2 — renouvellement local, 27 octobre 2026

L’activation des seules identités `module2_test1`, `module2_test2` et `module2_general` a été effectuée sur **orthogram**, en `dev`, à `127.0.0.1:3306`, serveur `STEF-LEGION` (MySQL 8.4.7). La résolution s’appuie sur `quiz_manifest_identity`, puis vérifie les ID locaux attendus ; aucun quiz n’est recherché uniquement par son numéro ou son titre.

| Identité | ID résolu | Initiales / réserves | Cours conservé | Renouvellement après | Quota |
| --- | ---: | --- | ---: | --- | --- |
| module2_test1 | 9 | 12 / 19 | 102 | actif | NULL |
| module2_test2 | 10 | 12 / 24 | 103 | actif | NULL |
| module2_general | 11 | 24 / 86 | 104 | actif | NULL |

La comparaison complète des 32 tables autorise exactement trois différences : `quiz.renewal_enabled` de `0` à `1` pour ces identités. Toutes les autres données et structures sont identiques : contenus, thèmes, positions, réponses, réserves, traces, quotas, rattachements, tentatives/snapshots/résultats, progressions et dictées. Les quatre quiz du module 1 et les quatre fondamentaux font aussi l’objet de relevés spécifiques avant/après, identiques.

**État réellement constaté avant intervention :** la table `quiz_attempt` était vide et aucune ligne `lesson` ne concernait les cours 102/103/104. Les rattachements EasyAdmin étaient présents. Ce constat a été confirmé directement par SQL avant activation ; aucune tentative de la recette utilisateur ne peut donc être attestée dans cette base au moment du relevé. Aucune tentative artificielle, récupération ou correction automatique n’a été effectuée. L’état présent a été intégralement sauvegardé et conservé.

## Code et tests

Le garde-fou module 2 autorise désormais `enable` et `disable` sur `orthogram` en `dev` avec `--allow-work-database`, comme l’import. Les cinq assertions de destination, le contrôle du port effectif, le plan lié à la destination et son recalcul sous verrou sont conservés. Production et hôtes distants restent refusés. Les commandes du module 1, les services partagés et le sélecteur ne sont pas modifiés.

Le rapport présente les `renewal_changes` prévus et l’application retourne `quizzes_changed`. Une répétition avec un nouveau plan valide retourne `noop=true` sans flush. Un ancien plan devenu périmé est toujours refusé, même pour une répétition.

Tests exécutés exclusivement sur bases jetables MySQL/SQLite et doubles de connexion :

- Ciblés module 2 : **72 tests, 519 assertions**, zéro échec.
- Non-régression quiz/modules 1 et 2 : **287 tests, 3 199 assertions**, aucune erreur, aucun échec ni test ignoré.
- Les refus sans consentement, pour destination incorrecte et production sont vérifiés pour les trois actions. Les identités `orthogram` utilisées dans les tests de garde-fou sont des doublures, jamais des connexions à la base de travail.
- Le cycle activation → répétition sans effet → désactivation est testé sur base jetable avec quiz du module 1/fondamentaux, quotas non NULL, cours rattachés, tentatives terminées et en cours, résultats et progressions. La comparaison de toutes les lignes vérifie que seuls les trois indicateurs changent ; la désactivation rétablit exactement le relevé initial.
- PHPStan niveau 6 sur les six fichiers PHP concernés, `lint:container`, PHP CS Fixer et `git diff --check` : conformes.

Commandes exécutées :

```powershell
$env:MODULE2_RECIPE_REPORTS = '0'
php -d memory_limit=512M -d xdebug.mode=off vendor/bin/phpunit tests/Services/ModuleTwoQuizDestinationTest.php tests/Integration/ModuleTwoQuizImportTest.php tests/Services/ModuleTwoQuizManifestTest.php --log-junit var/module2-renewal-local/targeted-tests.xml
php -d memory_limit=512M -d xdebug.mode=off vendor/bin/phpunit --filter 'ModuleOne|ModuleTwo|Quiz' --log-junit var/module2-renewal-local/quiz-regression.xml
```

La suite sans rapport avec les quiz n’a pas été lancée. Aucun test automatisé n’a utilisé `orthogram`. Aucune migration, réimportation, désactivation ou création de tentative n’y a été exécutée pour ce lot : une seule application d’activation et des lectures de contrôle.

## Sauvegarde vérifiée

Export SQL complet, réalisé avant mutation, conservé hors Git :

`var/backups/orthogram_module2_renewal_before_20261027_90e3d8d017adf849.sql`

Taille : **482 981 octets**. SHA-256 :

```text
af61bebee4563193222b8926157bd36b2a2217da3a6a4f87501667f7c1a5e43a
```

L’export a été restauré sur la nouvelle base locale jetable `orthogram_module2_restore_90e3d8d017adf849`. Toutes les lignes et colonnes des 32 tables correspondent au relevé source et le comparateur Doctrine ne relève aucune différence structurelle. Les DDL sont également comparés après normalisation de la seule déclaration redondante du jeu de caractères hérité. Cette base de restauration a été supprimée après vérification. La sauvegarde reste conservée ; les anciens exports sont intacts.

## Application et preuves

La prévisualisation a confirmé les trois identités, les tailles 12/12/24, les quotas NULL, les 177 copies conformes au manifeste et zéro ajout. Le plan exact a été recalculé sous verrou avant application.

```powershell
$local = @('--env=dev', '--expect-env=dev', '--expect-database=orthogram', '--expect-host=127.0.0.1', '--expect-port=3306', '--expect-server=STEF-LEGION')
php bin/console app:quiz:module2-renewal enable @local --report=var/module2-renewal-local/preview.json
# Après sauvegarde restaurée et examen du plan de cette destination uniquement :
php bin/console app:quiz:module2-renewal enable @local --apply --allow-work-database "--expect-plan=$ReviewedRenewalPlanHash" --report=var/module2-renewal-local/activate.json
php bin/console app:quiz:module2-renewal enable @local --report=var/module2-renewal-local/after-preview.json
```

Empreinte appliquée : `8328f6d7be13ed00f5d9419a981194c108752f6c8a67db5fc187838f8d0ce923`.
La nouvelle prévisualisation est cohérente, sans divergence et avec `renewal_changes=[]`. Son empreinte est `01cf28a3d0d6ab8672a97c91befff30b89ec6d1c8454516a82412a5eb04fdf9b`. Ces empreintes documentent cette opération ; elles ne doivent pas être réutilisées sans nouvel examen de l’état.

Les rapports opérationnels et captures restent hors Git dans `var/module2-renewal-local` : `backup.json`, `history-check.json`, `preview.json`, `activate.json`, `after-preview.json`, `conservation.json`, `protected-before.json`, `protected-after.json`, `final-verification.json`, ainsi que les résultats PHPUnit. Les fichiers `preview-summary.json` et `after-preview-summary.json` permettent de comparer les rattachements et historiques par quiz sans exposer les données des apprenants.

Une désactivation ultérieure exige sa propre prévisualisation `disable`, son plan et le même consentement local. Elle ne supprime pas les historiques. Aucune désactivation n’a été réalisée sur la base de travail pendant cette opération.

## Courte recette navigateur restante

1. Sur chacun des trois cours, terminer un premier passage puis utiliser **Recommencer** : toujours 12/12/24 questions, mêmes quotas de thèmes, avec utilisation des réserves. Sans tentative précédente persistée, le premier passage conserve le jeu initial.
2. Interrompre une tentative renouvelée puis recharger/reprendre : mêmes questions, même avancement et mêmes réponses déjà validées.
3. Terminer, vérifier le dénominateur du bilan et la complétion, puis consulter le bilan précédent : réponses et corrigés conservés. Le général impose au moins une reprise de question sur le thème « tout » ; le parcours intégral de la réserve n’est pas garanti.

Aucun push, accès Hostinger ou déploiement. Le commit local sur `preprod` ne contient que code, tests et documentation de ce chantier ; sauvegardes, captures et rapports opérationnels restent hors dépôt.

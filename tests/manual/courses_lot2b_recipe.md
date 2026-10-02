# Lot 2B — fichiers pédagogiques privés

Base de travail : `preprod`, `9d705410f3fd9b6345a6d479c17d436fba00d735`, arbre propre au début de l'intervention. Aucun changement du voter, de `isFree`, de la découverte ou du sommaire public. Aucune migration ajoutée.

**Le transfert des vrais fichiers reste à effectuer avant de valider la lecture locale.** Aucun fichier pédagogique réel n'a été copié, déplacé, supprimé ou réécrit pendant cette intervention. Le script de déploiement n'a pas été exécuté ; aucune connexion SSH ni opération sur Hostinger n'a été faite.

## Architecture et configuration

`COURSE_STORAGE_DIR` désigne une racine absolue contenant `files/`, `audios/`, `videos/`. Sans variable, la valeur est `%kernel.project_dir%/private/courses`, hors du cache et ignorée par Git. La variable accepte les paramètres Symfony via le processeur `resolve`, mais un chemin absolu littéral est recommandé en exploitation.

Les mappings Vich conservent les noms et propriétés en base, les namers et les options `delete_on_update`/`delete_on_remove`. Les mappings avatars et images des programmes sont inchangés. Les trois mappings pédagogiques n'ont plus de préfixe URL public. Leurs URI Vich ne doivent plus servir à fabriquer des liens ; les callbacks `download_uri` des formulaires utilisent la route administrative.

`CourseFileKind` définit les trois catégories, leurs propriétés et leur cohérence avec le type du cours. `CourseFileStorage` appelle `StorageInterface::resolvePath()` de Vich installé, puis valide le nom plat et le confinement du chemin réel dans la catégorie et dans la racine. Les traversées, chemins absolus, caractères de contrôle, wrappers, flux Windows et liens sortants sont refusés. Le stockage doit être administré par des identités de confiance : le contrôle `realpath` ne remplace pas les permissions et ne protège pas contre un acteur local autorisé à modifier simultanément les liens du stockage.

`CourseFileService` lit le fichier privé et conserve le rendu Twig avec `course`, `section`, `program`, ainsi que le retour `null` si le fichier manque. Aucun repli sur `public/courses`. L'estimation à partir des téléversements temporaires et celle à partir du contenu stocké restent disponibles.

- Lecture : `/courses/{id}/media/audio` ou `/courses/{id}/media/video`, GET/HEAD. `VIEW_COURSE` est évalué avant résolution, métadonnées, conditionnelles ou plages. Le type doit correspondre au média associé. Aucune route de source brute pour l'apprenant.
- Administration : `/admin/course-files/{id}/{source|audio|video}`, GET/HEAD. `ROLE_ADMIN`, puis `VIEW_COURSE` pour les contrôles de compte/authentification existants. Les fichiers associés restent récupérables par l'administrateur même après changement du type de cours. Pièce jointe `application/octet-stream`, nom enregistré, aucun chemin physique dans les en-têtes. Twig n'est pas exécuté dans ce téléchargement.
- Distribution : `BinaryFileResponse`, plages/HEAD/Last-Modified gérés par Symfony. `Cache-Control: private, no-store` (Symfony peut ajouter `max-age=0, must-revalidate` pour la session), `nosniff`. Pas de confiance activée pour X-Sendfile/X-Accel. Pas de calcul systématique d'ETag sur les grosses vidéos.
- `app:courses:verify-files` : vérification en lecture seule de tous les noms associés en base, y compris les anciennes associations. Sortie non nulle si un fichier est absent, illisible ou hors stockage. Le rapport donne l'identifiant du cours et la catégorie, pas le chemin physique.

Un cours `isFree=true` reste inaccessible aux anonymes et aux comptes sans abonnement. Les comptes inactifs et sessions 2FA incomplètes restent refusés.

Fichiers concernés :

| Domaine | Fichiers |
| --- | --- |
| Configuration | `.gitignore`, `config/services.yaml`, `config/packages/vich_uploader.yaml`, `config/packages/framework.yaml` |
| Résolution et lecture | `src/Enum/CourseFileKind.php`, `src/Services/Courses/CourseFileStorage.php`, `src/Services/Courses/CourseFileService.php` |
| Distribution et administration | `src/Controller/Course/CourseFileController.php`, `src/Controller/Admin/CoursesCrudController.php`, `templates/course/show/_content.html.twig` |
| Transfert et exploitation | `src/Services/Courses/CourseFileTransfer.php`, `bin/transfer-course-files.php`, `src/Command/VerifyCourseFilesCommand.php`, `ops/deploy_beta.sh` |
| Anciennes copies statiques | `public/courses/files/.htaccess`, `public/courses/audios/.htaccess`, `public/courses/videos/.htaccess` |
| Tests et procédure | `tests/bootstrap.php`, `tests/Controller/Admin/CoursesFreeAccessTest.php`, `tests/Controller/Course/CourseFilesTest.php`, `tests/Services/Courses/CourseFileStorageTest.php`, `tests/Services/Courses/CourseFileTransferTest.php`, le présent document |

## Transfert local, à exécuter séparément

Avant toute copie : arrêter les téléversements et les processus qui pourraient modifier/supprimer des fichiers, sauvegarder la base et les répertoires sources. Relever les vrais chemins et liens symboliques. Vérifier que `private/courses` n'est exposé par aucun autre virtual host, DocumentRoot, alias ou lien ; être hors de `public/` ne suffit pas. Sur une machine où le projet entier est servi par le web, choisir une autre racine absolue réellement privée.

Depuis `C:\Formations\orthogram`, simulation seulement :

```powershell
php bin/transfer-course-files.php --source-files="C:/Formations/orthogram/public/courses/files" --source-audios="C:/Formations/orthogram/public/courses/audios" --source-videos="C:/Formations/orthogram/public/courses/videos" --destination="C:/Formations/orthogram/private/courses"
```

Copie explicite, lors de l'opération de transfert ultérieure :

```powershell
php bin/transfer-course-files.php --source-files="C:/Formations/orthogram/public/courses/files" --source-audios="C:/Formations/orthogram/public/courses/audios" --source-videos="C:/Formations/orthogram/public/courses/videos" --destination="C:/Formations/orthogram/private/courses" --copy
php bin/transfer-course-files.php --source-files="C:/Formations/orthogram/public/courses/files" --source-audios="C:/Formations/orthogram/public/courses/audios" --source-videos="C:/Formations/orthogram/public/courses/videos" --destination="C:/Formations/orthogram/private/courses" --verify-only
php bin/console app:courses:verify-files
```

Si une autre destination est retenue, définir dans `.env.local` :

```dotenv
COURSE_STORAGE_DIR="CHEMIN_ABSOLU_PRIVE_VERIFIE"
```

Recompiler un éventuel environnement dumpé, puis vider le cache après changement de configuration. Ne pas valider la lecture avec un stockage vide. Les tests automatisés utilisent leur propre racine temporaire et n'effectuent pas ce transfert local.

L'outil fonctionne sans démarrer le kernel ni charger la base ou `.env.local.php`. Trois sources explicites et une destination absolue sont obligatoires. Simulation par défaut ; `--verify-only` exige toutes les copies identiques ; `--copy` copie uniquement les fichiers manquants. Les noms sont conservés, SHA-256 est vérifié avant/après chaque copie et sur l'inventaire final. Un conflit est détecté avant les écritures planifiées, et la création exclusive empêche l'écrasement concurrent. `.gitkeep` et `.htaccess` sont exclus ; sous-répertoires et liens parmi les entrées sont refusés. Une racine source explicitement indiquée peut être un lien du déploiement ; les destinations liées sont refusées.

Une relance conserve les copies identiques, ne les duplique pas et reprend les fichiers manquants. Une copie interrompue au milieu d'un fichier demeure un conflit : conserver/mettre en quarantaine cette copie partielle après investigation, puis relancer. Ne jamais demander à l'outil d'écraser un conflit. Les sources ne sont jamais supprimées ; la vérification n'impose pas l'absence de fichiers supplémentaires dans la destination (notamment les nouveaux téléversements).

Bloquer aussi les anciennes copies publiques. Les trois `.htaccess` livrés s'appliquent uniquement aux répertoires physiques `public/courses/files`, `audios`, `videos`. Ils ne bloquent pas globalement `/courses`. Leur efficacité dépend du serveur Apache/LiteSpeed et de sa configuration. Avec un autre serveur, configurer l'équivalent sur ces seuls chemins statiques. Tester les anciennes URL avec le vrai serveur local ; le client de tests Symfony ne sert pas les fichiers statiques.

## Script Hostinger proposé

Le script reçu n'était pas dans le dépôt. Sa version proposée est **`ops/deploy_beta.sh`** ; l'original de Downloads n'est pas modifié. Sur Hostinger, le script réellement lancé à la main est **`~/domains/orthogram.fr/deploy_beta.sh`** : sa mise à jour avec cette proposition sera une opération distincte, après sauvegarde et comparaison. Les chemins viennent du script et du complément utilisateur :

| Rôle | Chemin |
| --- | --- |
| Projet | `/home/u849885333/domains/orthogram.fr/public_html/beta` |
| Configuration source | `/home/u849885333/domains/orthogram.fr/.env.beta` |
| Anciennes sources | `/home/u849885333/domains/orthogram.fr/public_html/shared/courses` |
| Avatars publics | `/home/u849885333/domains/orthogram.fr/public_html/shared/avatars` |
| Destination proposée, à vérifier | `/home/u849885333/domains/orthogram.fr/private/orthogram/courses` |

`SHARED_ROOT` reste inchangé pour les avatars. Les cours ont leur propre `PRIVATE_COURSES`. Le script ne recrée plus le lien `public/courses`, ne rétablit plus le `.htaccess` permissif des anciens cours et ne leur applique plus les permissions publiques 755/644.

Les étapes avatars, restauration `.env.local`, `.htaccess` racine, suppression de l'ancien dump, `composer dump-env prod`, caches, migrations existantes, réparation avatars, LiipImagine, assets et permissions `var`/`public/media` sont conservées. Le lot n'ajoute pas de migration. L'ajout de `pipefail` s'accompagne de `find ... -print -quit` pour l'inventaire des avatars, évitant qu'un pipe interrompu ne saute leur copie.

`--initial-switch` effectue le précontrôle, la copie exclusive et sa vérification, puis enlève uniquement le lien symbolique dont la cible a été vérifiée. Il ne supprime pas le dossier partagé. `--deploy` exige le marqueur privé `.orthogram-private-ready` et les sous-dossiers : il ne consulte ni ne recopie l'ancien inventaire. Un lien public réintroduit est une erreur. Un vrai dossier `public/courses` contenant des fichiers est aussi une erreur : le traiter par inventaire/copie distincts, sauvegarde et archivage privé contrôlé, jamais par suppression automatique.

Le marqueur est créé seulement à la fin de la préparation réussie, après contrôle des références en base. Si la première exécution échoue avant le marqueur, maintenir l'interruption et relancer `--initial-switch` après correction ; la copie est idempotente. Ce marqueur n'est pas une preuve d'isolation HTTP.

## Maintenance conservée pendant la publication automatique Hostinger

Constats rapportés par l'opérateur : chaque push sur `preprod` déclenche clonage, `composer install`, puis publication ; le journal annonce la préservation des chemins correspondant au `.gitignore`. Une protection dans `public_html/.htaccess` seule ne bloque pas le sous-domaine. Une maintenance **inconditionnelle** en tête de `beta/.htaccess` et `beta/public/.htaccess` a bien donné 503 sur les cinq points d'entrée testés. Ces constats ne valident pas encore la nouvelle règle **conditionnelle** sur LiteSpeed.

Le témoin commun est `/home/u849885333/domains/orthogram.fr/.orthogram-maintenance`, hors du projet publié. `public/.htaccess` commence maintenant par `RewriteCond ... -f` et une réponse 503 avec un message texte de maintenance. Ses règles Symfony restent inchangées. Le `.htaccess` racine généré par le script contient exactement la même protection avant ses autres directives. Sans témoin, les règles normales continuent, notamment en local. Aucun contournement par cookie, rôle ou adresse IP n'est ajouté.

Le `.htaccess` racine n'est pas suivi par Git : seule l'entrée **`/.htaccess`** a été ajoutée au `.gitignore` pour demander sa conservation. **`public/.htaccess` reste versionné** et doit être publié avec le code. L'ignore Git ne prouve ni la conservation effective par Hostinger ni l'absence d'une fenêtre d'exposition durant sa publication : contrôler les fichiers et les réponses HTTP, y compris pendant la publication. Si cette conservation n'est pas effective, arrêter la bascule et maintenir une protection côté hébergement vérifiée avant toute nouvelle publication.

Le script exige le témoin avant toute opération de déploiement, en plus des attestations déjà prévues au lot 2B. Il écrit le `.htaccess` racine dans un fichier temporaire du même répertoire, puis le remplace par renommage atomique après écriture complète et réglage des permissions de lecture. Un échec d'écriture laisse le fichier actif intact ; le nettoyage ne vise que le fichier temporaire. **Le script ne supprime jamais le témoin**, après succès comme après échec. Le témoin ne remplace pas l'arrêt des workers et autres écritures CLI ; il bloque les requêtes HTTP passant par ces règles, pas `composer install` ni ses éventuels auto-scripts.

Séquence à réaliser ultérieurement par l'opérateur, sans déclencher de publication avant les premiers contrôles :

1. Sauvegarder les deux `.htaccess` actifs et le script manuel. Relever les **cinq URL exactes du contrôle déjà effectué** dans le compte rendu opérateur, sans supposer leurs chemins. Créer le témoin **avant le push et avant de remplacer toute protection inconditionnelle existante** :

   ```bash
   touch /home/u849885333/domains/orthogram.fr/.orthogram-maintenance
   ```

   Vérifier que LiteSpeed peut constater son existence (traversée des parents comprise). Installer le bloc conditionnel en tête des deux fichiers actifs par remplacement préparé, sans tronquer les fichiers ni supprimer les règles existantes. Lors de cette première préparation, ne retirer l'ancienne règle inconditionnelle qu'avec le témoin présent ; la version finale doit dépendre du témoin pour permettre la réouverture. Le script révisé ne peut pas protéger rétroactivement la phase de publication qui précède son lancement.
2. **Avant publication** : vérifier 503 sur les cinq URL relevées, en GET et HEAD, et vérifier le message sur GET. Compléter avec une route applicative, une ressource statique existante et les accès directs au front controller réellement disponibles. Tester depuis un client externe, sans se fier à un cache de navigateur. Conserver horaires, URL et résultats. Si une entrée laisse passer l'application, ne pas publier. Arrêter aussi les téléversements et les tâches d'écriture comme prévu pour la bascule.
3. **Pendant puis après publication automatique** : laisser le témoin en place durant clonage, Composer et publication ; surveiller les mêmes URL, puis répéter GET/HEAD lorsque Hostinger annonce la fin. Vérifier sur disque la présence du témoin, le maintien du bloc racine et la version publiée de `public/.htaccess`, puis consulter le journal de préservation. Une ligne du journal ou le `.gitignore` seuls ne suffisent pas. Toute réponse applicative inattendue impose de rétablir la protection et de suspendre la suite.
4. Après publication, comparer `ops/deploy_beta.sh` au script manuel sauvegardé, puis installer la version révisée à **`~/domains/orthogram.fr/deploy_beta.sh`**. Lancer ce fichier dans le mode approprié, avec les attestations du lot 2B, **témoin toujours présent**. **Après le script**, vérifier à nouveau le témoin, le bloc racine généré et les cinq réponses 503. En cas d'échec du script, conserver la maintenance et corriger avant de poursuivre. Effectuer sous maintenance les vérifications techniques possibles en CLI : intégrité, références en base, configuration, permissions, caches et revue des protections statiques permanentes.
5. **Réouverture contrôlée** : préparer les comptes, URL et fichiers de recette et garder un opérateur prêt à remettre le témoin. Le bloc ne prévoit aucun accès navigateur privilégié pendant la maintenance : la recette finale de lecture, connexion, 2FA et administration se fait pendant une fenêtre de réouverture surveillée, après les contrôles techniques. Retirer alors **manuellement le seul témoin**, sans enlever les protections permanentes des anciens fichiers :

   ```bash
   rm -- /home/u849885333/domains/orthogram.fr/.orthogram-maintenance
   ```

   Vérifier immédiatement le retour au comportement normal des cinq entrées, puis effectuer la recette navigateur/HTTP détaillée ci-dessous : lecture abonné/admin, refus anonyme/non-abonné/2FA, plages, téléchargements administratifs et anciennes URL. Une réponse 503 pendant la maintenance ne valide pas ces autorisations ni le refus permanent des anciennes copies. Contrôler les caches si une ancienne réponse de maintenance persiste.
6. Si la recette finale échoue, recréer immédiatement le témoin avec la commande `touch` ci-dessus et revérifier les cinq 503 avant correction. Si elle réussit, laisser le témoin absent et consigner la réouverture. Pour tout déploiement suivant, recréer le témoin et vérifier les 503 **avant le push** : le script manuel arrive après la publication automatique et ne peut pas assurer seul cette interruption.

Pour chaque URL réellement relevée, exemples de contrôles à effectuer sur le serveur/client de recette (non exécutés pendant cette intervention) :

```bash
curl -sS -D - -H 'Cache-Control: no-cache' 'URL_EXACTE_DU_RELEVE'
curl -sS -I -H 'Cache-Control: no-cache' 'URL_EXACTE_DU_RELEVE'
```

Vérifier aussi les destinations des éventuelles redirections. Les contrôles locaux se limitent à la syntaxe Bash, au diff, à l'identité des deux blocs de maintenance et au suivi/ignore Git. L'interprétation de `-f`, le message 503, la conservation pendant publication et le retour au fonctionnement normal doivent encore être vérifiés sur **LiteSpeed avec et sans témoin**. Aucune requête HTTP vers Hostinger, connexion SSH ou publication n'a été effectuée pour cette adaptation.

## Première bascule Hostinger — opérations restant à réaliser

1. **Inventaire serveur et sauvegardes.** Constater les DocumentRoot réels de `beta.orthogram.fr`, `orthogram.fr`, `www` et des autres domaines/sous-domaines du compte ; inspecter les alias, liens (`realpath`, `readlink`, inventaire des liens sous les racines constatées), règles de réécriture, caches/CDN et éventuelles autres copies. Vérifier la destination proposée, ses parents et l'absence d'exposition sous *toutes* ces racines. Sauvegarder la base, les trois sources, la configuration, les anciens `.htaccess`, le script et la version de code. Les archives doivent elles-mêmes être privées.
2. **Identité et permissions.** Constater l'utilisateur/groupe effectif de PHP-FPM, les restrictions `open_basedir` et l'utilisateur de déploiement. Le propriétaire des scripts PHP ou l'utilisateur SSH ne prouvent pas l'identité du processus PHP. Utiliser la configuration du pool/hébergement ou un diagnostic restreint, jamais une page `phpinfo()` publique persistante. Précréer la destination et permettre à PHP traversée des parents, lecture, création, remplacement et suppression dans les trois catégories. Si les identités diffèrent, utiliser le groupe réellement constaté et/ou des ACL ; par exemple 0770 sur les répertoires et 0660 sur les fichiers uniquement si ce groupe est approprié. Si la même identité suffit, 0700/0600 peuvent convenir. Aucune permission 777 ; aucun `chmod` global du domaine. Le script crée les copies sous `umask 007` et ne réattribue pas arbitrairement les droits du stockage. Tester un fichier jetable avec l'identité PHP réelle, puis le retirer.
3. **Simulation préalable.** Déposer le code/outillage sous contrôle de maintenance ou dans une préparation isolée, sans lancer les auto-scripts de démarrage avant le transfert. Exécuter la commande ci-dessous sans `--copy`, conserver le rapport et résoudre les conflits. Elle n'exige pas d'environnement Symfony compilé.
4. **Interruption.** Suivre la séquence de maintenance ci-dessus : témoin présent et 503 constatés dans les deux points de configuration avant publication. Arrêter les téléversements, remplacements, suppressions et tâches capables d'écrire. Vérifier la conservation après publication et après remplacement atomique du `.htaccess` racine par le script. Maintenir l'interruption en cas d'erreur. Faire une sauvegarde finale cohérente des fichiers et de la base.
5. **Anciennes sources inaccessibles.** Pendant cette interruption, configurer le refus HTTP sur le répertoire statique réel `public_html/shared/courses` et toutes ses autres expositions, sans toucher `shared/avatars` ni les routes `/courses/...`. Sous Apache compatible, un `.htaccess` `Require all denied` à la racine de cet ancien stockage *statique* peut être un élément de protection, à sauvegarder et à tester ; il ne prouve rien sur d'autres virtual hosts ou serveurs qui l'ignorent. Les anciens `.htaccess` permissifs et les règles enfants/alias doivent être inspectés. Si la configuration d'accès ne peut être prouvée, conserver les sources dans une archive réellement privée après une opération distincte vérifiée, puis neutraliser leur exposition publique avant remise en service. La copie privée seule et le retrait d'un lien ne clôturent pas le risque.
6. **Configuration persistante.** Ajouter dans `/home/u849885333/domains/orthogram.fr/.env.beta` :

   ```dotenv
   COURSE_STORAGE_DIR=/home/u849885333/domains/orthogram.fr/private/orthogram/courses
   ```

   Vérifier qu'aucune variable du pool PHP/hébergement ne surcharge ce chemin avec une ancienne valeur. Le script valide le fichier source sans afficher ses secrets, copie d'abord `.env.beta` vers `.env.local`, exporte la valeur pour ses commandes CLI, puis supprime l'ancien dump et lance `composer dump-env prod`. Le stockage persiste en dehors du projet et du cache.
7. **Exécution ultérieure autorisée.** Après avoir *constaté* maintenance, isolation privée et blocage des sources conservées, l'opérateur pourra définir les trois attestations ci-dessous et lancer le mode initial. Ces variables sont des garde-fous humains, pas des tests automatiques de Hostinger. Ne pas les positionner par anticipation.
8. **Ordre interne.** Précontrôles → copie privée vérifiée → retrait du lien attendu → avatars → restauration de configuration → compilation d'environnement → caches → migrations existantes → `app:courses:verify-files` → étapes restantes → marqueur. Toute erreur stoppe la préparation. Aucune levée automatique de maintenance. Si le contrôle des références échoue, retrouver les fichiers manquants dans les sauvegardes/sources ou corriger les associations après analyse ; ne pas créer un repli public.
9. **Contrôles techniques puis réouverture contrôlée.** Valider les contrôles CLI et la préparation des protections permanentes sous maintenance ; purger toute ancienne mise en cache CDN/proxy publique des fichiers. Retirer manuellement le témoin pour la fenêtre surveillée décrite ci-dessus, puis tester lecture, administration et anciennes URL depuis le navigateur/client extérieur. Rétablir immédiatement le témoin si la recette échoue. Une page de maintenance 503 ne constitue pas une preuve durable de blocage des fichiers.

Simulation serveur (à exécuter ultérieurement, depuis le projet) :

```bash
php bin/transfer-course-files.php \
  --source-files=/home/u849885333/domains/orthogram.fr/public_html/shared/courses/files \
  --source-audios=/home/u849885333/domains/orthogram.fr/public_html/shared/courses/audios \
  --source-videos=/home/u849885333/domains/orthogram.fr/public_html/shared/courses/videos \
  --destination=/home/u849885333/domains/orthogram.fr/private/orthogram/courses
# Les mêmes arguments avec --copy font une copie vérifiée ; --verify-only ne copie rien.
# Le mode initial du script réalise ces trois passes, nul besoin de recopier à la main.

export ORTHOGRAM_MAINTENANCE_CONFIRMED=1
export ORTHOGRAM_PRIVATE_STORAGE_VERIFIED=1
export ORTHOGRAM_LEGACY_HTTP_BLOCKED=1
bash ~/domains/orthogram.fr/deploy_beta.sh --initial-switch
# Déploiements suivants, mêmes vérifications et interruption :
bash ~/domains/orthogram.fr/deploy_beta.sh --deploy
```

Ne pas exécuter successivement les deux dernières lignes lors de la première bascule : elles illustrent les deux modes. Le script n'effectue ni `git pull`, ni installation des dépendances, ni activation automatique du site, comme l'original. Coordonner la mise en place du code avec cette interruption. Ne pas laisser l'ancien script reprendre son travail : il recréerait le lien et la protection permissive.

## Recette navigateur et HTTP (locale puis beta)

Préparer des comptes de recette : administrateur actif avec 2FA terminée, abonné actif, compte actif sans abonnement, compte inactif et administrateur avec 2FA en attente. Utiliser un cours Twig, un audio et une vidéo avec fichiers existants transférés ; relever leurs identifiants et anciennes URL réelles. Répéter les refus avec `isFree=true`.

1. Abonné : ouvrir chaque cours, vérifier le texte Twig et les variables de contexte ; lire audio/vidéo, avancer/reculer dans la durée. Dans Réseau, les lecteurs pointent vers `/courses/ID/media/audio|video`, aucune URL `/courses/audios|videos|files/`. Les requêtes de plage autorisées renvoient 206 et `Content-Range`, avec les bons octets ; HEAD sans corps conserve les métadonnées. Le cache contient `private` et `no-store` ; aucune délégation X-Sendfile/X-Accel.
2. Copier ces URL dans une fenêtre anonyme et avec le compte sans abonnement : redirection d'authentification ou 403, jamais d'octets du média. Répéter GET, HEAD, `Range`, `If-Modified-Since` et `If-None-Match: *`. Tester le compte inactif (retour connexion) et la session 2FA en attente (retour 2FA). Même contrôle sur les téléchargements administratifs ; l'abonné n'y a pas accès.
3. Révoquer l'abonnement après une première lecture, conserver la session/cookies et rejouer une requête de plage et une conditionnelle : refus, aucune réponse 206/304 autorisant une réutilisation après perte des droits. Les octets déjà reçus par le navigateur ne peuvent pas être révoqués ; le contrôle porte sur les nouvelles requêtes.
4. Administrateur : ajouter un **cours de recette** avec un fichier jetable, constater le nom généré, télécharger sa source (pièce jointe dont le texte Twig reste brut), remplacer, vérifier l'ancien fichier de recette supprimé par Vich, supprimer via la case du formulaire. Faire le même cycle audio/vidéo. Vérifier la durée automatique Twig et les associations aux cours. Ne pas effectuer ce test destructif sur un vrai fichier pédagogique.
5. Sur fixtures de recette : fichier absent → 404 pour média/download, message habituel pour Twig ; type audio demandé en vidéo → 404 ; catégorie source demandée sur la route apprenant → 404. Aucun chemin serveur dans les liens/en-têtes/erreurs publiques (`APP_DEBUG=0` en prod).
6. Vérifier avatars existants et ajout/remplacement d'un avatar de recette, images des programmes, pages `/courses/{programme}/{section}/{cours}`, progression/commentaires et administration. Aucune interdiction générale du préfixe `/courses`.

Exemples HTTP à adapter avec un identifiant, un vrai nom ancien et des cookies de recette obtenus après authentification complète (ne pas publier les cookies) :

```bash
curl -i 'https://beta.orthogram.fr/courses/ID/media/audio'
curl -I 'https://beta.orthogram.fr/courses/ID/media/audio'
curl -i -H 'Range: bytes=0-15' 'https://beta.orthogram.fr/courses/ID/media/audio'
curl -i -H 'If-None-Match: *' 'https://beta.orthogram.fr/courses/ID/media/audio'
curl -i -b cookies-recette.txt -H 'Range: bytes=0-15' 'https://beta.orthogram.fr/courses/ID/media/audio'
curl -I -b cookies-recette.txt 'https://beta.orthogram.fr/courses/ID/media/video'
# Rejouer Last-Modified reçu dans If-Modified-Since, avant puis après révocation.
```

Anciennes expositions à vérifier sur **des fichiers dont l'existence est confirmée**, en GET, HEAD et Range, avec et sans cookies, en HTTP/HTTPS et sur les hôtes/alias constatés :

- `https://beta.orthogram.fr/courses/files/NOM.html.twig`, `/courses/audios/NOM`, `/courses/videos/NOM` ;
- variantes `/public/courses/...`, dont les éventuelles redirections suivies ;
- `https://orthogram.fr/shared/courses/files/NOM.html.twig` et les catégories audio/vidéo ;
- variantes `/beta/public/courses/...`, `/beta/courses/...`, domaines `www`, autres racines/alias/liens réellement identifiés ;
- toute URL qui exposerait le chemin privé, si une racine ou un alias constaté pourrait le couvrir.

Résultat exigé pour les anciennes URL : refus/404 sans contenu ni octets de fichier, jamais 200/206/304 donnant accès au contenu ; une redirection doit être suivie pour vérifier la destination. Les réponses de maintenance seules sont insuffisantes. Ne pas déduire la sécurité de quelques URL devinées ou d'un `.htaccess` présent sur disque. Vérifier également l'absence de listing et purger les caches publics antérieurs ; le navigateur d'un ancien lecteur peut conserver des données déjà reçues.

## Retour arrière sans perte

Maintenir/rétablir l'interruption des écritures. Sauvegarder **l'état courant** de la base et de tout le stockage privé, y compris les nouveaux noms téléversés, remplacements et suppressions depuis la bascule. Conserver les snapshots précédents et les sources anciennes bloquées. Ne jamais recopier automatiquement l'ancien public par-dessus le privé : il peut contenir des versions obsolètes dont la base ne veut plus, et ne contient pas les nouveaux téléversements.

Le retour privilégié est une version de code compatible avec ce stockage et ces routes privées (corriger/réappliquer le lot 2B sur la version applicative choisie). Garder `COURSE_STORAGE_DIR`, recompiler l'environnement/cache et contrôler toutes les références avant réouverture. Un retour brut au lot 2A réintroduit les URL statiques ; il n'est pas une solution sûre à remettre en service. Si une telle restauration est indispensable pour diagnostic, la maintenir hors accès public, conserver tous les fichiers privés, et préparer d'abord l'adaptation de distribution contrôlée. Ne pas relancer l'ancien `deploy_beta.sh`, ne pas recréer le lien public et ne pas rétablir son `.htaccess` permissif. Un rollback de base doit être réconcilié avec les téléversements post-bascule, jamais supprimer ceux-ci.

## Liens éditoriaux et frontière des vérifications

Recherche locale effectuée dans le code, les templates, assets, fichiers pédagogiques et `prep_cours` : aucune référence pédagogique intégrée aux anciens préfixes trouvée. Les occurrences applicatives Vich ont été remplacées ; les références restantes de procédure et de tests sont intentionnelles. Aucun contenu éditorial n'a été modifié en masse. Les contenus présents uniquement en base ou sur Hostinger ne sont pas couverts par cette recherche : inventorier descriptions, corrections, sources Twig distantes et liens absolus vers `/shared/courses` ou d'autres alias, puis adapter individuellement les liens nécessaires vers une route autorisée. Une source Twig ne doit jamais devenir une cible brute pour l'apprenant.

Symfony teste les droits, la résolution, les réponses de fichiers, les formulaires et l'outil sur fixtures isolées. Il ne prouve ni DocumentRoot, ni `AllowOverride`, ni permissions PHP-FPM, ni alias, ni caches du serveur réel. Les constats opérateur sur l'ancienne maintenance inconditionnelle sont distingués ci-dessus des **vérifications de la nouvelle protection conditionnelle et du lot 2B restant à effectuer sur Hostinger**. Aucune identité PHP, exposition HTTP ou configuration Hostinger n'a été présentée comme constatée localement.

## Contrôles locaux exécutés

- PHPUnit ciblé et non-régressions : **273 tests, 3 057 assertions, aucun échec, 1 test ignoré** lors de la passe globale. Périmètre : nouveaux fichiers/transfert, administration cours et quiz, interactions cours, lecteur quiz, résultats quiz, sommaire/progression, voter, services cours et entités Courses/QuizQuestion/Exercice. Bases SQLite isolées ; stockage temporaire par processus. La suite entière des rappels, qui utilise d'autres bases de test, n'a pas été exécutée pour ce lot.
- Dernière passe après désactivation explicite de X-Sendfile et ajout des scénarios `If-Range` : **82 tests, 830 assertions, aucun échec, le même test ignoré** (`CourseFilesTest`, `CourseFileStorageTest`, `CourseFileTransferTest`). Ces 82 tests recouvrent la passe précédente, ils ne s'y ajoutent pas comme nouveaux tests distincts. Container et YAML revérifiés après ce changement.
- Le test de lien symbolique de **fichier** est ignoré car sa création n'est pas autorisée pour ce compte Windows. Le test de sortie par **jonction de répertoire Windows** a réellement été exécuté et passe, ainsi que les noms malformés et chemins résolus hors catégorie. Rejouer le test de lien de fichier sur Linux avant la recette hébergée.
- `lint:container`, `lint:yaml`, `lint:twig templates/course` (34 templates), `git diff --check` : réussis.
- PHP CS Fixer, contrôle des 13 fichiers PHP concernés : aucune correction restante.
- `composer validate --no-check-publish` : valide ; avertissement préexistant sur la contrainte `symfony/apache-pack: *`. Aucune dépendance installée ou mise à niveau.
- PHPStan global : **26 erreurs**, toutes dans 8 fichiers de tests préexistants non modifiés, aucune dans les fichiers du lot. Elles concernent principalement des types d'itérables et les tests des rappels calendaires. Le contrôle global n'est donc pas annoncé comme réussi.
- `bash -n ops/deploy_beta.sh` avec Bash de Git for Windows : syntaxe valide. **Analyse syntaxique uniquement**, aucune étape du script exécutée, aucune validation d'exécution Hostinger.

Commande de la passe PHPUnit globale :

```powershell
vendor\bin\phpunit tests/Controller/Course/CourseFilesTest.php tests/Controller/Admin/CoursesFreeAccessTest.php tests/Controller/Course/CourseInteractionTest.php tests/Controller/Course/QuizPlayerTest.php tests/Controller/Course/SidebarCompletionTest.php tests/Controller/Admin/QuizAdminTest.php tests/Controller/QuizResultsTest.php tests/Security/Voter/CourseVoterTest.php tests/Services/Courses tests/Entity/CoursesTest.php tests/Entity/QuizQuestionTest.php tests/Entity/ExerciceTest.php
```

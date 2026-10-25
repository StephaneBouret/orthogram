# Bêta — livraison du renouvellement des quiz

Préparation locale du 25 octobre 2026, depuis `9a1f2c0095c12f4ae3ba7cd03a8dc30a1154084c`, branche **`preprod`**, arbre initial propre. Les adaptations de cette préparation ne sont pas committées. **Aucune commande distante ci-dessous n'a été exécutée.** Aucun accès Hostinger, aucune écriture sur `orthogram`, aucun push ou déploiement. Les contenus et la répartition 16/13/18/47 sont définitivement validés.

Cette procédure complète [le déploiement existant](deploy_beta.md) et les recettes [lot 1](../tests/manual/quiz_renewal_lot1.md), [lot 2](../tests/manual/quiz_renewal_lot2.md), [lot 3](../tests/manual/quiz_renewal_lot3.md). Elle ne remplace pas les protections des cours et dictées. Les commandes sont du **Bash Linux**, à exécuter ultérieurement après autorisation de livraison. Arrêter à toute erreur ; ne pas réparer ou restaurer automatiquement.

## Compatibilité précise du titre de `fundamentals_4`

Diagnostic communiqué pendant le déploiement : la bêta utilise **« Test : réviser les fondamentaux »**, tandis que le manifeste porte **« Test général : réviser les fondamentaux »**. La prévisualisation précédente a refusé la résolution avec `applied=false`. Selon ce diagnostic, les deux migrations sont déjà appliquées, aucun import ni renouvellement n'a été activé et la maintenance reste active. Ce correctif est préparé et testé localement ; aucun état distant n'a été consulté ou modifié pour le produire.

Le résolveur accepte explicitement **ces deux titres et eux seuls pour la clé `fundamentals_4`**, dans la requête SQL puis dans la comparaison stricte PHP. Les trois rattachements restent obligatoires :

- `course.slug = test-general-reviser-les-fondamentaux` ;
- `section.slug = les-fondamentaux` ;
- `program.slug = formation-en-orthographe`.

Une seule correspondance doit exister **sur l'ensemble des deux titres**. Si chacun désigne un quiz satisfaisant ces rattachements, la résolution est ambiguë et refusée : aucune priorité au titre du manifeste, aucun choix du premier résultat. Un titre non autorisé, une variante de casse ou un espace supplémentaire restent refusés par la vérification PHP. Le rattachement unique, les huit quiz distincts et le titre exact du manifeste pour chacun des sept autres quiz restent exigés. Aucun identifiant n'est codé en dur.

Ce changement ne renomme rien et n'écrit aucune donnée. `resources/quiz/module1_reserve.json` reste strictement inchangé, SHA-256 **`a7ea9d939aa93971fdf29e3661f400f5043a77ca51fd87c462ab841e4cef72ba`**. Les empreintes de manifeste/contenu enregistrées dans `quiz_reserve_import` et leur validation ne sont pas modifiées. Les réserves déjà importées sont toujours reconnues ; une empreinte altérée reste signalée comme divergence.

Après une future livraison autorisée de ce correctif, reprendre la **prévisualisation** avec la destination vérifiée et un nouveau fichier, en conservant la maintenance. Ne pas rejouer les migrations déjà appliquées, renommer le quiz, modifier les slugs ou reprendre une ancienne empreinte de plan pour contourner le blocage. Dans la session Bash où `PHP_BIN`, `DEST` et `BACKUP_DIR` ont été vérifiés :

```bash
"$PHP_BIN" bin/console app:quiz:module1-reserves --env=prod --no-debug "${DEST[@]}" \
  --report="$BACKUP_DIR/import-preview-title-compat.json" > "$BACKUP_DIR/import-preview-title-compat.stdout.json"
```

Cette commande n'importe rien. Lire le nouveau rapport avant toute action ultérieure autorisée. Les autres contrôles de contenu, composition, provenance, destination et transaction restent applicables. Voir le [bilan du correctif et de ses tests locaux](../tests/manual/quiz_fundamentals_title_compat_report.json).

## 1. Fonctionnement constaté du déploiement

`ops/deploy_beta.sh` ne fait ni `git pull`, ni checkout, ni push. La documentation existante décrit la publication automatique Hostinger de **`preprod`**, avec installation Composer, puis l'exécution manuelle de `/home/u849885333/domains/orthogram.fr/deploy_beta.sh`. Ce déclenchement et la branche configurée dans hPanel restent à reconfirmer sur place ; le script SSH réellement installé doit être comparé à celui livré.

Chemins présents dans le dépôt, à vérifier sur place avant toute action :

| Usage | Chemin documenté |
| --- | --- |
| Projet publié | `/home/u849885333/domains/orthogram.fr/public_html/beta` |
| Environnement source | `/home/u849885333/domains/orthogram.fr/.env.beta` |
| Témoin de maintenance partagé | `/home/u849885333/domains/orthogram.fr/.orthogram-maintenance` |
| Script SSH | `/home/u849885333/domains/orthogram.fr/deploy_beta.sh` |
| Stockages privés | `/home/u849885333/domains/orthogram.fr/private/orthogram/{courses,dictations}` |

Ordre réel du script : vérifications préalables de maintenance et stockage → stockage privé et avatars → copie de `.env.beta` dans `.env.local` → remplacement du `.htaccess` racine → suppression de `.env.local.php`, `composer dump-env prod` → caches Symfony → **une commande globale `doctrine:migrations:migrate --env=prod --no-debug --no-interaction`** → vérification des fichiers de cours → réparation des avatars → cache LiipImagine → assets et permissions.

Le script **exige** le témoin et les confirmations opérateur ; il **ne crée pas la maintenance**, n'en prouve pas l'effet HTTP et **ne retire jamais le témoin**, y compris après succès. Il n'importe pas les réserves et n'active pas les quiz. Il reste inchangé dans cette livraison. L'import et l'activation se font donc **après son succès, sous maintenance toujours active**, avant retrait manuel du témoin.

Les migrations et la réparation des avatars sont des écritures distinctes. La comparaison après script signalera aussi une modification d'avatar/utilisateur : l'expliquer avant d'aller plus loin, sans la masquer par une exclusion. Aucun import ni activation n'est ajouté au déploiement ordinaire.

## 2. Valeurs à relever, pas à inventer

`u849885333_orthogram_beta` est le nom **historiquement rapporté le 18 octobre** dans `deploy_beta.md` ; il n'est pas considéré comme la destination actuelle vérifiée. Ne pas le recopier automatiquement. Aucune valeur du poste local n'est une valeur de production.

À relever sur place : accès SSH/port/compte dans hPanel ; branche de publication et SHA effectivement livré ; hôte, port, nom exact et utilisateur MySQL ; `@@hostname` du serveur ; binaire PHP CLI effectivement utilisé par le script et compatibilité PHP web ; chemins des clients MySQL ; URL d'asset existante, alias/racines web et services cron/workers à suspendre. Les chemins du tableau précédent sont connus du dépôt, mais doivent correspondre à l'installation réelle. Le SHA de la future livraison inclura les adaptations encore non committées, pas seulement `9a1f2c0`.

Dans une session SSH Bash autorisée :

```bash
set -euo pipefail
umask 077
DOMAIN=/home/u849885333/domains/orthogram.fr
PROJECT="$DOMAIN/public_html/beta"
test -d "$PROJECT" && test -r "$DOMAIN/.env.beta"
cd "$PROJECT"
PHP_BIN=$(type -P php)
DUMP_BIN=$(type -P mysqldump)
MYSQL_BIN=$(type -P mysql)
test -x "$PHP_BIN" && test -x "$DUMP_BIN" && test -x "$MYSQL_BIN"
"$PHP_BIN" -v
"$DUMP_BIN" --version
composer check-platform-reqs --no-dev
```

Le script utilise `php` du `PATH`, pas un binaire distant codé dans ce document. Exiger PHP ≥ 8.4, les extensions de `composer.json` et PDO MySQL ; vérifier aussi PHP web. Ne pas changer arbitrairement le `PATH` entre ces vérifications et le script.

## 3. Maintenance, identité et sauvegarde avant toute migration/import

```bash
touch "$DOMAIN/.orthogram-maintenance"
read -r -p 'Chemin URL d’un asset bêta existant (commençant par /) : ' ASSET_PATH
[[ "$ASSET_PATH" == /* ]]
for path in / /login /index.php /public/index.php "$ASSET_PATH"; do
  test "$(curl -sS -L -o /dev/null -w '%{http_code}' "https://beta.orthogram.fr$path")" = 503
  test "$(curl -sS -I -L -o /dev/null -w '%{http_code}' "https://beta.orthogram.fr$path")" = 503
done
```

Faire aussi ces contrôles depuis un client extérieur et sur les alias/racines réellement accessibles, notamment les accès `/beta/` et `/beta/public/` s'ils existent. **Un fichier témoin sans 503 effective ne suffit pas : ne pas publier.** Suspendre les workers, cron et autres écritures selon l'installation observée, puis attendre la fin des transactions en cours. Les commandes de suspension ne sont pas inventées ici. Garder le blocage permanent des anciennes URL de médias : une 503 n'en prouve pas l'isolation.

Identifier la connexion effective du code actuellement publié, sans afficher `DATABASE_URL` ni mot de passe :

```bash
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" <<'PHP'
<?php
require 'vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
$kernel = new App\Kernel('prod', false); $kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
$p = $db->getParams();
echo json_encode(['host' => $p['host'] ?? null, 'port' => $p['port'] ?? 3306,
    'effective' => $db->fetchAssociative('SELECT DATABASE() AS db, @@hostname AS server, @@port AS port')], JSON_THROW_ON_ERROR).PHP_EOL;
$kernel->shutdown();
PHP
read -r -p 'Hôte MySQL confirmé avec hPanel : ' DB_HOST
read -r -p 'Port MySQL confirmé : ' DB_PORT
read -r -p 'Nom EXACT de la base bêta confirmée : ' DB_NAME
read -r -p 'Valeur exacte de @@hostname confirmée : ' DB_SERVER
read -r -p 'Utilisateur MySQL autorisé : ' DB_USER
test -n "$DB_HOST" && test -n "$DB_NAME" && test -n "$DB_SERVER" && test -n "$DB_USER"
[[ "$DB_PORT" =~ ^[0-9]+$ ]]
"$MYSQL_BIN" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password --database="$DB_NAME" \
  -e 'SELECT DATABASE(), @@hostname, @@port; SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE();'
```

Rapprocher les deux connexions de la destination indépendante attendue dans hPanel. Les commandes de quiz revérifieront ensuite nom, hôte configuré, port configuré et serveur effectif. Une configuration `unix_socket` explicite ou un port serveur différent du port attendu est refusé : arrêter et analyser la configuration réelle, sans retirer les protections. Si une table n'est pas InnoDB, définir une sauvegarde avec verrouillage approprié avant de poursuivre.

```bash
test "$(realpath "$DOMAIN/private/orthogram")" = "$DOMAIN/private/orthogram"
BACKUP_DIR=$(mktemp -d "$DOMAIN/private/orthogram/backup-quiz-renewal.XXXXXX")
BACKUP="$BACKUP_DIR/beta-before-quiz-renewal.sql"
"$DUMP_BIN" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password \
  --single-transaction --routines --events --triggers --hex-blob --no-tablespaces \
  "$DB_NAME" > "$BACKUP"
test -s "$BACKUP"
grep -q '^-- Dump completed on ' "$BACKUP"
grep -q '^CREATE TABLE `quiz_attempt`' "$BACKUP"
grep -q '^CREATE TABLE `quiz_question`' "$BACKUP"
sha256sum "$BACKUP" > "$BACKUP.sha256"
sha256sum -c "$BACKUP.sha256"
stat -c '%a %s %n' "$BACKUP_DIR" "$BACKUP"
```

Avec `set -e`, un export non nul arrête la procédure. Vérifier aussi toutes les définitions de tables et les INSERT des tables non vides, l'espace disponible et les droits 0700/0600 ; les mots de passe sont saisis au prompt, jamais en argument ni consignés. Adapter une option de client seulement après identification de sa version MySQL/MariaDB et revue explicite ; ne pas accepter de dump partiel. Pas de `--databases` ni de sélection forcée de la base source dans le dump destiné à la restauration d'essai.

Avant migration, valider la restauration dans une **base jetable distincte**, avec un utilisateur limité à celle-ci, conformément à la section 3 de `deploy_beta.md` : import du dump, `mysqlcheck`, comparaison des tables, comptes métier et journal Doctrine. L'empreinte seule prouve l'intégrité du fichier, pas sa restaurabilité. Ne jamais restaurer la base locale complète vers la bêta. Conserver également les sauvegardes d'environnement et médias exigées par le déploiement existant, hors Web. Ne pas placer les preuves sous `public_html`, ni sous `var` du projet : le script modifie ses permissions.

## 4. Publication autorisée puis contrôles avant le script

Seulement lors de la future livraison autorisée : publication de la branche `preprod` selon le mécanisme Hostinger existant, puis attendre sa réussite et la fin de Composer. Aucun push n'est exécuté par cette préparation. Vérifier le SHA livré dans les journaux et par `git rev-parse HEAD` si `.git` existe. Sans `.git`, comparer l'empreinte des fichiers à l'artefact de livraison approuvé ; ne pas remplacer cette preuve par un SHA supposé. Recontrôler les 503 après publication.

Comparer la copie SSH de `deploy_beta.sh` à `ops/deploy_beta.sh` publié avant de l'installer, selon la section 2 de `deploy_beta.md` : sauvegarde privée de l'ancien script, lecture du diff, contrôle `bash -n`, remplacement puis `cmp`. Ne pas écraser une protection distante supplémentaire sans l'examiner. Ne pas lancer `--initial-switch` pour cette livraison de quiz.

Préparer l'environnement effectif depuis `.env.beta` comme dans le déploiement existant, sans changer les choix applicatifs (notamment dictées). Ne pas recopier aveuglément `ORTHOGRAM_DICTATION_WEB_DEFERRED=1` ou un flag apprenant provenant de la livraison d'octobre 18. Conserver leur état opérationnel réellement validé.

```bash
cd "$PROJECT"
test -f "$DOMAIN/.orthogram-maintenance"
bash -n ops/deploy_beta.sh
CHECK_ARGS=("$DOMAIN/.env.beta" "$DOMAIN/private/orthogram/courses" "$DOMAIN/private/orthogram/dictations")
"$PHP_BIN" ops/check_beta_environment.php source "${CHECK_ARGS[@]}"
(umask 077; cp "$DOMAIN/.env.beta" .env.local; rm -f .env.local.php; composer dump-env prod)
"$PHP_BIN" ops/check_beta_environment.php compiled "${CHECK_ARGS[@]}"
"$PHP_BIN" bin/console cache:clear --env=prod --no-debug
"$PHP_BIN" bin/console cache:warmup --env=prod --no-debug
"$PHP_BIN" ops/check_beta_environment.php container "${CHECK_ARGS[@]}"
DEST=(--expect-env=prod "--expect-host=$DB_HOST" "--expect-port=$DB_PORT" "--expect-server=$DB_SERVER" "--expect-database=$DB_NAME")
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" ops/quiz_release_snapshot.php --capture "${DEST[@]}" --output="$BACKUP_DIR/before-migrations.json"
"$PHP_BIN" bin/console doctrine:migrations:status --env=prod --no-debug > "$BACKUP_DIR/migrations-status-before.txt"
"$PHP_BIN" bin/console doctrine:migrations:list --env=prod --no-debug > "$BACKUP_DIR/migrations-list-before.txt"
cat "$BACKUP_DIR/migrations-status-before.txt" "$BACKUP_DIR/migrations-list-before.txt"
```

Le relevé ne charge pas les entités Quiz : il fonctionne avant l'ajout des colonnes du lot 1. Il lit toutes les tables dans une transaction `READ ONLY` et conserve lignes, colonnes et DDL dans un fichier privé. Il peut contenir des données personnelles : ne pas le publier ni le committer.

Migrations de ce chantier à rapprocher du **statut distant** :

| Version | Effet attendu |
| --- | --- |
| `DoctrineMigrations\Version20261025120000` | Ajoute `quiz.renewal_enabled=0`, `quiz.error_question_quota=NULL`, `quiz_question.reserve=0`, `quiz_question.contains_error=NULL`. |
| `DoctrineMigrations\Version20261025150000` | Crée `quiz_reserve_import` vide, clé `(quiz_id, source_key)`, référence unique à la question et contraintes. |

Accepter comme attente seulement le sous-ensemble non exécuté de ces deux versions. **Toute autre migration en attente, inconnue ou indisponible impose un arrêt avant lancement du script global.** Si une version est déjà appliquée, ne pas la rejouer. Si la table/les colonnes existent sans entrée cohérente au journal, arrêter ; ne pas modifier le journal à la main.

```bash
"$PHP_BIN" bin/console doctrine:migrations:migrate --dry-run --env=prod --no-debug --no-interaction \
  > "$BACKUP_DIR/migrations-dry-run.txt" 2>&1
less "$BACKUP_DIR/migrations-dry-run.txt"
```

Examiner tout le SQL, pas seulement le nombre de migrations. Un dry-run peut initialiser le stockage du journal Doctrine : la sauvegarde et le relevé le précèdent. Ici ce journal doit déjà exister ; toute autre préparation imprévue est à examiner. Les deux migrations sont non transactionnelles sous MySQL : un échec peut laisser du DDL partiel ; pas de `down`, réexécution ou restauration automatique.

## 5. Déploiement unique et comparaison des migrations

Après sauvegarde restaurée, statut et SQL approuvés, et seulement lorsque les trois attestations suivantes sont vraies :

```bash
test -f "$DOMAIN/.orthogram-maintenance"
ORTHOGRAM_MAINTENANCE_CONFIRMED=1 \
ORTHOGRAM_PRIVATE_STORAGE_VERIFIED=1 \
ORTHOGRAM_LEGACY_HTTP_BLOCKED=1 \
  "$DOMAIN/deploy_beta.sh" --deploy
test -f "$DOMAIN/.orthogram-maintenance"
"$PHP_BIN" bin/console doctrine:migrations:status --env=prod --no-debug > "$BACKUP_DIR/migrations-status-after.txt"
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" ops/quiz_release_snapshot.php --capture "${DEST[@]}" --output="$BACKUP_DIR/after-migrations.json"
"$PHP_BIN" ops/quiz_release_snapshot.php --before="$BACKUP_DIR/before-migrations.json" --after="$BACKUP_DIR/after-migrations.json" \
  --phase=migration --output="$BACKUP_DIR/compare-migrations.json"
"$PHP_BIN" bin/console dbal:run-sql 'SHOW CREATE TABLE quiz_reserve_import' --env=prod --no-debug
```

**Ne pas ajouter de commandes `doctrine:migrations:execute` : le script a déjà appliqué les versions en attente.** Si aucune n'était en attente, il n'en applique aucune. L'outil de comparaison permet seulement les quatre colonnes avec leurs défauts, la nouvelle table vide et les entrées manquantes des deux versions attendues. Les autres lignes, anciennes colonnes et DDL doivent rester identiques. Si une version était déjà appliquée, son schéma et son journal restent identiques.

Le contrôle échoue aussi sur des écritures annexes du script, par exemple la réparation d'avatars : conserver le rapport et expliquer les différences avant toute étape suivante. Ne pas changer les comparateurs pour ignorer silencieusement des lignes. Une remise en ligne automatique par une copie distante différente du script est une divergence bloquante : maintenir/rétablir la maintenance et réconcilier les scripts avant import.

## 6. Audit et import complémentaire, toujours en mode fixe

```bash
"$PHP_BIN" bin/console app:quiz:module1-reserves --env=prod --no-debug "${DEST[@]}" \
  --report="$BACKUP_DIR/import-preview.json" > "$BACKUP_DIR/import-preview.stdout.json"
```

Examiner `connection`, `destination`, `targets`, les huit `quizzes`, `initial_differences`, `errors`, `existing_copies` et les 94 `copies`. La résolution se fait par titre exact, rattachement unique programme/section/cours et comparaison des 58 contenus initiaux, jamais par les identifiants locaux 1–8. Les identifiants et historiques de production restent ses propres données.

Exiger : aucune erreur/différence ; tailles fondamentaux **10/12/12/22**, module 1 **14/12/12/20** ; huit quiz fixes et quotas à `NULL` ; aucune modification de métadonnées initiales. Le cas initial attendu est 114 initiales, 340 propositions, zéro réserve/trace, ajout de 94 questions et 188 propositions en 16/13/18/47. Un import déjà complet, strictement identique et fixe doit annoncer 208 questions, 528 propositions, 94 traces et zéro ajout. Toute situation partielle, réserve non tracée, collision ou divergence impose une analyse, jamais un écrasement. Des initiales différentes sont décrites dans le rapport et bloquent l'écriture de production, même avec une empreinte fraîche.

Les 47 sources validées sont copiées volontairement dans leur test intermédiaire et le général. `source_key` avec le quiz est l'identité ; le texte seul n'est pas une identité universelle. Aucun ancien SQL de création ni dump local complet n'est utilisé.

Après lecture et validation du plan, capturer l'état immédiatement antérieur à l'import, puis appliquer :

```bash
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" ops/quiz_release_snapshot.php --capture "${DEST[@]}" --output="$BACKUP_DIR/before-import.json"
PLAN_HASH=$("$PHP_BIN" -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if($p["errors"]!==[] || $p["initial_differences"]!==[]) exit(1); echo $p["plan_hash"];' "$BACKUP_DIR/import-preview.json")
"$PHP_BIN" bin/console app:quiz:module1-reserves --env=prod --no-debug "${DEST[@]}" \
  --apply --allow-production-database "--expect-plan=$PLAN_HASH" --report="$BACKUP_DIR/import-result.json"
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" ops/quiz_release_snapshot.php --capture "${DEST[@]}" --output="$BACKUP_DIR/after-import.json"
"$PHP_BIN" ops/quiz_release_snapshot.php --before="$BACKUP_DIR/before-import.json" --after="$BACKUP_DIR/after-import.json" \
  --phase=import --plan="$BACKUP_DIR/import-preview.json" --output="$BACKUP_DIR/compare-import.json"
"$PHP_BIN" bin/console app:quiz:module1-reserves --env=prod --no-debug "${DEST[@]}" \
  --report="$BACKUP_DIR/import-after-preview.json" > "$BACKUP_DIR/import-after-preview.stdout.json"
```

Exiger 208 questions, 114 initiales, 94 réserves, 528 propositions, 94 traces ; aucun ajout proposé, aucune erreur/différence, huit quiz fixes. Comparaison stricte des lignes préexistantes, positions, rattachements, tentatives, snapshots et progressions ; journal Doctrine inchangé. Seuls les compteurs techniques `AUTO_INCREMENT` des tables questions/propositions avancent avec les insertions. Les définitions de schéma restent identiques. Le second appel est une **prévisualisation**, pas un import supplémentaire.

L'empreinte de production inclut l'environnement et la destination exacte. Elle est recalculée sous les verrous des quatre quiz ; une empreinte de recette, d'une autre base ou devenue périmée est refusée. Même un réimport identique en production exige le plan fraîchement recalculé de son état courant. Les appels locaux existants et l'idempotence transactionnelle du service sont conservés.

## 7. Activation distincte, puis contrôles avant réouverture

```bash
"$PHP_BIN" bin/console app:quiz:module1-renewal enable --env=prod --no-debug "${DEST[@]}" \
  --report="$BACKUP_DIR/enable-preview.json" > "$BACKUP_DIR/enable-preview.stdout.json"
```

Vérifier les quatre `targets` du module 1, aucune erreur ni différence initiale, quotas `NULL`, initiales et réserves inchangées. Ne pas activer les fondamentaux. Capturer puis appliquer avec ce nouveau plan :

```bash
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" ops/quiz_release_snapshot.php --capture "${DEST[@]}" --output="$BACKUP_DIR/before-enable.json"
PLAN_HASH=$("$PHP_BIN" -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if($p["errors"]!==[] || $p["initial_differences"]!==[]) exit(1); echo $p["plan_hash"];' "$BACKUP_DIR/enable-preview.json")
"$PHP_BIN" bin/console app:quiz:module1-renewal enable --env=prod --no-debug "${DEST[@]}" \
  --apply --allow-production-database "--expect-plan=$PLAN_HASH" --report="$BACKUP_DIR/enable-result.json"
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" ops/quiz_release_snapshot.php --capture "${DEST[@]}" --output="$BACKUP_DIR/after-enable.json"
"$PHP_BIN" ops/quiz_release_snapshot.php --before="$BACKUP_DIR/before-enable.json" --after="$BACKUP_DIR/after-enable.json" \
  --phase=enable --plan="$BACKUP_DIR/enable-preview.json" --output="$BACKUP_DIR/compare-enable.json"
"$PHP_BIN" ops/check_beta_environment.php container "${CHECK_ARGS[@]}"
"$PHP_BIN" bin/console app:courses:verify-files --env=prod --no-debug
test -f "$DOMAIN/.orthogram-maintenance"
```

Seules les quatre valeurs `quiz.renewal_enabled` passent de 0 à 1. Toutes les autres lignes et les 31 tables si le socle distant correspond au local (sinon l'inventaire réel fait foi) restent identiques. Les tentatives déjà commencées conservent leurs snapshots et réponses. Aucun quota global d'erreurs n'est introduit.

Vérifier les rapports, journaux, migrations, assets, protections des médias et 503 ; la recette complète du moteur a déjà été réalisée sur copie, elle n'exige pas de données de test en production. Après accord opérateur de réouverture :

```bash
rm -- "$DOMAIN/.orthogram-maintenance"
```

Contrôler aussitôt les accès normaux, EasyAdmin (quatre « Avec renouvellement », quatre « Fixe », tailles 14/12/12/20 et fondamentaux conservés, réserves 16/13/18/47), un historique/corrigé existant et les assets. Ne pas créer de tentative de test, modifier de contenu ou reprendre la recette synthétique en production. La consultation web ne peut pas être testée à travers une maintenance 503 active : la distinguer des vérifications CLI préalables. En cas d'échec : `touch "$DOMAIN/.orthogram-maintenance"`, suspendre à nouveau les écritures, puis examiner les faits.

## 8. Retour fonctionnel, retour de code et restauration

La première mesure fonctionnelle est la **désactivation**, conservant les réserves, traces et historiques. Même destination vérifiée, autorisation de production et nouveau plan :

```bash
"$PHP_BIN" bin/console app:quiz:module1-renewal disable --env=prod --no-debug "${DEST[@]}" \
  --report="$BACKUP_DIR/disable-preview.json" > "$BACKUP_DIR/disable-preview.stdout.json"
PLAN_HASH=$("$PHP_BIN" -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if($p["errors"]!==[]) exit(1); echo $p["plan_hash"];' "$BACKUP_DIR/disable-preview.json")
"$PHP_BIN" bin/console app:quiz:module1-renewal disable --env=prod --no-debug "${DEST[@]}" \
  --apply --allow-production-database "--expect-plan=$PLAN_HASH" --report="$BACKUP_DIR/disable-result.json"
```

Lire le plan et confirmer les quatre cibles avant application ; consigner l'état avant/après. Les tentatives en cours continuent sur leurs snapshots ; les suivantes reviennent aux initiales. Une divergence éditoriale est conservée et signalée, pas corrigée par la désactivation.

Un **retour de code** exige une version compatible avec les colonnes et réserves déjà présentes. Une version antérieure au lot 1 risquerait de présenter toutes les questions sans distinction de réserve ; ne pas la republier aveuglément. Aucun `down` destructif des deux migrations.

Une **restauration de base** est un dernier recours distinct : maintenance et arrêt des écritures, analyse des données nouvelles depuis la sauvegarde, restauration d'essai validée dans une autre base, décision explicite. La sauvegarde avant import n'inclut ni les réserves ni les éventuelles tentatives postérieures ; ne pas effacer ces historiques pour désactiver le renouvellement.

## 9. Lecture correcte des rapports locaux et limites de cette préparation

Dans `var/local-reserve-import-20261025/operation.php` et `var/local-renewal-enable-20261025/operation.php`, le champ `browser_preserved_full_database` est fixé à l'expression **`$mode === 'verify-browser'`**. `verify.json` est produit par le mode `verify`, avant la consultation navigateur : son `false` signifie donc **contrôle navigateur pas encore effectué**, pas échec d'une comparaison. Ce champ aurait dû être ternaire/nommé autrement pour éviter l'ambiguïté.

`verify-browser.json` est produit après la recette Chrome et exige l'égalité stricte du relevé final avec `after.json`. Les rapports finaux reprennent ce dernier résultat `true`. Les preuves disponibles concordent : navigateur `success=true`, comparaison `after.json`/`after-browser.json` identique, vérification finale réussie. Les relevés bruts restent inchangés. Le bilan de préparation consigne leurs empreintes et cette interprétation ; aucun échec n'est masqué.

Les tests de préparation utilisent exclusivement des bases MySQL jetables. Les essais de l'autorisation `prod` utilisent ces mêmes bases locales jetables avec le contexte de commande `prod` ; l'hôte distant est couvert par une connexion simulée, sans réseau. Cela vérifie le mécanisme, **pas** les identifiants, droits, environnement PHP ni données Hostinger.

Avant exécution distante restent à acquérir : autorisation de livraison, SHA incluant ces adaptations, destination hPanel effective, copie SSH du script, maintenance effective sur tous les accès, disponibilité/compatibilité PHP et MySQL, état réel des migrations et données, sauvegarde restaurable et contrôle web final. Aucun de ces points n'est déclaré validé à distance par les tests locaux.

Résultats et empreintes : [rapport de préparation](quiz_renewal_production_report.json). Les 22 tests ciblés passent (697 assertions), puis le contrôle final du comparateur avec son script CLI passe (1 test, 14 assertions), ainsi que les quatre tests finaux de destination. Les 33 scénarios du contrat de déploiement et la syntaxe des 13 blocs Bash sont validés. PHPStan conserve exactement ses 34 diagnostics antérieurs, sans exclusion ajoutée. Le relevé en lecture seule confirme que la base de travail et `.env.local` sont inchangés. La recette navigateur complète et la suite applicative générale n'ont pas été rejouées.

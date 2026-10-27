# Module 2 — import et renouvellement sur bêta

Préparation du 27 octobre 2026 sur `preprod`. La recette locale utilisateur est entièrement validée : renouvellement, tailles 12/12/24, reprise, bilans et historiques. Voir les bilans [d’import](module2_local_import.md) et [d’activation locale](module2_local_renewal.md). Les valeurs et empreintes de ces bilans ne sont **pas** celles de bêta.

Ce lot prépare le code et cette procédure. Aucun accès Hostinger, push, déploiement, import ou activation distant n’est effectué. Aucune donnée de `orthogram` locale n’est modifiée. Les étapes Bash ci-dessous seront exécutées avec l’utilisateur lors d’une livraison autorisée. Arrêter à toute erreur, conserver les preuves et la maintenance ; aucun rattrapage automatique des divergences.

## Contrat de sécurité et compatibilité

`app:quiz:module2-import` prévisualise par défaut ; les nouveaux quiz sont fixes, quotas `NULL`. `app:quiz:module2-renewal enable|disable` est une opération ultérieure distincte sur les seules clés `module2_test1`, `module2_test2`, `module2_general`. Les ID viennent de `quiz_manifest_identity`, jamais des ID locaux 9/10/11 ni des seuls titres. Un tombstone, une collision, une trace absente, un import partiel ou une différence de contenu interdit l’écriture ; aucune adoption ou réparation forcée.

En production, aperçu compris, exiger `--expect-env=prod` et les quatre assertions base/hôte/port/serveur. Toute écriture, même sans effet, exige `--apply --allow-production-database --expect-plan=…`. `--allow-work-database` est refusé en prod, même accompagné du consentement production. Hors prod, les destinations distantes restent interdites ; les règles `orthogram`/dev et bases jetables/test restent inchangées. Le service revérifie la connexion effective et le consentement, puis recalcule le plan sous verrou. Aucun changement des commandes du module 1 ni du moteur de sélection.

Points MariaDB examinés :

- `Version20261027120000` crée uniquement `quiz_manifest_identity`, InnoDB, PK sur la clé, unicité de `quiz_id`, FK nullable vers `quiz` avec `ON DELETE SET NULL`. DDL non transactionnel : ne pas supposer qu’un échec de migration est annulé.
- `GET_LOCK` est un verrou de connexion, indépendant du commit ; `RELEASE_LOCK` reste exécuté dans `finally`. Nom propre à la base, puis transaction et verrous pessimistes Doctrine ordonnés sur les quiz. La recette exerce deux processus concurrents. [Référence MariaDB](https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/get_lock).
- `SET TRANSACTION READ ONLY` porte sur la prochaine transaction ; l’aperçu se termine par rollback. [Référence MariaDB](https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/set-commands/set-transaction).
- MariaDB représente JSON par LONGTEXT validé. L’importeur compare les champs métier décodés et les empreintes canoniques PHP, sans égalité SQL de documents JSON ni dépendance au stockage binaire MySQL. Les captures de conservation comparent les valeurs brutes sur **le même moteur**, y compris snapshots et résultats ; ne pas comparer un dump MySQL à MariaDB pour prétendre à une égalité octet à octet. [Référence MariaDB](https://mariadb.com/docs/server/reference/data-types/string-data-types/json).

La version bêta, les droits et son état réel ne sont pas connus de cette préparation. Relever `VERSION()` et la plateforme Doctrine effectivement sélectionnée avant livraison. Un `serverVersion` MySQL dans une connexion MariaDB est une divergence à résoudre avant de poursuivre. La recette locale et ses limites sont consignées à la fin du document.

## 1. Identifier le code, les outils et la destination

Les chemins suivants proviennent du script versionné et des procédures existantes [déploiement](deploy_beta.md) et [module 1](quiz_renewal_production.md). Les vérifier sur place. Aucun nouveau nom de base, slug ou identifiant n’est présumé.

```bash
set -euo pipefail
umask 077
DOMAIN=/home/u849885333/domains/orthogram.fr
PROJECT="$DOMAIN/public_html/beta"
MAINTENANCE="$DOMAIN/.orthogram-maintenance"
cd "$PROJECT"
read -r -p 'SHA approuvé de la future livraison : ' RELEASE_SHA
read -r -p 'Binaire PHP CLI vérifié : ' PHP_BIN
read -r -p 'Client MariaDB vérifié : ' MYSQL_BIN
read -r -p 'Client mariadb-dump compatible : ' DUMP_BIN
read -r -p 'Client mariadb-check compatible : ' CHECK_BIN
test -n "$RELEASE_SHA"
"$PHP_BIN" --version
"$MYSQL_BIN" --version
"$DUMP_BIN" --version
composer check-platform-reqs --no-dev
if test -d .git; then git status --short --branch; git rev-parse HEAD; fi
```

Consigner le SHA actuellement déployé et celui approuvé pour livraison. Sans `.git`, utiliser les journaux de publication et les empreintes de l’artefact approuvé. Vérifier PHP CLI **et** PHP web, les dépendances de `composer.lock`, la branche de publication `preprod` dans hPanel et la copie SSH du script. Ne jamais afficher `.env.beta`, `.env.local.php`, `DATABASE_URL`, `printenv` ou un diagnostic Symfony révélant les secrets.

Identifier la connexion ouverte par Doctrine, pas seulement l’URL de configuration :

```bash
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" <<'PHP'
<?php
require 'vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
$kernel = new App\Kernel('prod', false); $kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
$p = $db->getParams();
echo json_encode(['environment' => $kernel->getEnvironment(), 'host' => $p['host'] ?? null,
    'configured_port' => $p['port'] ?? 3306, 'explicit_socket' => !empty($p['unix_socket']),
    'platform' => get_class($db->getDatabasePlatform()),
    'effective' => $db->fetchAssociative('SELECT DATABASE() AS db, @@hostname AS server, @@port AS port, VERSION() AS version')], JSON_THROW_ON_ERROR).PHP_EOL;
$kernel->shutdown();
PHP
read -r -p 'Base bêta confirmée indépendamment dans hPanel : ' DB_NAME
read -r -p 'Hôte effectif confirmé : ' DB_HOST
read -r -p 'Port effectif confirmé : ' DB_PORT
read -r -p '@@hostname confirmé : ' DB_SERVER
read -r -p 'Compte MariaDB autorisé : ' DB_USER
test -n "$DB_NAME" && test -n "$DB_HOST" && test -n "$DB_SERVER" && test -n "$DB_USER"
[[ "$DB_PORT" =~ ^[1-9][0-9]{0,4}$ ]]
DEST=(--expect-env=prod "--expect-database=$DB_NAME" "--expect-host=$DB_HOST" "--expect-port=$DB_PORT" "--expect-server=$DB_SERVER")
SOURCE=(--host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password)
"$MYSQL_BIN" "${SOURCE[@]}" --database="$DB_NAME" -e 'SELECT DATABASE(), @@hostname, @@port, VERSION(); SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE();'
```

Le nom `u849885333_orthogram_beta` n’est qu’un relevé historique du 18 octobre dans la procédure existante ; ne pas le présumer actuel. Arrêter si la destination ne correspond pas à hPanel, si un socket explicite est configuré ou si les ports divergent. Toutes les tables métier doivent être InnoDB ; sinon arrêter et préparer un traitement distinct. Inventorier aussi vues, routines, événements et triggers, leurs DEFINER et éventuelles références à une autre base.

## 2. Maintenance et sauvegarde restaurée avant publication/script

```bash
touch "$MAINTENANCE"
read -r -p 'URL HTTPS d’un asset réellement présent : ' ASSET_URL
for url in https://beta.orthogram.fr/ https://beta.orthogram.fr/login https://beta.orthogram.fr/index.php https://beta.orthogram.fr/public/index.php "$ASSET_URL"; do
  test "$(curl -sS -L -o /dev/null -w '%{http_code}' "$url")" = 503
  test "$(curl -sS -I -L -o /dev/null -w '%{http_code}' "$url")" = 503
done
```

Répéter depuis un client extérieur, sur tous les accès/alias réellement configurés. Suspendre workers, cron, formulaires et autres écritures ; attendre la fin des transactions en cours. Le témoin seul ne prouve pas la maintenance. Conserver l’isolation permanente des médias privés.

Créer un répertoire privé **hors `public_html`, hors Git et hors `var` du projet**, que le script passe en permissions 775. Les captures contiennent des données personnelles.

```bash
test "$(realpath "$DOMAIN/private/orthogram")" = "$DOMAIN/private/orthogram"
BACKUP_DIR=$(mktemp -d "$DOMAIN/private/orthogram/module2-release.XXXXXX")
BACKUP="$BACKUP_DIR/before.sql"
DUMP_OPTIONS=(--single-transaction --routines --events --triggers --hex-blob --no-tablespaces --skip-comments --skip-dump-date --skip-extended-insert --order-by-primary)
"$DUMP_BIN" "${SOURCE[@]}" "${DUMP_OPTIONS[@]}" "$DB_NAME" > "$BACKUP"
test -s "$BACKUP"
sha256sum "$BACKUP" > "$BACKUP.sha256"
sha256sum -c "$BACKUP.sha256"
stat -c '%a %s %n' "$BACKUP_DIR" "$BACKUP"
cp -p "$DOMAIN/.env.beta" "$BACKUP_DIR/env.beta"
tar -cf "$BACKUP_DIR/private-media.tar" -C "$DOMAIN/private/orthogram" courses dictations
tar -tf "$BACKUP_DIR/private-media.tar" > "$BACKUP_DIR/private-media.list"
```

Vérifier les codes retour, droits 0700/0600, espace disponible et inventaire de toutes les tables/définitions. Le mot de passe est saisi au prompt, jamais en argument. Ne pas utiliser `--databases` : le dump d’essai ne doit ni créer ni sélectionner la base source. Ne pas accepter de dump partiel en supprimant une option ou en ignorant un refus de privilège. Sauvegarder aussi avatars et configuration selon le déploiement existant.

Provisionner une **base temporaire vide distincte**, idéalement sur un serveur isolé de même version MariaDB, avec un compte limité à cette base et événements non exécutés. Relever ses paramètres, sans changer `.env.beta`. Examiner le dump pour exclure tout `USE`, DDL ou référence qualifiée visant la source ; les vues/routines/triggers demandent une revue particulière. Le compte de restauration ne doit pas pouvoir écrire dans la source.

```bash
read -r -p 'Hôte de restauration isolé : ' RESTORE_HOST
read -r -p 'Port de restauration : ' RESTORE_PORT
read -r -p 'Base temporaire nouvellement créée pour ce contrôle : ' RESTORE_DB
read -r -p 'Compte limité à cette base temporaire : ' RESTORE_USER
test -n "$RESTORE_DB" && test "$RESTORE_DB" != "$DB_NAME"
RESTORE=(--host="$RESTORE_HOST" --port="$RESTORE_PORT" --user="$RESTORE_USER" --password)
"$MYSQL_BIN" "${RESTORE[@]}" --database="$RESTORE_DB" -e 'SELECT DATABASE(),@@hostname,@@port,VERSION(); SHOW FULL TABLES;'
# Exiger une base vide et confirmer son identité avant cette seule restauration.
"$MYSQL_BIN" "${RESTORE[@]}" --database="$RESTORE_DB" < "$BACKUP"
"$CHECK_BIN" "${RESTORE[@]}" --check "$RESTORE_DB"
"$DUMP_BIN" "${RESTORE[@]}" "${DUMP_OPTIONS[@]}" "$RESTORE_DB" > "$BACKUP_DIR/restored.sql"
diff -u "$BACKUP" "$BACKUP_DIR/restored.sql" > "$BACKUP_DIR/restore.diff"
sha256sum -c "$BACKUP.sha256"
```

Avec le même client et le même moteur, cette comparaison déterministe contrôle structures et données, y compris quiz, cours, tentatives, snapshots JSON, résultats, progressions et registre Doctrine. Une différence arrête la procédure : comparer tables, colonnes, index/FK, routines/événements/triggers, comptages et lignes ; documenter toute différence purement technique de représentation avant une nouvelle vérification approuvée. Ne jamais retirer des tables métier du contrôle. Un `mysqlcheck` ou une empreinte seuls ne valident pas la restauration. Conserver export, empreinte, preuve de restauration et paramètres non secrets ; le nettoyage de la base temporaire attend la fin de l’intervention.

## 3. Publication future, migrations et script de déploiement

**Comportement inspecté :** `deploy_beta.sh` ne publie pas Git et ne crée ni ne retire la maintenance. Il vérifie les stockages, copie `.env.beta`, compile l’environnement, vide/réchauffe les caches, exécute **globalement `doctrine:migrations:migrate`**, vérifie les fichiers de cours, répare les avatars, compile les assets et modifie les permissions. Les écritures d’avatars peuvent donc apparaître dans la comparaison de conservation. Aucun import ni activation module 2 n’est ajouté à ce script.

Après sauvegarde restaurée seulement, effectuer la future publication autorisée de `preprod` selon [deploy_beta.md](deploy_beta.md), attendre la réussite de Composer et vérifier le SHA livré égal à `RELEASE_SHA`. Recontrôler les 503. Comparer la copie SSH `$DOMAIN/deploy_beta.sh` au fichier publié, conserver son ancienne version en privé et examiner le diff avant remplacement ; ne pas écraser une protection distante supplémentaire. Utiliser `--deploy`, pas `--initial-switch`.

Préparer l’environnement CLI de la nouvelle version conformément au script existant, sans modifier les choix de dictées ou d’autres parcours :

```bash
cd "$PROJECT"
test -f "$MAINTENANCE"
CHECK_ARGS=("$DOMAIN/.env.beta" "$DOMAIN/private/orthogram/courses" "$DOMAIN/private/orthogram/dictations")
"$PHP_BIN" ops/check_beta_environment.php source "${CHECK_ARGS[@]}"
cp "$DOMAIN/.env.beta" .env.local
rm -f -- .env.local.php
composer dump-env prod
"$PHP_BIN" ops/check_beta_environment.php compiled "${CHECK_ARGS[@]}"
```

Protéger les fichiers d’environnement comme dans la procédure existante et refaire l’identification de la connexion effective de la section 1. Vérifier aussi que `php` trouvé dans le PATH du script correspond à `PHP_BIN`. Ne pas recopier un ancien flag de livraison de dictées. Les surcharges d’environnement doivent être cohérentes avec `.env.beta` et PHP web.

Le script applique **toutes** les migrations en attente : examiner leur liste et leur SQL, pas uniquement la migration module 2.

```bash
"$PHP_BIN" bin/console doctrine:migrations:status --env=prod --no-debug > "$BACKUP_DIR/migrations-before.txt"
"$PHP_BIN" bin/console doctrine:migrations:list --env=prod --no-debug > "$BACKUP_DIR/migrations-list.txt"
"$PHP_BIN" bin/console doctrine:migrations:migrate --dry-run --env=prod --no-debug --no-interaction > "$BACKUP_DIR/migrations-dry-run.txt"
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" ops/quiz_release_snapshot.php --capture "${DEST[@]}" --output="$BACKUP_DIR/before-deploy.json"
bash -n ops/deploy_beta.sh
cmp ops/deploy_beta.sh "$DOMAIN/deploy_beta.sh"
```

Prérequis : colonnes de renouvellement/réserves et `quiz_reserve_import` des versions `20261025120000` et `20261025150000`, avec leurs schémas conformes. Pour ce lot, seule `DoctrineMigrations\Version20261027120000` est nouvelle. Si d’autres versions sont en attente, **arrêter avant le script global**, les examiner et les faire traiter/valider comme un lot distinct ; ne pas les appliquer silencieusement. Si la version module 2 est déjà enregistrée, vérifier le schéma et ne pas la réexécuter. Table présente mais version absente, ou inversement : arrêt et diagnostic de DDL partiel.

Après accord sur le SQL et seulement si les attestations sont réellement acquises :

```bash
ORTHOGRAM_MAINTENANCE_CONFIRMED=1 \
ORTHOGRAM_PRIVATE_STORAGE_VERIFIED=1 \
ORTHOGRAM_LEGACY_HTTP_BLOCKED=1 \
  "$DOMAIN/deploy_beta.sh" --deploy
test -f "$MAINTENANCE"
"$PHP_BIN" bin/console doctrine:migrations:status --env=prod --no-debug > "$BACKUP_DIR/migrations-after.txt"
"$PHP_BIN" bin/console dbal:run-sql 'SHOW CREATE TABLE quiz_manifest_identity' --env=prod --no-debug > "$BACKUP_DIR/identity-schema.txt"
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" ops/quiz_release_snapshot.php --capture "${DEST[@]}" --output="$BACKUP_DIR/after-deploy.json"
"$PHP_BIN" ops/module2_quiz_compare.php --phase=migration --before="$BACKUP_DIR/before-deploy.json" --after="$BACKUP_DIR/after-deploy.json" --output="$BACKUP_DIR/compare-deploy.json"
```

**Ne pas ajouter `doctrine:migrations:execute` après le script.** Vérifier le PK, l’unicité, la FK `ON DELETE SET NULL`, les types et InnoDB contre `Version20261027120000`. Le comparateur permet uniquement l’ajout de la table vide et de cette entrée du journal ; les autres données/structures doivent rester identiques. Il signale aussi une réparation d’avatar : diagnostiquer cette différence et obtenir une revue explicite avant import ; ne pas élargir les exclusions pour faire passer le contrôle. Les captures initiales restent conservées.

## 4. Prévisualisation puis import fixe

```bash
"$PHP_BIN" bin/console app:quiz:module2-import --env=prod --no-debug "${DEST[@]}" --report="$BACKUP_DIR/import-preview.json"
```

Lire intégralement le plan : destination, SHA du manifeste, `schema_missing=[]`, `errors=[]`, `differences=[]`, trois clés et répartition 12/12/24 initiales et 19/24/86 réserves. Nouveau lot attendu : `add_quizzes=3`, `add_questions=177`, `add_answers=354`, `existing_copies=0`. Six sources en attente exclues. Aucun cours créé/rattaché. Un lot déjà complet doit annoncer zéro ajout et 177 copies conformes ; son état de renouvellement est conservé, jamais remis à zéro par réimport. Arrêter si cet état ne correspond pas à l’intervention prévue.

Après revue, relever l’empreinte de **ce plan bêta** :

```bash
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" ops/quiz_release_snapshot.php --capture "${DEST[@]}" --output="$BACKUP_DIR/before-import.json"
IMPORT_PLAN=$("$PHP_BIN" -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if($p["errors"]!==[] || $p["differences"]!==[]) exit(1); echo $p["plan_hash"];' "$BACKUP_DIR/import-preview.json")
"$PHP_BIN" bin/console app:quiz:module2-import --env=prod --no-debug "${DEST[@]}" --apply --allow-production-database "--expect-plan=$IMPORT_PLAN" --report="$BACKUP_DIR/import-result.json"
"$PHP_BIN" bin/console app:quiz:module2-import --env=prod --no-debug "${DEST[@]}" --report="$BACKUP_DIR/import-after.json"
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" ops/quiz_release_snapshot.php --capture "${DEST[@]}" --output="$BACKUP_DIR/after-import.json"
"$PHP_BIN" ops/module2_quiz_compare.php --phase=import --plan="$BACKUP_DIR/import-after.json" --before="$BACKUP_DIR/before-import.json" --after="$BACKUP_DIR/after-import.json" --output="$BACKUP_DIR/compare-import.json"
"$PHP_BIN" bin/console dbal:run-sql "SELECT i.quiz_key,i.quiz_id,q.renewal_enabled,q.error_question_quota,(SELECT COUNT(*) FROM quiz_question x WHERE x.quiz_id=q.id AND x.reserve=0) AS initial_count,(SELECT COUNT(*) FROM quiz_question x WHERE x.quiz_id=q.id AND x.reserve=1) AS reserve_count,(SELECT COUNT(*) FROM quiz_answer a JOIN quiz_question x ON x.id=a.question_id WHERE x.quiz_id=q.id) AS answers,(SELECT COUNT(*) FROM quiz_reserve_import t WHERE t.quiz_id=q.id) AS traces FROM quiz_manifest_identity i JOIN quiz q ON q.id=i.quiz_id WHERE i.quiz_key IN ('module2_test1','module2_test2','module2_general') ORDER BY i.quiz_key" --env=prod --no-debug > "$BACKUP_DIR/import-volumes.txt"
```

Exiger **3 quiz, 177 questions, 354 propositions, 177 traces, 3 identités** pour ce module uniquement ; 48 initiales +129 réserves, tailles 12/12/24, mode fixe et quotas `NULL`. Consigner les **ID bêta effectivement attribués**. La prévisualisation doit annoncer zéro ajout et aucune divergence. Le comparateur contrôle toutes les anciennes lignes, y compris module 1/fondamentaux, cours, tentatives, snapshots, résultats et progressions. Seuls les compteurs techniques d’insertion peuvent avancer. Un second import n’est pas nécessaire : l’idempotence est testée sur bases jetables.

## 5. Rattachements EasyAdmin et activation séparée

Les rattachements locaux sont des données : **Git ne les transfère pas**. Aucun SQL d’attachement automatique. Dans EasyAdmin **Cours**, choisir **Type de contenu : Quiz**, puis le **Quiz** par son titre et l’ID bêta résolu par traçabilité. Relever les vrais cours/sections/slugs sur bêta, vérifier l’ordre et l’absence de doublon ; ne pas copier les ID locaux de cours ou de quiz.

| Identité et titre | Emplacement pédagogique validé |
| --- | --- |
| `module2_test1` — Test : écrire les nombres et les expressions courantes | Après l’exercice « plutôt / plus tôt », avant le cours « on / on n’ » |
| `module2_test2` — Test : distinguer les formes et les terminaisons | Après l’exercice des adverbes en « -amment / -emment », avant le cours « d’en / dans » |
| `module2_general` — Test général : réviser le module 2 | Après l’exercice sur tout adverbe, avant la dictée finale |

Une maintenance HTTP 503 bloque aussi EasyAdmin. Pour cette étape, établir avec l’utilisateur un accès administrateur contrôlé maintenant le blocage des apprenants/écritures concurrentes, ou une fenêtre de réouverture surveillée. Aucun mécanisme de contournement n’est supposé installé. Si une fenêtre est nécessaire : relever son début, retirer le seul témoin après accord opérateur, effectuer les rattachements, remettre le témoin, revérifier les 503 et l’arrêt des écritures. Conserver les éventuelles nouvelles données légitimes ; refaire la sauvegarde/restauration de la section 2 dans un **nouveau répertoire** et les relevés avant activation. Ne jamais comparer à l’ancien relevé en supprimant ces nouvelles lignes.

Capturer séparément avant/après rattachement avec `ops/quiz_release_snapshot.php`, examiner les différences de cours attendues et leurs métadonnées ; module 1, fondamentaux et historiques ne doivent pas avoir été édités. Les conserver comme preuves, sans appliquer le comparateur d’import à une modification manuelle de cours. Garder les trois quiz fixes, quotas `NULL`, jusqu’à cette activation explicite :

```bash
"$PHP_BIN" bin/console app:quiz:module2-renewal enable --env=prod --no-debug "${DEST[@]}" --report="$BACKUP_DIR/enable-preview.json"
```

Exiger les trois identités résolues, tailles 12/12/24, quotas `NULL`, contenus/réserves conformes, aucune erreur ni différence, zéro ajout. `renewal_changes` doit contenir uniquement les trois `renewal_enabled: false → true`. Si les quiz sont déjà actifs ou divergent, arrêter et comprendre l’état ; ne pas forcer. Après revue du nouveau plan :

```bash
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" ops/quiz_release_snapshot.php --capture "${DEST[@]}" --output="$BACKUP_DIR/before-enable.json"
ENABLE_PLAN=$("$PHP_BIN" -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if($p["errors"]!==[] || $p["differences"]!==[] || count($p["renewal_changes"])!==3) exit(1); foreach($p["quizzes"] as $q) if($q["error_question_quota"]!==null || $q["renewal_enabled"]!==false) exit(1); echo $p["plan_hash"];' "$BACKUP_DIR/enable-preview.json")
"$PHP_BIN" bin/console app:quiz:module2-renewal enable --env=prod --no-debug "${DEST[@]}" --apply --allow-production-database "--expect-plan=$ENABLE_PLAN" --report="$BACKUP_DIR/enable-result.json"
APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" ops/quiz_release_snapshot.php --capture "${DEST[@]}" --output="$BACKUP_DIR/after-enable.json"
"$PHP_BIN" ops/module2_quiz_compare.php --phase=enable --plan="$BACKUP_DIR/enable-preview.json" --before="$BACKUP_DIR/before-enable.json" --after="$BACKUP_DIR/after-enable.json" --output="$BACKUP_DIR/compare-enable.json"
"$PHP_BIN" bin/console app:quiz:module2-renewal enable --env=prod --no-debug "${DEST[@]}" --report="$BACKUP_DIR/enable-after.json"
```

Seuls les trois indicateurs changent ; tous les quotas, contenus, positions, thèmes, traces, rattachements, tentatives, snapshots, résultats, progressions et données module 1/fondamentaux restent identiques. Dernier aperçu : actifs, quotas `NULL`, zéro ajout, `renewal_changes=[]`, aucune erreur/divergence. Le comparateur conserve les valeurs JSON brutes et n’ignore aucune table d’historique.

## 6. Réouverture, recette et nettoyage

Avant réouverture : examiner les rapports et journaux, vérifier les migrations, l’environnement effectif, les fichiers de cours et assets, l’isolation des médias et les 503. Faire approuver le bilan d’import/activation avec les ID réellement attribués et les rattachements. Après accord :

```bash
"$PHP_BIN" ops/check_beta_environment.php container "${CHECK_ARGS[@]}"
"$PHP_BIN" bin/console app:courses:verify-files --env=prod --no-debug
test -f "$MAINTENANCE"
rm -- "$MAINTENANCE"
```

Vérifier immédiatement accès normaux, assets et EasyAdmin : trois quiz avec renouvellement, tailles 12/12/24, réserves 19/24/86 et quotas `NULL`, bons emplacements, module 1/fondamentaux inchangés. Consulter un historique existant. Avec l’utilisateur, effectuer ensuite la recette navigateur autorisée sur chaque quiz : premier passage initial, « Recommencer » avec renouvellement, interruption/reprise identique, bilan/complétion et conservation du bilan précédent. Ne pas créer de tentatives artificielles par script en production. Les passages navigateur créent des données légitimes, à distinguer des relevés de conservation antérieurs. Le général réutilise au moins une question du thème « tout » ; aucune promesse de parcourir toutes les réserves.

En cas d’anomalie : rétablir `touch "$MAINTENANCE"`, interrompre les écritures et diagnostiquer. Conserver les sauvegardes, empreintes et rapports privés. Supprimer **uniquement** la base temporaire de restauration créée et consignée pour cette intervention, après nouvelle vérification nom/hôte/serveur et accord opérateur ; utiliser hPanel ou le compte de provisionnement limité. Ne pas supprimer les bases préexistantes, sauvegardes, médias ou historiques. Aucun nettoyage global ou par joker.

## Diagnostic et reprise

- Destination/consentement refusé : relire la connexion effective et les assertions. Ne pas transformer `--allow-work-database` en autorisation de prod ni retirer un contrôle d’hôte/port.
- Migration échouée : conserver le SQL, l’erreur, le registre et `SHOW CREATE TABLE`. Du DDL peut être engagé malgré l’échec. Ne pas rejouer aveuglément, marquer une migration exécutée ou lancer un `down` ; préparer une correction ciblée après diagnostic.
- Collision, import partiel, trace ou contenu divergent : conserver le diff du plan ; aucune adoption, suppression ou réimport forcé. Examiner aussi les transactions et tentatives récentes.
- Plan périmé/verrou indisponible : identifier l’opération concurrente, attendre sa fin, refaire un aperçu et une revue. Ne jamais réutiliser l’ancienne empreinte. Après erreur technique, capturer et prévisualiser pour déterminer si le commit SQL a eu lieu, notamment si seule l’écriture du rapport a échoué.
- Retour fonctionnel : prévisualiser `app:quiz:module2-renewal disable` avec les mêmes assertions, examiner son propre plan, capturer, puis appliquer avec `--allow-production-database` et cette empreinte ; comparer avec `--phase=disable`. Cette opération conserve contenus et historiques. Elle refuse elle aussi les divergences ; elle n’est pas un outil de réparation.
- Retour de code : choisir une version compatible avec les réserves et le schéma présent. Pas de retour aveugle à une version qui afficherait toutes les questions.
- Restauration globale : dernier recours distinct, jamais automatique. Arrêt des écritures, inventaire des données plus récentes que la sauvegarde, restauration d’essai distincte et décision explicite sur leur conservation. Désactiver le renouvellement n’exige pas d’effacer les nouvelles tentatives.

## Recette reproductible et résultats de préparation

Les tests n’utilisent jamais `orthogram`. Ils créent puis suppriment leurs propres bases `orthogram_module2_<16hex>_test`. Les essais de contexte prod utilisent ces mêmes bases locales jetables ; les hôtes distants sont simulés. La première exécution MariaDB a mis en évidence le défaut MyISAM de Wamp : les sessions du support de test demandent maintenant InnoDB, sans changer le serveur. L’installation échouée est nettoyée même en cas d’erreur de `setUp`.

Pour rejouer sur une série MariaDB identique à celle relevée en SSH, utiliser un serveur local isolé, PHP 8.4 et les dépendances de développement du lock. Fournir `DATABASE_URL` au seul processus de test (ne jamais modifier `.env.beta` ou pointer les tests vers Hostinger), avec hôte loopback, port observé, compte de recette et `serverVersion` MariaDB exact. Le nom configuré n’est pas la base de travail ; le support crée sa propre base aléatoire avant tout DDL/DML. Ne pas mettre une URL contenant un secret dans l’historique shell ou les rapports.

```bash
# DATABASE_URL est déjà fourni au processus par la configuration privée de recette.
MODULE2_RECIPE_REPORTS=0 php -d memory_limit=512M -d xdebug.mode=off vendor/bin/phpunit tests/Services/ModuleTwoQuizDestinationTest.php tests/Integration/ModuleTwoQuizImportTest.php tests/Services/ModuleTwoQuizManifestTest.php
MODULE2_RECIPE_REPORTS=0 php -d memory_limit=512M -d xdebug.mode=off vendor/bin/phpunit --filter 'ModuleOne|ModuleTwo|Quiz'
```

Résultats réellement obtenus, PHP **8.4.27**, bases exclusivement jetables :

| Contrôle | Résultat |
| --- | --- |
| MariaDB **11.4.9**, port local 3307 : module 2 et persistance/concurrence des tentatives (`QuizRenewalPersistenceTest`), dernière exécution isolée | **118 tests, 729 assertions**, aucun échec |
| MySQL **8.4.7** : non-régression `ModuleOne\|ModuleTwo\|Quiz` | **331 tests, 3 353 assertions**, aucun échec |
| MySQL : contrôle final supplémentaire du comparateur et de son CLI, après ajout des assertions CLI | **1 test, 44 assertions**, aucun échec |
| PHPStan niveau 6 sur les neuf fichiers PHP du chantier ; `lint:container` ; PHP CS Fixer | Conformes |
| Contrat du déploiement existant (`python tests/ops/deploy_beta_test.py`) | **33 scénarios réussis** ; permissions POSIX réelles non exercées sous Windows/MSYS, cibles/modes des commandes contrôlés |
| Syntaxe Bash de cette procédure ; cinq empreintes du dossier éditorial ; `git diff --check` | **14 blocs** valides ; empreintes inchangées ; conforme |

La recette couvre le refus sans consentement, les assertions manquantes/incorrectes et l’interdiction du consentement local en prod ; les appels directs au service revérifient aussi l’autorisation. Migration réelle, collisions, divergences, plans périmés, rollback après insertion, concurrence de deux importeurs, ID décalés, réimport sans effet, activation/désactivation séparées et conservation des données préexistantes sont exercés. Le comparateur contrôle les anciennes lignes de toutes les tables ; son test refuse une modification étrangère dans chaque table et un changement de quota lors de l’activation. Son CLI refuse d’écraser un rapport existant.

**Limites et essais non réussis conservés :** le premier essai MariaDB a échoué pendant la création des fixtures MyISAM ; les 22 bases vides créées par cet essai ont été contrôlées puis supprimées individuellement. Après correction des seules sessions de recette, les tests ciblés passent. Une extension exploratoire de toute la suite quiz sur MariaDB a donné 331 tests, 3 300 assertions, **deux échecs** : `QuizProductionCommandTest::testProductionRefusalsNeverWrite` utilise littéralement `3307` comme mauvais port (c’est ici le bon port), et le worker de `QuizRenewalPersistenceTest` a rencontré une collision de génération du cache Symfony lors d’exécutions simultanées. Le test module 1 est conservé inchangé ; son résultat ne valide donc pas la suite générale entière sur ce port MariaDB. La persistance/concurrence a été rejouée isolément et passe dans les 118 tests ci-dessus. Ne pas lancer plusieurs suites partageant `var/cache/test` simultanément.

Rapports locaux non versionnés : `var/module2-production-mariadb-final.xml`, `var/module2-production-mysql-regression.xml`, `var/module2-production-mysql-audit.xml` ; l’essai MariaDB élargi non réussi reste dans `var/module2-production-mariadb-regression.xml`. Le lanceur `py` ne trouvait pas d’installation ; les contrôles Python ont été exécutés avec `python` **3.11.9**. Aucun secret, export SQL ou relevé de données réelles n’est ajouté au commit.

La série MariaDB de bêta reste à relever en SSH ; 11.4.9 local n’est pas présenté comme une validation de sa version exacte. Aucun résultat local ne valide les privilèges, le stockage, les données ou le déploiement Hostinger. Le script de déploiement, les migrations, les commandes du module 1 et le dossier éditorial sont inchangés ; seules les protections module 2, les preuves de conservation et leur documentation évoluent.

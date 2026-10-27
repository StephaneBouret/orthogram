# Bêta — livraison des dictées

Pour le module 2, suivre la [procédure d’import et d’activation séparée](module2_quiz_production.md). Elle place la sauvegarde restaurée et la revue de toutes les migrations en attente avant le script global ; aucun import ni renouvellement n’est ajouté au déploiement ordinaire.

Pour la livraison du renouvellement des quiz, suivre aussi la [procédure dédiée](quiz_renewal_production.md) : audit des deux migrations du chantier avant le `migrate` global déjà présent, import et activation distincts sous maintenance conservée. Aucun import ou activation de quiz n'est ajouté au script ordinaire.

Préparation locale du 18 octobre 2026, branche `preprod`, base
`a082e4694ca80dba17cf3229d2871678c9c471d7`. Avant modification,
`git diff HEAD -- ops/deploy_beta.sh` était vide : la référence fournie comme
script utilisé est identique au dépôt. Aucun accès Hostinger effectué ; la copie
SSH devra encore être comparée. `.env.local` de développement reste inchangé
(parcours activé volontairement). Aucun véritable environnement n'est livré.

Les commandes ci-dessous sont **à exécuter ultérieurement**, après validation
de la livraison. Arrêter à la première erreur, maintenir les écritures et workers
interrompus et conserver le témoin. Ne jamais lancer ce script sur le poste de
développement. La procédure des [cours privés](../tests/manual/courses_lot2b_recipe.md)
reste applicable, notamment l'inventaire de toutes les racines web/alias et le
blocage permanent des anciennes sources.

État rapporté par l'opérateur le 18 octobre : maintenance active, restauration
des `.htaccess` confirmée, aucune migration distante appliquée, sauvegarde
restaurée localement et dry-run distant validés. Source confirmée :
`u849885333_orthogram_beta`, 27 tables InnoDB, 6 comptes, export de 159 786 octets.
Ces résultats sont acquis pour l'instantané contrôlé, pas recontrôlés ici.
Conserver leurs preuves et vérifier qu'aucune écriture ni modification du code
des migrations n'est intervenue depuis ; sinon renouveler les contrôles concernés
des sections 3–4. La restauration locale ne prouve pas la compatibilité Hostinger.

## 1. Avant le push : maintenance et configuration distante

En SSH avec le compte de déploiement, dans Bash :

```bash
set -euo pipefail
DOMAIN=/home/u849885333/domains/orthogram.fr
PROJECT="$DOMAIN/public_html/beta"
touch "$DOMAIN/.orthogram-maintenance"
```

Depuis un client HTTP extérieur, vérifier **GET et HEAD = 503** sur
`https://beta.orthogram.fr/`, `/login`, `/index.php`, `/public/index.php` et
une URL d'asset réellement existante. Exemple de contrôle bloquant :

```bash
URL=https://beta.orthogram.fr/login
test "$(curl -sS -L -o /dev/null -w '%{http_code}' "$URL")" = 503
test "$(curl -sS -I -L -o /dev/null -w '%{http_code}' "$URL")" = 503
```

Répéter sur les autres accès réels à la bêta (`/beta/`, `/beta/public/`, alias
constatés), puis interrompre workers/cron pouvant écrire. Ne pas pousser si ces
contrôles échouent. Le script ne crée pas la maintenance et ne retire jamais le
témoin, même après succès. Une 503 ne prouve pas le blocage permanent des médias.

Éditer **sur le serveur** `$DOMAIN/.env.beta`, sans afficher son contenu dans des
logs ni le copier dans Git. Ajouter une seule définition de chaque variable :

```dotenv
DICTATION_STORAGE_DIR=/home/u849885333/domains/orthogram.fr/private/orthogram/dictations
DICTATION_LEARNER_ENABLED=0
```

Conserver `COURSE_STORAGE_DIR` privé et les autres paramètres de production.
Le flag `0` concerne cette livraison ; le script accepte aussi `1` et ne le remet
jamais à zéro. Supprimer/corriger les surcharges contradictoires du processus,
de PHP web ou des fichiers `.env.prod[.local]`, sans modifier le réglage local de
développement. Ne pas utiliser `source .env.beta`, `printenv`, `debug:dotenv` ou
`debug:container --env-vars` pour ces vérifications.

Le stockage attendu est hors de `public_html` et du répertoire republié. Vérifier
`realpath "$DOMAIN/private/orthogram"`, les propriétaires/groupes, les droits de
traversée des parents et l'absence de racine web/alias pointant sur ce stockage.
Le parent doit déjà être réel, comme pour les cours. Création possible avant
la vérification PHP web :

```bash
STORE="$DOMAIN/private/orthogram/dictations"
test "$(realpath "$DOMAIN/private/orthogram")" = "$DOMAIN/private/orthogram"
test ! -L "$STORE"
if test ! -e "$STORE"; then (umask 007; mkdir -- "$STORE"); fi
test -d "$STORE"
test "$(realpath "$STORE")" = "$STORE"
stat -c '%a %U %G %n' "$DOMAIN/private/orthogram" "$STORE"
find "$STORE" -type l -print
```

Tout lien, y compris interne ou pendant, impose un arrêt et une analyse manuelle.
Le script peut aussi créer uniquement ce dernier répertoire sous `umask 007`
(mode 0770 sans ACL héritée). Il ne modifie ni les permissions existantes ni les
médias. Pas de purge, déplacement, recopie ou lien public. Adapter au besoin le
groupe/ACL à **l'identité PHP web constatée**, avec seulement les accès nécessaires
(traversée des parents, lecture/écriture du stockage), jamais `777` ni chmod
récursif des médias. Examiner aussi les ACL héritées.

Les contrôles Bash/PHP CLI ne prouvent **ni** les droits PHP-FPM/LiteSpeed **ni**
sa configuration (`open_basedir`, variables serveur, version/extensions).
`ORTHOGRAM_PRIVATE_STORAGE_VERIFIED=1` atteste le stockage **des cours existants**
déjà validé (accès PHP web et isolation HTTP), pas celui des dictées. Ne pas poser
cette confirmation si les cours ne sont pas validés. L'isolation hors Web des
deux stockages reste obligatoire avant déploiement.

Pour cette livraison, la validation PHP web **des dictées reste non acquise** et
est reportée à l'import et à la lecture audio dans EasyAdmin, après réouverture
surveillée, avec le parcours apprenant désactivé. Aucune sonde HTTP temporaire.
Le choix explicite `ORTHOGRAM_DICTATION_WEB_DEFERRED=1` impose le flag effectif
`0` aux contrôles source, compilé et conteneur ; ce n'est pas une attestation de
réussite. Sans ce choix (ou avec `0`), le flag reste configurable pour les
déploiements futurs, après recette réussie.

Compatibilité vérifiée dans le code : `DictationController::actor()` refuse les
actions apprenant avant les accès métier ; `DictationJourney::forSection()`
retourne immédiatement sans consulter les dictées quand le flag vaut `0`.
Les contrôles de cours et leurs widgets ne créent alors aucun verrou de dictée.
`CourseFileStorage` utilise exclusivement le stockage des cours ; les
constructeurs des services de dictées ne lisent pas leurs médias. L'administration
des dictées reste accessible aux administrateurs indépendamment de ce flag.
Les migrations restent nécessaires, notamment pour la colonne mappée de `user`,
avant réouverture ; désactiver le parcours ne permet pas de les omettre.

## 2. Publication du code puis installation du script manuel

Après validation et commit ultérieurs des changements locaux, relever le SHA à
livrer (`git rev-parse HEAD`), puis seulement après les 503 :

```bash
# Poste local, branche preprod ; aucune de ces opérations n'a été faite ici.
git push origin preprod
```

Attendre la **fin réussie** de la publication automatique Hostinger et de son
installation Composer depuis `composer.lock`. Vérifier le SHA publié dans les
journaux Hostinger (et `git rev-parse HEAD` sur la copie publiée si elle a `.git`).
Recontrôler les 503. Ne pas lancer simultanément publication et script SSH.

Composer déclare PHP >= 8.4, `ext-ctype`, `ext-fileinfo`, `ext-iconv`, `ext-intl`,
`ext-mbstring` et `james-heinrich/getid3` **1.9.24** (également verrouillé).
L'import MP3 utilise `finfo` et getID3 ; la correction utilise notamment intl et
mbstring. Contrôler également le pilote MySQL de PHP. Aucun `composer install`
supplémentaire n'est ajouté : le script vérifie les exigences installées avec
`composer check-platform-reqs --no-dev` et la disponibilité de getID3.
Confirmer aussi ces exigences dans PHP web et les limites d'upload pour les MP3
(25 Mio maximum par fichier, 30 minutes maximum ; `post_max_size` doit laisser
la place à l'enveloppe du formulaire). Les auto-scripts Composer Hostinger
incluent déjà `cache:clear`, `assets:install` et `importmap:install`.

En SSH, sauvegarder/comparer puis installer la copie révisée, hors publication :

```bash
cd "$PROJECT"
test -f "$DOMAIN/.orthogram-maintenance"
bash -n ops/deploy_beta.sh
php -l ops/check_beta_environment.php
composer check-platform-reqs --no-dev
composer show james-heinrich/getid3
DEPLOY_UMASK=$(umask)
umask 077
SCRIPT_BACKUP=$(mktemp "$DOMAIN/deploy_beta.sh.before-dictations.XXXXXX")
cp -p "$DOMAIN/deploy_beta.sh" "$SCRIPT_BACKUP"
diff -u "$SCRIPT_BACKUP" ops/deploy_beta.sh || test "$?" = 1
# Lire le diff : intégrer toute protection distante supplémentaire avant de continuer.
SCRIPT_NEXT=$(mktemp "$DOMAIN/deploy_beta.sh.next.XXXXXX")
cp ops/deploy_beta.sh "$SCRIPT_NEXT"
bash -n "$SCRIPT_NEXT"
chmod 700 "$SCRIPT_NEXT"
mv -- "$SCRIPT_NEXT" "$DOMAIN/deploy_beta.sh"
cmp ops/deploy_beta.sh "$DOMAIN/deploy_beta.sh"
umask "$DEPLOY_UMASK"
```

Le nouveau script appelle `ops/check_beta_environment.php` **dans le projet
publié** ; les deux doivent appartenir à la même livraison. Ne pas installer le
script seul avant la publication. Conserver la sauvegarde de l'ancien script.

## 3. Configuration effective, base cible et sauvegarde vérifiée

Toujours sous maintenance, préparer la configuration sans appliquer de migration :

```bash
cd "$PROJECT"
CHECK=(php ops/check_beta_environment.php)
CHECK_ARGS=("$DOMAIN/.env.beta" "$DOMAIN/private/orthogram/courses" "$DOMAIN/private/orthogram/dictations")
export ORTHOGRAM_DICTATION_WEB_DEFERRED=1
"${CHECK[@]}" source "${CHECK_ARGS[@]}"
# Pour cette livraison, le message doit indiquer DICTATION_LEARNER_ENABLED=0.
(umask 077; cp "$DOMAIN/.env.beta" .env.local; rm -f .env.local.php; composer dump-env prod)
"${CHECK[@]}" compiled "${CHECK_ARGS[@]}"
php bin/console cache:clear --env=prod --no-debug
php bin/console cache:warmup --env=prod --no-debug
"${CHECK[@]}" container "${CHECK_ARGS[@]}"
```

Protéger `.env.beta`, `.env.local` et `.env.local.php` : 0600 si PHP web utilise
le même compte, sinon 0640 et groupe privé vérifié. Ne jamais les afficher.
Le contrôleur compare uniquement les deux chemins et le flag entre source,
copie, dump et environnement effectif, puis les chemins et le booléen résolus par Symfony.
Il refuse une surcharge de processus contradictoire et n'affiche aucune valeur
secrète. Après toute modification d'environnement, refaire ces commandes.

Identifier la connexion **effective**, sans imprimer `DATABASE_URL` ni le mot
de passe, puis rapprocher ces informations de la base bêta attendue dans hPanel :

```bash
php <<'PHP'
<?php
require 'vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
$kernel = new App\Kernel('prod', false);
$kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
foreach (['host', 'port', 'dbname', 'user'] as $key) {
    echo $key.': '.($db->getParams()[$key] ?? '(default)').PHP_EOL;
}
echo json_encode($db->fetchAssociative('SELECT DATABASE() AS db, @@hostname AS server, @@port AS port, CURRENT_USER() AS account'), JSON_THROW_ON_ERROR).PHP_EOL;
PHP
php bin/console dbal:run-sql 'SELECT COUNT(*) AS existing_users FROM user' --env=prod --no-debug
php bin/console dbal:run-sql 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE"' --env=prod --no-debug
```

Consigner l'identité et le nombre de comptes existants. Toute divergence arrête
la procédure. `--single-transaction` garantit la cohérence des tables InnoDB ;
si d'autres moteurs sont présents, préparer une sauvegarde avec verrouillage
adapté, en maintenant l'arrêt de **toutes** les écritures et DDL concurrents.

Créer une sauvegarde hors Web, dans un emplacement réel sans liens/alias publics.
Renseigner les paramètres **de la connexion vérifiée** ; saisir le mot de passe
au prompt, jamais dans une commande ou une variable d'environnement :

```bash
read -r -p 'Hôte MySQL vérifié : ' DB_HOST
read -r -p 'Port MySQL vérifié : ' DB_PORT
read -r -p 'Nom exact base bêta : ' DB_NAME
read -r -p 'Utilisateur MySQL : ' DB_USER
test "$(realpath "$DOMAIN/private/orthogram")" = "$DOMAIN/private/orthogram"
umask 077
BACKUP_DIR=$(mktemp -d "$DOMAIN/private/orthogram/backup-dictations.XXXXXX")
BACKUP="$BACKUP_DIR/beta-before.sql"
mysqldump --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password \
  --single-transaction --routines --triggers --events --hex-blob --no-tablespaces \
  "$DB_NAME" > "$BACKUP"
test -s "$BACKUP"
sha256sum "$BACKUP" > "$BACKUP.sha256"
sha256sum -c "$BACKUP.sha256"
cp -p "$DOMAIN/.env.beta" "$BACKUP_DIR/env.beta"
tar -cf "$BACKUP_DIR/private-media.tar" -C "$DOMAIN/private/orthogram" courses dictations
tar -tf "$BACKUP_DIR/private-media.tar" > "$BACKUP_DIR/private-media.list"
umask "$DEPLOY_UMASK"
```

Vérifier le code retour du dump, les droits 0700/0600 de la sauvegarde et l'espace
disponible. En cas d'option/droit MySQL indisponible, arrêter et résoudre avec
l'hébergeur ; ne pas accepter une sauvegarde partielle. Ne pas utiliser
`--databases` : le dump destiné au test ne doit pas sélectionner la base bêta.

**Vérification de restauration avant migration** : provisionner une base jetable
vide, de nom différent, de préférence sur un serveur isolé, avec un utilisateur
qui ne peut pas écrire dans la bêta. Ne pas la déclarer dans `.env.beta`.

```bash
read -r -p 'Hôte de restauration isolé : ' RESTORE_HOST
read -r -p 'Port : ' RESTORE_PORT
read -r -p 'Base jetable vide : ' RESTORE_DB
read -r -p 'Utilisateur limité à cette base : ' RESTORE_USER
test -n "$RESTORE_DB"
test "$RESTORE_DB" != "$DB_NAME"
mysql --host="$RESTORE_HOST" --port="$RESTORE_PORT" --user="$RESTORE_USER" --password \
  --database="$RESTORE_DB" < "$BACKUP"
mysqlcheck --host="$RESTORE_HOST" --port="$RESTORE_PORT" --user="$RESTORE_USER" --password \
  --check "$RESTORE_DB"
mysql --host="$RESTORE_HOST" --port="$RESTORE_PORT" --user="$RESTORE_USER" --password \
  --database="$RESTORE_DB" -e 'SHOW TABLES; SELECT COUNT(*) AS existing_users FROM user; SELECT * FROM doctrine_migration_versions ORDER BY version;'
```

Comparer tables, comptages (au minimum `user`, mais aussi les tables métier
inventoriées) et registre des migrations à la source sous maintenance. Une somme
SHA seule ne démontre pas que le dump est restaurable. Conserver dump, empreinte,
compte rendu et archive privée. Aucun test de restauration ne vise la base réelle.

## 4. Migrations en attente, dry-run, puis déploiement

Les migrations examinées sont, dans l'ordre :

| Version | Effet |
| --- | --- |
| `Version20261016120000` | Création `dictation`, `dictation_version`, `dictation_attempt`, index et clés étrangères vers sections/utilisateurs. |
| `Version20261016180000` | Métadonnées des deux audios, segments, clé et empreinte ; initialise uniquement les segments des versions déjà présentes à `[]`. |
| `Version20261017180000` | Ajout `user.dictation_prerequisites JSON DEFAULT NULL` ; comptes existants exemptés. |

Toutes ciblent MySQL/MariaDB et sont **non transactionnelles**. Un échec DDL peut
laisser un schéma partiel. Pas de rollback/down automatique, pas de relance aveugle
ni de modification manuelle du registre pour masquer l'échec. Diagnostiquer le
schéma et le registre sous maintenance ; choisir une réparation ou restauration
vérifiée, en préservant aussi les nouveaux médias. Ne pas importer la base de
développement et ne jamais remplir rétroactivement les prérequis des comptes.

```bash
cd "$PROJECT"
php bin/console doctrine:migrations:status --env=prod --no-debug
php bin/console doctrine:migrations:list --env=prod --no-debug
php bin/console doctrine:migrations:migrate --dry-run --env=prod --no-debug --no-interaction \
  > "$BACKUP_DIR/migrations-dry-run.log" 2>&1
less "$BACKUP_DIR/migrations-dry-run.log"
```

Le statut distant fait foi, indépendamment des trois migrations déjà appliquées
en développement. Pour cette première livraison, retrouver ces trois versions
en attente ; toute autre migration, version inconnue, divergence de schéma ou
version déjà partiellement appliquée nécessite un examen avant de continuer.
Lire le SQL complet du dry-run et son code retour. Il n'applique pas le schéma
et ne garantit pas que le DDL réussira ; Doctrine peut vérifier/préparer son
registre de versions. La sauvegarde précède donc aussi cette étape.

Après sauvegarde restaurée et dry-run approuvé par l'opérateur, les trois
confirmations existantes restent nécessaires. La confirmation de stockage porte
sur les cours ; ajouter le choix explicite du report pour les dictées :

```bash
test -f "$DOMAIN/.orthogram-maintenance"
ORTHOGRAM_MAINTENANCE_CONFIRMED=1 \
ORTHOGRAM_PRIVATE_STORAGE_VERIFIED=1 \
ORTHOGRAM_LEGACY_HTTP_BLOCKED=1 \
ORTHOGRAM_DICTATION_WEB_DEFERRED=1 \
  "$DOMAIN/deploy_beta.sh" --deploy
test -f "$DOMAIN/.orthogram-maintenance"
```

`--deploy` exige le témoin de bascule privée des cours et ne recopie jamais les
anciennes sources. `--initial-switch` reste réservé à cette bascule, avec ses
trois passes de transfert/vérification ; ne pas l'utiliser pour installer les
dictées. La préservation/réparation des avatars et les contrôles des cours restent
inchangés. Le script contient **une seule application** `migrations:migrate` ;
ne pas lancer une application manuelle en plus du script.

Ordre conservé : environnement compilé → cache → contrôle de configuration
résolue → migrations → références cours/avatars/assets. Les constructeurs ne
lisent pas les dictées/utilisateurs ; les warmers Doctrine chargent les métadonnées,
Twig compile sans rendre les pages, et le listener d'inscription ne s'exécute
que sur `prePersist`. La vérification locale avec un pilote interdisant toute
connexion DB confirme `cache:clear` et `cache:warmup` sur cette version. Les
commandes métier susceptibles de lire `user` restent après les migrations.

Après `asset-map:compile`, le script impose **0755 aux répertoires et 0644 aux
fichiers ordinaires de `public/assets` uniquement**, même avec un `umask 077`.
Il refuse une racine ou un parent symbolique et utilise `find -P` pour ignorer
les liens internes, sans modifier leurs cibles. Aucun changement d'umask global,
de permissions des stockages privés ou des fichiers d'environnement. Un échec
de cette correction arrête le script et laisse la maintenance active.
Test ciblé : `python tests/ops/deploy_beta_test.py --assets-only` (compilation et
commandes applicatives simulées). Il couvre le lancement sous `077`, les liens
et un échec de chmod. Sous Windows/MSYS, seuls les arguments/cibles de chmod
sont vérifiés ; les modes effectifs 0755/0644 sont également exigés sous POSIX.

## 5. Recette, réouverture et activation séparée

Après succès, **avant retrait manuel** du témoin : refaire le contrôle `container`,
le statut/list des migrations, `app:courses:verify-files`, vérifier les journaux,
les assets et les 503. Vérifier le schéma attendu et l'exemption :

```bash
php bin/console doctrine:migrations:status --env=prod --no-debug
php bin/console doctrine:migrations:list --env=prod --no-debug
php bin/console dbal:run-sql 'SELECT COUNT(*) AS total, SUM(dictation_prerequisites IS NULL) AS exempted FROM user' --env=prod --no-debug
php bin/console dbal:run-sql 'SELECT COUNT(*) FROM dictation' --env=prod --no-debug
```

Pour cette première migration, toutes les lignes `user` préexistantes doivent
être exemptées ; rapprocher le comptage de celui sauvegardé. Ne pas corriger une
différence par un UPDATE global.

Le contrôle CLI doit encore annoncer le flag **0** avec le report actif ; il ne
prouve pas la configuration du processus web. Vérifier l'absence de surcharge
PHP web connue du flag, sans afficher de secrets. Conserver explicitement le
statut « stockage dictées : CLI validé, PHP web à recetter ». Vérifier les
restrictions permanentes de tous les anciens
chemins/alias, l'absence de lien public vers les dictées, et purger les anciens
caches publics éventuels. Les 503 ne suffisent pas à prouver ces restrictions.

Seulement après ces succès techniques, retirer **manuellement** le témoin pour
une réouverture surveillée :

```bash
rm -- "$DOMAIN/.orthogram-maintenance"
# Si une recette échoue :
# touch "$DOMAIN/.orthogram-maintenance"
```

Tester alors accueil, connexion, cours, avatars, administration et refus des
anciennes URL/médias sans droit (GET/HEAD/Range, domaines/alias inventoriés).
Rétablir immédiatement la maintenance en cas d'échec.
Vérifier l'absence de parcours/widgets dictées sur un compte apprenant autorisé.
Après création d'une dictée dans EasyAdmin, demander `/dictees/ID` avec cet ID
réel et un compte actif abonné autorisé à sa section : attendre 404. Un ID
inexistant ou une redirection de connexion anonyme ne prouve pas le flag web.
Si le parcours apparaît, rétablir immédiatement la maintenance et corriger la
surcharge avant de poursuivre. Ces contrôles utilisent les routes applicatives
existantes, sans exception de maintenance.

La suite comporte quatre étapes distinctes :

1. **Code et schéma** : livraison ci-dessus, parcours apprenant désactivé.
2. **Contenu EasyAdmin** : créer les dictées et brouillons par section, texte,
   variantes, correction et segments. Aucune donnée éditoriale n'est créée par
   le déploiement ou par une nouvelle migration.
3. **MP3** : importer les audios complet et d'écriture via les formulaires
   administratifs, écouter les deux via PHP web et vérifier les segments.
   Rapprocher les deux nouveaux fichiers/références du répertoire privé attendu,
   sans afficher leur contenu ni toucher aux médias préexistants. L'import
   réussi exerce l'écriture exclusive, la lecture, getID3 et les métadonnées
   sous PHP web ; la lecture audio exerce le service web des médias. Consigner
   leur réussite avant de publier les versions utilisables. Cela ne démontre
   pas tous les droits PHP (notamment la suppression) ni toutes les extensions
   du SAPI. En cas d'échec, garder le flag à `0`, ne pas publier/activer et
   diagnostiquer l'import/lecture ; les cours peuvent rester ouverts si leur
   recette est réussie et leur isolation préservée. L'import calcule clé, empreinte, durée et
   références ; un simple dépôt SCP de MP3 ne crée pas ces associations. Les
   fichiers existants ne sont ni remplacés ni nettoyés par le déploiement.
4. **Activation ultérieure** : uniquement après import et écoute réussis des
   deux pistes dans EasyAdmin, validation du contenu et des prérequis PHP web
   (extensions/limites d'upload via les moyens d'administration de l'hébergeur), ouvrir
   une nouvelle fenêtre de maintenance, changer uniquement le flag distant à
   `1`, exécuter `unset ORTHOGRAM_DICTATION_WEB_DEFERRED`, refaire les commandes
   copie/dump/cache/contrôles de la section 3 **sans réexporter le report**, puis
   réouverture manuelle surveillée et recette apprenant. Remettre la maintenance
   si celle-ci échoue. Aucun push ni nouvelle migration nécessaires
   pour ce changement seul. Les anciens comptes, y compris ceux créés pendant
   que le flag était à `0`, restent exemptés (`NULL`). Les nouveaux comptes à
   partir de l'activation reçoivent leur instantané des dictées utilisables ;
   aucune reconstruction rétroactive.

## Vérifications locales reproductibles et limites

```powershell
& 'C:\Program Files\Git\bin\bash.exe' -n ops/deploy_beta.sh
php -l ops/check_beta_environment.php
python tests/ops/deploy_beta_test.py
php tests/ops/beta_cache_without_database.php
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php tests/ops/DeferredDictationAccessTest.php tests/Security/Voter/CourseVoterTest.php
composer validate --no-check-publish
composer check-platform-reqs --no-dev
git diff --check
```

Exécuté : 21 scénarios Bash simulés (dont 4 ciblés assets) + 12 scénarios PHP/Dotenv
réels. Les vérifications antérieures ont aussi exécuté les deux
commandes de cache prod et le contrôle du conteneur avec toute connexion DB
interdite et le stockage dictées absent de la fixture. Couverture : report accepté
avec flag 0 et refusé avec flag 1, valeurs 0/1, mauvais chemin/flag, absence de flag,
surcharge de processus, copie/dump divergents, erreur de syntaxe contenant un
secret jamais réaffiché, absence de maintenance/confirmation, dépendances en
échec, stockage fichier/liens (parent, feuille, pendant, interne), création du
répertoire, échec migration, conservation des médias/maintenance, modes initial
et courant, avatars et absence de transfert des cours en `--deploy`.
Les tests unitaires isolés vérifient aussi les dix appels du contrôleur apprenant
refusés en 404 avec stockage absent et accès ORM interdits, les droits des cours
avec parcours désactivé et l'exemption des nouvelles inscriptions. Ils chargent
uniquement l'autoloader, aucun environnement applicatif ni base réelle :
12 tests, 218 assertions avec la suite existante du voter des cours.

Les fixtures restent dans des dossiers uniques `var/deploy-beta-test-*` et
`var/beta-cache-test-*`, ignorés par Git. Sous Windows, les liens sont émulés par
MSYS : ces essais ne prouvent pas les ACL/permissions Linux, PHP web, les réponses
HTTP Hostinger, la publication Composer distante, la base cible, la restauration
du dump ou l'exécution MySQL des migrations. Ces contrôles restent à faire sur
les environnements prévus. Composer valide avec avertissements sur les contraintes
préexistantes getID3 exacte et `symfony/apache-pack: *` ; aucune dépendance modifiée.
Le contrôle de plateforme installé passe aussi ; `extension_loaded` confirme
localement ctype, fileinfo, iconv, intl, mbstring et pdo_mysql. Composer peut
annoncer mbstring fourni par le polyfill même en présence de l'extension native :
ce résultat ne remplace pas l'inventaire des extensions du SAPI web distant.

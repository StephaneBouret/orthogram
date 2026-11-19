# Module 3 — procédure bêta à relire avant publication

Préparation sur `preprod`, à partir de `207a0f69c935b92d53ec611724afff061b536903`. Les opérations ci-dessous sont destinées à une intervention ultérieure autorisée. Le SHA de départ identifie la préparation locale. Relever séparément le SHA définitif contenant les adaptations de production.

## Avant le push — décision de publication

**Prérequis ouvert côté Hostinger.** Le dépôt permet de vérifier les règles de maintenance, les chemins et les contrôles ponctuels du script. Il ne permet pas d’établir qu’une réponse 503 continue sera servie pendant le remplacement du code par la publication automatique. La fenêtre commence dès le push susceptible de déclencher hPanel. Suspendre le push jusqu’à obtention des preuves suivantes.

- Faire confirmer le déclencheur réel, la branche, les SHA courant et à livrer, le répertoire remplacé ou nettoyé, les publications concurrentes, l’ordre de copie des fichiers et de Composer, les hooks et leur comportement en cas d’échec. Identifier ce qui advient des deux témoins non suivis dans le projet, du `.htaccess` racine non suivi et du `public/.htaccess` suivi.
- Obtenir la liste des DocumentRoot, chemins directs, alias, accès à l’origine, proxy et caches CDN pour les deux domaines. Faire établir et tester une barrière de maintenance indépendante du répertoire publié qui couvre ces accès pendant **toute** la fenêtre, ou obtenir la démonstration que les règles et témoins restent effectifs à chaque étape du mécanisme de publication. Vérifier aussi la portée sur le site principal. Des sondes avant/après ou un hook qui recrée les témoins après copie laissent une fenêtre possible.
- Confirmer l’arrêt des workers, cron, uploads et autres écritures, attendre les transactions en cours. Inventorier les cours du module 3 avec leurs sections, positions, types, relations et fichiers, ainsi que la dictée, sa version, son audio et ses rattachements. Les conserver comme référence. Les preuves contenant des données restent privées.
- Comparer la copie **externe** du script au fichier du **commit approuvé**, puis valider une sauvegarde restaurée avant toute publication. Les détails suivent. [Procédure de maintenance et de déploiement](deploy_beta.md)

### Témoins et contrôles HTTP

Utiliser un shell Bash dédié sur le serveur pour toute la fenêtre. Confirmer les chemins et les règles en tête des deux `.htaccess` avant les redirections. Poser les trois témoins une seule fois à l’entrée en maintenance autorisée. Lors des contrôles suivants, toute disparition impose un arrêt.

```bash
set -euo pipefail
set +x
umask 077
DOMAIN=/home/u849885333/domains/orthogram.fr
PROJECT="$DOMAIN/public_html/beta"
readonly -a MARKERS=("$DOMAIN/.orthogram-maintenance" "$PROJECT/.orthogram-maintenance" "$PROJECT/public/.orthogram-maintenance")
cd "$PROJECT"
for marker in "${MARKERS[@]}"; do
    [[ ! -L "$marker" && ( ! -e "$marker" || -f "$marker" ) ]] || exit 1
done
touch -- "${MARKERS[@]}"

readonly -a URLS=(https://beta.orthogram.fr/ https://beta.orthogram.fr/login https://beta.orthogram.fr/admin
      https://beta.orthogram.fr/public/ https://beta.orthogram.fr/public/index.php https://orthogram.fr/beta/public/index.php)
orthogram_check_maintenance() {
    local marker url method code
    local -a args
    for marker in "${MARKERS[@]}"; do
        if [[ ! -f "$marker" || -L "$marker" ]]; then
            printf 'Témoin absent ou invalide : %s\n' "$marker" >&2
            return 1
        fi
    done
    for url in "${URLS[@]}"; do
        for method in GET HEAD; do
            args=(--disable --silent --show-error --connect-timeout 5 --max-time 15 --proto '=https'
                  --output /dev/null --write-out '%{http_code}' --header 'Cache-Control: no-cache' --header 'Pragma: no-cache')
            if [[ "$method" == HEAD ]]; then args+=(--head); fi
            code=$(curl "${args[@]}" "$url?orthogram_maintenance_check=$$-$SECONDS-$RANDOM") || return 1
            printf '%s %s %s\n' "$method" "$url" "$code"
            [[ "$code" == 503 ]] || return 1
        done
    done
}
orthogram_check_maintenance || exit 1
```

À chaque point de contrôle (avant push, après Hostinger/Composer, avant le script externe, avant les écritures et avant chaque réouverture), exiger le succès du contrôle serveur ci-dessus et des sondes d’un **client extérieur**. Depuis ce client, reprendre le tableau `URLS` ci-dessus et exécuter uniquement ce bloc HTTP dans Bash. Une erreur réseau/TLS ou une redirection interrompt la procédure. Vérifier séparément les alias et l’origine découverts lors de l’inventaire.

```bash
(( ${#URLS[@]} == 6 )) || exit 1
for url in "${URLS[@]}"; do
    for method in GET HEAD; do
        args=()
        if [[ "$method" == HEAD ]]; then args=(--head); fi
        code=$(curl --disable --silent --show-error --connect-timeout 5 --max-time 15 --proto '=https' \
            --header 'Cache-Control: no-cache' --header 'Pragma: no-cache' --output /dev/null \
            --write-out '%{http_code}' "${args[@]}" "$url?orthogram_maintenance_check=$$-$SECONDS-$RANDOM") || exit 1
        printf '%s %s %s\n' "$method" "$url" "$code"
        [[ "$code" == 503 ]] || exit 1
    done
done
```

Chaque point exige **12 réponses 503 directes depuis chaque point de vue**. Ces sondes ponctuelles et les essais simulés hors ligne ne prouvent pas la continuité pendant la publication automatique.

### Sauvegarde et script externe avant publication

1. Relever dans hPanel la destination bêta attendue, puis relever indépendamment dans Doctrine l’environnement, l’hôte configuré, le port, la plateforme et, sur la connexion effective, `DATABASE()`, `@@hostname`, `@@port` et `VERSION()`. Vérifier les cinq assertions, la série MariaDB, les droits, les tables InnoDB, les vues, triggers, événements et routines. Vérifier le choix de plateforme Doctrine. Ne pas consigner les secrets. [Identification de la connexion, procédure du module 2, § 1](module2_quiz_production.md)
2. Créer un répertoire privé unique hors du projet, de Git et de `public_html`. Les commandes ci-dessous utilisent les valeurs bêta confirmées. Conserver les chemins de fichiers et les empreintes dans le journal privé de livraison.
3. Depuis le dépôt de revue, extraire le script du SHA exact avec `git show "${RELEASE_SHA}:ops/deploy_beta.sh" > deploy_beta.approuve.sh` dans un répertoire privé, calculer `sha256sum` et transférer le fichier de référence séparément de la publication. Sur l’hébergeur, sauvegarder `$DOMAIN/deploy_beta.sh`, comparer octet par octet avec `cmp`, examiner tout écart, y compris les protections propres au serveur. Résoudre et faire approuver les différences avant publication. Refaire la comparaison avant exécution. Un arbre publié sans métadonnées Git demande une preuve du SHA livré par le journal hPanel et les empreintes de l’artefact.

Renseigner les variables d’identité d’après les valeurs autorisées, dont `DB_ACCOUNT` sous la forme exacte renvoyée par `CURRENT_USER()` (compte MariaDB avec sa partie hôte). Garder l’hôte et le port Doctrine pour les assertions applicatives. Pour les clients MariaDB, reprendre le socket Unix validé lors du module 2. La connexion TCP à la source a déjà échoué avec l’erreur 1045. Vérifier que les fichiers d’options ordinaires des clients n’imposent aucun autre identifiant, transport ou ordre SQL à la connexion.

```bash
: "${RELEASE_SHA:?SHA définitif approuvé}" "${DB_NAME:?base bêta vérifiée}" "${DB_HOST:?hôte vérifié}" "${DB_PORT:?port vérifié}" "${DB_SERVER:?serveur vérifié}" "${DB_ACCOUNT:?compte source autorisé}"
test "$(realpath "$DOMAIN/private/orthogram")" = "$DOMAIN/private/orthogram"
EVIDENCE=$(mktemp -d "$DOMAIN/private/orthogram/module3-release.XXXXXX")
DEST=(--expect-env=prod "--expect-database=$DB_NAME" "--expect-host=$DB_HOST" "--expect-port=$DB_PORT" "--expect-server=$DB_SERVER")
unset MYSQL_PWD
CREDENTIALS=$(mktemp -d "$DOMAIN/private/orthogram/module3-client.XXXXXX")
trap 'rm -f -- "$CREDENTIALS/source.cnf" "$CREDENTIALS/restore.cnf"; rmdir -- "$CREDENTIALS"' EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
SOURCE_CNF="$CREDENTIALS/source.cnf"
RESTORE_CNF="$CREDENTIALS/restore.cnf"

# Doctrine fournit les valeurs résolues du projet publié, y compris les caractères spéciaux.
SOURCE_CNF="$SOURCE_CNF" APP_ENV=prod APP_DEBUG=0 php -d display_errors=0 -d log_errors=0 <<'PHP'
<?php
try {
    require 'vendor/autoload.php';
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
    $kernel = new App\Kernel('prod', false);
    $kernel->boot();
    $params = $kernel->getContainer()->get('doctrine')->getConnection()->getParams();
    $profile = "[client]\n";
    foreach (['user', 'password'] as $key) {
        $value = $params[$key] ?? null;
        if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new RuntimeException();
        }
        $escaped = strtr($value, ["\\" => "\\\\", '"' => '\\"', "\n" => '\n', "\r" => '\r', "\t" => '\t', "\x08" => '\b']);
        $profile .= $key.'="'.$escaped."\"\n";
    }
    if (file_put_contents(getenv('SOURCE_CNF'), $profile) !== strlen($profile)) {
        throw new RuntimeException();
    }
} catch (Throwable) {
    file_put_contents('php://stderr', "Création du profil client source impossible.\n");
    exit(1);
}
PHP
test "$(stat -c %a "$SOURCE_CNF")" = 600
SOURCE_SOCKET=/var/lib/mysql/mysql.sock
test -S "$SOURCE_SOCKET"
SOURCE=("--defaults-extra-file=$SOURCE_CNF" --protocol=SOCKET "--socket=$SOURCE_SOCKET")

# Comparer les valeurs effectives aux valeurs attendues avant tout dump ou chargement.
verify_db() {
    local db=$1 account=$2 server=$3 port=$4 transport=$5 status actual expected
    shift 5
    status=$(LC_ALL=C mariadb "$@" --database="$db" --execute='status') || return 1
    grep -Eq "^Connection:[[:space:]].* via ${transport}[[:space:]]*$" <<< "$status" || return 1
    actual=$(mariadb "$@" --database="$db" --batch --skip-column-names \
        --execute='SELECT DATABASE(), CURRENT_USER(), @@hostname, @@port') || return 1
    expected=$(printf '%s\t%s\t%s\t%s' "$db" "$account" "$server" "$port")
    printf 'Base, compte autorisé, serveur, port : %s (via %s)\n' "$actual" "$transport"
    [[ "$actual" == "$expected" ]]
}
verify_db "$DB_NAME" "$DB_ACCOUNT" "$DB_SERVER" "$DB_PORT" 'UNIX socket' "${SOURCE[@]}" || exit 1
DUMP=(--single-transaction --routines --events --triggers --hex-blob --no-tablespaces
      --skip-comments --skip-dump-date --skip-extended-insert --order-by-primary)
mariadb-dump "${SOURCE[@]}" "${DUMP[@]}" "$DB_NAME" > "$EVIDENCE/before.sql"
test -s "$EVIDENCE/before.sql"
sha256sum "$EVIDENCE/before.sql" > "$EVIDENCE/before.sha256"
sha256sum -c "$EVIDENCE/before.sha256"
```

Sauvegarder également la configuration privée, les avatars et les médias des cours et dictées. Vérifier inventaire, tailles, accès en lecture et empreintes. Réserver une base vide à cette restauration sur un serveur isolé de même version. Faire confirmer le compte spécifique et ses droits sur tous les objets de cette seule base, sans droit sur la source, le transport autorisé, le nom de serveur et son port SQL. Vérifier options de base, jeu de caractères et collation. Examiner les références qualifiées, DEFINER et événements du dump avant restauration et suspendre l’exécution des événements sur la cible.

Obtenir par le canal privé de restauration un fichier d’options MariaDB appartenant à l’opérateur, en mode 600 et hors racine web. Il contient les identifiants de **restauration** dans `[client]`. Pour un serveur TCP distant, y inclure les paramètres TLS vérifiés pour cette destination. Renseigner `RESTORE_PROFILE` avec le chemin de ce fichier et `RESTORE_TRANSPORT` avec le mode confirmé. Garder la trace shell désactivée. Les deux copies de session sont protégées par `umask 077` et supprimées à la sortie du shell, y compris après erreur ou signal intercepté. Exclure ces profils des sauvegardes et des rapports.

```bash
: "${RESTORE_DB:?base jetable réservée}" "${RESTORE_ACCOUNT:?compte de restauration autorisé}" "${RESTORE_SERVER:?serveur vérifié}" "${RESTORE_PORT:?port SQL vérifié}"
: "${RESTORE_PROFILE:?chemin du profil privé de restauration}" "${RESTORE_TRANSPORT:?SOCKET ou TCP vérifié}"
[[ "${RESTORE_DB,,}" != "${DB_NAME,,}" && "$RESTORE_ACCOUNT" != "$DB_ACCOUNT" ]] || exit 1
[[ -f "$RESTORE_PROFILE" && ! -L "$RESTORE_PROFILE" && -O "$RESTORE_PROFILE" ]] || exit 1
test "$(stat -c %a "$RESTORE_PROFILE")" = 600
install -m 600 -- "$RESTORE_PROFILE" "$RESTORE_CNF"
RESTORE=("--defaults-extra-file=$RESTORE_CNF")
case "$RESTORE_TRANSPORT" in
    SOCKET)
        : "${RESTORE_SOCKET:?chemin du socket de restauration vérifié}"
        test -S "$RESTORE_SOCKET"
        RESTORE+=(--protocol=SOCKET "--socket=$RESTORE_SOCKET")
        via='UNIX socket' ;;
    TCP)
        : "${RESTORE_HOST:?hôte TCP de restauration vérifié}"
        RESTORE+=(--protocol=TCP "--host=$RESTORE_HOST" "--port=$RESTORE_PORT")
        via='TCP/IP' ;;
    *) exit 1 ;;
esac
verify_db "$RESTORE_DB" "$RESTORE_ACCOUNT" "$RESTORE_SERVER" "$RESTORE_PORT" "$via" "${RESTORE[@]}" || exit 1
objects=$(mariadb "${RESTORE[@]}" --database="$RESTORE_DB" --batch --skip-column-names <<'SQL'
SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE())
     + (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=DATABASE())
     + (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE())
     + (SELECT COUNT(*) FROM information_schema.events WHERE event_schema=DATABASE());
SQL
)
test "$objects" = 0
sha256sum -c "$EVIDENCE/before.sha256"
orthogram_check_maintenance || exit 1
mariadb "${RESTORE[@]}" "$RESTORE_DB" < "$EVIDENCE/before.sql"
mariadb-check "${RESTORE[@]}" --check "$RESTORE_DB"
mariadb-dump "${RESTORE[@]}" "${DUMP[@]}" "$RESTORE_DB" > "$EVIDENCE/restored.sql"
diff -u "$EVIDENCE/before.sql" "$EVIDENCE/restored.sql" > "$EVIDENCE/restore.diff"
sha256sum -c "$EVIDENCE/before.sha256"
```

Exiger concordance du schéma, de toutes les lignes et des objets, dont cours, dictées, tentatives, résultats et progressions. Si une représentation diffère au réexport, conserver le diff privé et expliquer chaque différence technique par comparaison des définitions et valeurs avant de valider la restauration. **Deux preuves sont requises avant push** : la sauvegarde restaurée et comparée, et la continuité de la maintenance établie côté Hostinger pendant toute la publication automatique.

### Captures de conservation

Exécuter les exemples Bash suivants dans le même shell, depuis le projet bêta, avec les outils PHP/Composer validés et `php` correspondant au CLI prévu. Le comparateur historique accepte `--module=module3` dans la nouvelle livraison. Les fichiers JSON bruts peuvent contenir des données personnelles.

```bash
export APP_ENV=prod APP_DEBUG=0
capture() { php ops/quiz_release_snapshot.php --capture "${DEST[@]}" "--output=$EVIDENCE/$1.json"; }
compare() { php ops/module2_quiz_compare.php --module=module3 "$@"; }
quiz_import() { php bin/console app:quiz:module3-import --env=prod --no-debug "${DEST[@]}" "$@"; }
renewal() { php bin/console app:quiz:module3-renewal enable --env=prod --no-debug "${DEST[@]}" "$@"; }
capture before-publication
orthogram_check_maintenance || exit 1
```

## 1. Publication du code

Une fois les prérequis établis et l’accord obtenu pour ce SHA, autoriser la publication de `preprod`. Attendre la fin réussie du traitement Hostinger et de Composer. Vérifier le SHA et les empreintes livrés, les trois témoins, les règles racine/public et les 12 réponses HTTP directes. En cas de témoin disparu, garder la barrière indépendante et rétablir manuellement les protections avant de reprendre.

Comparer le fichier publié **et** la copie externe à la référence extraite du commit. La commande suivante suppose `APPROVED_SCRIPT` renseigné avec son chemin privé vérifié. Avant le dry-run des migrations, contrôler le fichier d’environnement source et préparer la configuration compilée du projet publié selon la procédure de déploiement. Vérifier que la connexion de ce nouveau code correspond toujours aux cinq assertions en prenant une capture. Une différence, même si le dry-run semble vide, interrompt la préparation.

```bash
: "${APPROVED_SCRIPT:?copie vérifiée du script du SHA approuvé}"
cmp "$APPROVED_SCRIPT" "$PROJECT/ops/deploy_beta.sh"
cmp "$APPROVED_SCRIPT" "$DOMAIN/deploy_beta.sh"
bash -n "$DOMAIN/deploy_beta.sh"
orthogram_check_maintenance || exit 1
capture published-before-script
compare --phase=identical "--before=$EVIDENCE/before-publication.json" "--after=$EVIDENCE/published-before-script.json" "--output=$EVIDENCE/compare-published.json"
php bin/console doctrine:migrations:status --env=prod --no-debug > "$EVIDENCE/migrations-status.txt"
php bin/console doctrine:migrations:list --env=prod --no-debug > "$EVIDENCE/migrations-list.txt"
php bin/console doctrine:migrations:migrate --dry-run --no-interaction --env=prod --no-debug > "$EVIDENCE/migrations-dry-run.txt"
```

Le module 3 réutilise le schéma des modules précédents. Exiger les tables `quiz_manifest_identity`, `quiz_reserve_import` et les colonnes de réserve, renouvellement et quota, avec leurs clés conformes. Le script externe lance une migration **globale**, peut réparer des avatars et compile les assets. Relire la liste et le SQL avant exécution. Toute migration en attente ou divergence de schéma relève d’une livraison distincte. Préserver la configuration et les médias de la dictée déjà publiée. Avant de poser les trois attestations, exiger les contrôles de maintenance, d’accès PHP web au stockage privé des cours et d’isolation HTTP, ainsi que la preuve de blocage HTTP des anciens chemins de médias. Maintenir la validation de la dictée déjà publiée. [Contrôles du script et paramètres opérateur](deploy_beta.md)

```bash
cmp "$APPROVED_SCRIPT" "$PROJECT/ops/deploy_beta.sh"
cmp "$APPROVED_SCRIPT" "$DOMAIN/deploy_beta.sh"
orthogram_check_maintenance && (
    unset ORTHOGRAM_DICTATION_WEB_DEFERRED
    ORTHOGRAM_MAINTENANCE_CONFIRMED=1 \
    ORTHOGRAM_PRIVATE_STORAGE_VERIFIED=1 \
    ORTHOGRAM_LEGACY_HTTP_BLOCKED=1 \
        bash "$DOMAIN/deploy_beta.sh" --deploy
) || exit 1
orthogram_check_maintenance || exit 1
capture after-publication
compare --phase=identical "--before=$EVIDENCE/before-publication.json" "--after=$EVIDENCE/after-publication.json" "--output=$EVIDENCE/compare-publication.json"
```

Le script vérifie lui-même les trois témoins et les 12 statuts au démarrage, immédiatement avant les migrations et à la fin. Une différence de données lors de la publication, même sur un avatar, interrompt la suite pour diagnostic. Vérifier à nouveau la destination effective après compilation de l’environnement.

## 2. Import des données — trois quiz fixes

En `prod`, même une prévisualisation exige l’environnement, la base, l’hôte, le port et le nom du serveur attendus. Le contrôle partagé les confronte à la connexion effective. Toute application de plan, même sans ajout, exige en plus `--apply --allow-production-database --expect-plan=…`. Le consentement local est refusé en production, y compris en présence du consentement de production. La base de travail `orthogram` reste réservée au contexte local. Les commandes du module 3 utilisent les clés éditoriales du manifeste.

```bash
orthogram_check_maintenance || exit 1
capture before-import
quiz_import "--report=$EVIDENCE/import-plan.json"
```

Relire la destination, le SHA-256 du manifeste `fa86493da4fb4ede77c8246bf3f460b235183eb82f506ff2acdba08ca697e876`, les trois clés et les volumes. Pour ce premier import, attendre 3 nouveaux quiz, 168 questions, 336 propositions et zéro copie existante. Exiger `schema_missing=[]`, `differences=[]`, `configuration_differences=[]`, `errors=[]`. Une collision de titre/texte, une identité supprimée, une trace orpheline ou un lot partiel impose d’examiner le diff. Un lot déjà complet et conforme permet de passer aux vérifications après import.

```bash
read -r -p 'Empreinte du plan bêta d’import relu : ' IMPORT_HASH
orthogram_check_maintenance || exit 1
quiz_import --apply --allow-production-database "--expect-plan=$IMPORT_HASH" "--report=$EVIDENCE/import-result.json"
quiz_import "--report=$EVIDENCE/import-after.json"
capture after-import
compare --phase=import "--plan=$EVIDENCE/import-after.json" "--before=$EVIDENCE/before-import.json" "--after=$EVIDENCE/after-import.json" "--output=$EVIDENCE/compare-import.json"
```

Le service vérifie de nouveau la destination et le consentement, prend le verrou propre à ce module et à cette base, ouvre la transaction et recalcule le plan sous verrou. Une empreinte locale, périmée ou relative à une autre action exige une nouvelle prévisualisation et une nouvelle revue. Après une interruption, prendre une capture et relire l’état avant toute décision de reprise.

| Clé résolue via `quiz_manifest_identity` | Initiales | Réserves | Propositions |
| --- | ---: | ---: | ---: |
| `module3_test1` | 10 | 25 | 70 |
| `module3_test2` | 12 | 23 | 70 |
| `module3_general` | 20 | 78 | 196 |

Relever les trois **ID attribués en bêta** dans `import-after.json`. Vérifier zéro cours rattaché à chacun, `renewal_enabled=false`, quota SQL `NULL`, trois identités, 168 traces. La prévisualisation doit afficher 168 copies existantes et zéro ajout. Le comparateur confronte toutes les anciennes lignes et le schéma. Les seuls ajouts admis sont les trois quiz, les 168 questions, les 336 propositions, les trois identités et les 168 traces. Vérifier aussi l’inventaire et les empreintes des médias des cours et de la dictée. Conserver les quiz fixes jusqu’à la décision de rattachement.

```bash
mariadb "${SOURCE[@]}" "$DB_NAME" > "$EVIDENCE/fixed-bank.tsv" <<'SQL'
SELECT i.quiz_key, q.id, q.renewal_enabled, q.error_question_quota,
       (SELECT COUNT(*) FROM courses c WHERE c.quiz_id=q.id) AS cours,
       (SELECT COUNT(*) FROM quiz_question x WHERE x.quiz_id=q.id AND x.reserve=0) AS initiales,
       (SELECT COUNT(*) FROM quiz_question x WHERE x.quiz_id=q.id AND x.reserve=1) AS reserves,
       (SELECT COUNT(*) FROM quiz_answer a JOIN quiz_question x ON x.id=a.question_id WHERE x.quiz_id=q.id) AS propositions,
       (SELECT COUNT(*) FROM quiz_reserve_import t WHERE t.quiz_id=q.id) AS traces
FROM quiz_manifest_identity i JOIN quiz q ON q.id=i.quiz_id
WHERE i.quiz_key IN ('module3_test1','module3_test2','module3_general')
ORDER BY i.quiz_key;
SQL
```

## 3. Rattachement manuel dans EasyAdmin

EasyAdmin exige une fenêtre d’accès opérateur autorisée. Après validation de la phase fixe, refaire les sondes extérieures et exécuter le contrôle serveur juste avant la réouverture des trois témoins et de la barrière.

```bash
orthogram_check_maintenance || exit 1
```

Si une isolation opérateur n’est pas disponible, planifier le rattachement après la réouverture autorisée. Garder les captures d’import antérieures à ces modifications.

Dans les cours de type **Quiz** de la bonne section, utiliser les identités bêta relevées et le champ **Quiz**. Vérifier la position et le cours sélectionné avant chaque sauvegarde. Si un emplacement manque ou est déjà occupé, arrêter pour décision éditoriale. La dictée et les cours pédagogiques existants doivent rester en place. Consigner les couples ID de cours bêta / clé / ID de quiz bêta et contrôler le compteur en mode fixe. [Guide éditorial et emplacements](../resources/quiz/module3/module3_guide_relecture.md)

| Quiz | Emplacement prévu |
| --- | --- |
| `module3_test1` | Après « voir » / « voire », avant « pousse-toi » / « pousses-toi ». |
| `module3_test2` | Après « Les adjectifs de couleur (1) », avant « cauchemar » / « cauchemardesque ». |
| `module3_general` | Après « dailleurs » / « d’ailleurs », avant « Ma dictée après les cours ». |

## 4. Activation ultérieure du renouvellement

Après accord distinct, rétablir la maintenance, vérifier les trois témoins et les douze 503, suspendre les écritures et reconfirmer la destination. Prendre une **nouvelle** référence après rattachement et une sauvegarde restaurable si la fenêtre a changé. Utiliser de nouveaux noms de rapports à chaque essai.

```bash
orthogram_check_maintenance || exit 1
capture before-enable
renewal "--report=$EVIDENCE/enable-plan.json"
```

Relire `renewal_changes` et exiger exactement trois passages de `false` à `true` pour les identités bêta résolues, quotas `NULL`, zéro erreur et zéro divergence. Une configuration déjà active ou partiellement active demande une revue de l’état constaté. Après accord sur l’empreinte spécifique de ce plan :

```bash
read -r -p 'Empreinte du plan bêta d’activation relu : ' ENABLE_HASH
orthogram_check_maintenance || exit 1
renewal --apply --allow-production-database "--expect-plan=$ENABLE_HASH" "--report=$EVIDENCE/enable-result.json"
capture after-enable
compare --phase=enable "--plan=$EVIDENCE/enable-plan.json" "--before=$EVIDENCE/before-enable.json" "--after=$EVIDENCE/after-enable.json" "--output=$EVIDENCE/compare-enable.json"
renewal "--report=$EVIDENCE/enable-after.json"
```

Exiger trois indicateurs modifiés et `renewal_changes=[]` en fin de phase. Comparer les quotas, rattachements, cours, dictée, progressions, tentatives, snapshots et bilans avec la référence après rattachement. Pour toute différence, conserver les preuves et l’isolement. Une désactivation ultérieure utilise `module3-renewal disable`, sa propre prévisualisation et son consentement. Une restauration globale nécessite une décision distincte tenant compte des écritures postérieures à la sauvegarde.

Après validation et succès des sondes extérieures, effectuer le dernier contrôle serveur.

```bash
orthogram_check_maintenance || exit 1
```

Retirer ensuite manuellement les trois témoins puis lever la barrière au moment convenu. Contrôler GET et HEAD sur les six URL selon leurs statuts normaux, rétablir les workers et faire la recette navigateur autorisée des trois compteurs, du premier passage, du redémarrage et de la reprise. Conserver les sauvegardes et les rapports hors Git et quitter le shell dédié pour supprimer les deux profils clients temporaires. [Réouverture et contrôle des accès](deploy_beta.md)

## Vérifications locales du 19 novembre 2026

| Vérification | Résultat |
| --- | --- |
| MySQL local, suites ciblées des modules 1 à 3, destinations et commandes de production | 163 tests, 1 824 assertions réussies |
| MariaDB locale, suite d’intégration du module 3 | 7 tests, 381 assertions réussies |
| Contrat de maintenance de `deploy_beta.sh`, HTTP et commandes d’exploitation simulés | 42 scénarios réussis |
| Conteneur Symfony de test, PHPStan et PHP CS Fixer sur les six fichiers PHP du diff | Conformes |

Le scénario de production utilise une base jetable créée par la suite. Il vérifie les assertions absentes ou erronées, le consentement distinct, le refus d’un ancien plan sous verrou, l’import fixe, le second passage sans écriture et l’activation de trois indicateurs. Les captures incluent un cours, une dictée publiée avec sa version, des tentatives et une progression. PHP et le sélecteur ont aussi été exercés par les tests existants de reprise, de concurrence et de rollback. Le premier passage MySQL a signalé une différence d’ordre des clés du tableau de comptages dans deux assertions du nouveau test. Après correction de cette comparaison par nom de table, toute la sélection a été rejouée avec succès.

Le correctif documentaire a été vérifié hors ligne. Les 14 blocs Bash passent `bash -n`. Avec `curl` simulé, la fonction extraite de ce document accepte les 12 réponses 503 attendues, refuse chacun des trois témoins absent ou symbolique avant la première requête, et s’arrête sur 302 ou erreur réseau. Le lancement simulé du script s’interrompt sur échec du contrôle ou du script. Le lecteur d’options MariaDB 11.4.9 a restitué à l’identique un mot de passe fictif avec caractères spéciaux depuis le profil généré.

Aucune mesure distante de la maintenance, du schéma, du contenu ni des droits Hostinger n’a été effectuée pendant cette préparation.

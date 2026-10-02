#!/bin/bash

set -euo pipefail

PROJECT_DIR="/home/u849885333/domains/orthogram.fr/public_html/beta"
ENV_FILE="/home/u849885333/domains/orthogram.fr/.env.beta"
MAINTENANCE_FILE="/home/u849885333/domains/orthogram.fr/.orthogram-maintenance"
SHARED_ROOT="/home/u849885333/domains/orthogram.fr/public_html/shared"
LEGACY_COURSES="$SHARED_ROOT/courses"
SHARED_AVATARS="$SHARED_ROOT/avatars"
PRIVATE_COURSES="/home/u849885333/domains/orthogram.fr/private/orthogram/courses"
READY_FILE="$PRIVATE_COURSES/.orthogram-private-ready"

fail() { echo "STOP: $*" >&2; exit 1; }

# This script does not establish maintenance or prove HTTP isolation. See the runbook.
# Keep maintenance active on any failure and until the post-deploy HTTP acceptance.
MODE="${1:---deploy}"
[[ "$MODE" == "--initial-switch" || "$MODE" == "--deploy" ]] || fail "Use --initial-switch or --deploy"
[[ -f "$MAINTENANCE_FILE" ]] || fail "Create $MAINTENANCE_FILE and verify HTTP 503 before deployment"
[[ "${ORTHOGRAM_MAINTENANCE_CONFIRMED:-}" == 1 ]] || fail "Stop HTTP traffic/uploads and workers first; then set ORTHOGRAM_MAINTENANCE_CONFIRMED=1"
[[ "${ORTHOGRAM_PRIVATE_STORAGE_VERIFIED:-}" == 1 ]] || fail "Verify PHP access, ownership and all web roots/aliases; then set ORTHOGRAM_PRIVATE_STORAGE_VERIFIED=1"
[[ "${ORTHOGRAM_LEGACY_HTTP_BLOCKED:-}" == 1 ]] || fail "Block retained public sources on ALL hosts/aliases first; then set ORTHOGRAM_LEGACY_HTTP_BLOCKED=1"

cd "$PROJECT_DIR"

echo "0. Stockage privé des cours (avatars inchangés)"
# No recursive chmod here: provision access for the observed PHP identity beforehand.
[[ -d "$PRIVATE_COURSES" && ! -L "$PRIVATE_COURSES" ]] || fail "Provision the verified private directory first"
[[ "$(realpath "$PRIVATE_COURSES")" == "$PRIVATE_COURSES" ]] || fail "Private storage ancestors must not be symlinks"
[[ -r "$ENV_FILE" ]] || fail "Missing .env.beta"

# Validate the configuration BEFORE copying or compiling it; never print its secrets.
php -r 'require "vendor/autoload.php"; $env = (new Symfony\Component\Dotenv\Dotenv())->parse(file_get_contents($argv[1])); if (($env["COURSE_STORAGE_DIR"] ?? null) !== $argv[2]) { fwrite(STDERR, "COURSE_STORAGE_DIR in .env.beta must match the private directory\n"); exit(1); }' "$ENV_FILE" "$PRIVATE_COURSES"

# A real public/courses directory containing data needs a separately inventoried transfer.
# It is never removed automatically. The ordinary tracked placeholders are harmless.
if [[ -e public/courses && ! -L public/courses ]]; then
    if find public/courses -type f ! -name .gitkeep ! -name .htaccess -print -quit | grep -q .; then
        fail "Untransferred real public/courses directory: inventory and copy separately"
    fi
    if find public/courses -type l -print -quit | grep -q .; then
        fail "Unexpected nested link in public/courses"
    fi
fi

if [[ "$MODE" == "--initial-switch" ]]; then
    [[ ! -e "$READY_FILE" ]] || fail "Initial switch already verified; use --deploy"
    [[ -d "$LEGACY_COURSES" ]] || fail "Missing legacy shared courses"
    if [[ -L public/courses ]]; then
        [[ "$(realpath public/courses)" == "$(realpath "$LEGACY_COURSES")" ]] || fail "Unexpected public/courses target"
    fi
    TRANSFER=(php bin/transfer-course-files.php
        "--source-files=$LEGACY_COURSES/files"
        "--source-audios=$LEGACY_COURSES/audios"
        "--source-videos=$LEGACY_COURSES/videos"
        "--destination=$PRIVATE_COURSES")
    "${TRANSFER[@]}"              # Full conflict/integrity preflight, no write.
    (umask 007; "${TRANSFER[@]}" --copy)
    "${TRANSFER[@]}" --verify-only # Any missing or different file stops the switch.
    if [[ -L public/courses ]]; then
        unlink public/courses     # Remove the verified link only, never its target.
    fi
else
    [[ -f "$READY_FILE" && ! -L "$READY_FILE" ]] || fail "Run the initial verified switch first"
    [[ "$(cat "$READY_FILE")" == 'orthogram-private-courses-v1' ]] || fail "Invalid switch marker"
    [[ ! -L public/courses ]] || fail "Legacy public/courses link reintroduced; inspect it before proceeding"
    # Subsequent deploys NEVER read/copy the legacy source inventory.
fi
for category in files audios videos; do
    [[ -d "$PRIVATE_COURSES/$category" && ! -L "$PRIVATE_COURSES/$category" ]] || fail "Missing or linked private category: $category"
    [[ -r "$PRIVATE_COURSES/$category" && -w "$PRIVATE_COURSES/$category" && -x "$PRIVATE_COURSES/$category" ]] || fail "Deployment user cannot access private category: $category"
done

echo "0bis. Préservation des avatars utilisateur"
mkdir -p "$SHARED_AVATARS"

if [ -d "public/images/avatars" ] && [ ! -L "public/images/avatars" ]; then
    if find public/images/avatars -type f ! -name ".gitkeep" ! -name ".htaccess" -print -quit | grep -q .; then
        cp -a public/images/avatars/. "$SHARED_AVATARS/"
    fi

    rm -rf public/images/avatars
fi

if [ -L "public/images/avatars" ]; then
    rm public/images/avatars
fi

ln -s "$SHARED_AVATARS" public/images/avatars

cat > "$SHARED_AVATARS/.htaccess" <<'EOF'
<FilesMatch "\.(php|php3|php4|php5|php7|php8|phtml|phar)$">
Require all denied
</FilesMatch>

Options -Indexes
EOF

echo "1. Restauration du .env.local"
cp "$ENV_FILE" .env.local
export COURSE_STORAGE_DIR="$PRIVATE_COURSES"

echo "2. Création du .htaccess racine"
# Same filesystem as the active file: rename only after a complete successful write.
HTACCESS_TMP="$(mktemp "$PROJECT_DIR/.htaccess.tmp.XXXXXX")"
trap 'rm -f -- "$HTACCESS_TMP"' EXIT
cat > "$HTACCESS_TMP" <<'EOF'
# Shared maintenance switch, outside the directory replaced by Hostinger publication.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond /home/u849885333/domains/orthogram.fr/.orthogram-maintenance -f
    RewriteRule ^ - [R=503,L]
</IfModule>
ErrorDocument 503 "Maintenance en cours. Merci de reessayer dans quelques instants."

SetEnv APP_ENV prod
SetEnv APP_DEBUG 0

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    RewriteCond %{THE_REQUEST} /public/([^\s?]*) [NC]
    RewriteRule ^ %1 [L,NE,R=302]

    RewriteRule ^((?!public/).*)$ public/$1 [L,NC]
</IfModule>
EOF
chmod 644 "$HTACCESS_TMP"
mv -f -- "$HTACCESS_TMP" "$PROJECT_DIR/.htaccess"
trap - EXIT
unset HTACCESS_TMP

echo "3. Suppression de l'ancien .env.local.php"
rm -f .env.local.php

echo "4. Compilation de l'environnement Symfony"
composer dump-env prod

echo "5. Nettoyage du cache Symfony"
rm -rf var/cache/*
php bin/console cache:clear --env=prod --no-debug
php bin/console cache:warmup --env=prod --no-debug

echo "6. Exécution des migrations Doctrine"
php bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction

echo "6bis. Vérification des références pédagogiques (lecture seule)"
php bin/console app:courses:verify-files --env=prod --no-debug

echo "7. Réparation des avatars utilisateur"
php bin/console app:avatars:repair --env=prod --no-debug

echo "7bis. Nettoyage du cache LiipImagine"
mkdir -p public/media/cache
php bin/console liip:imagine:cache:remove --env=prod --no-debug || true

echo "8. Compilation des assets"
php bin/console asset-map:compile --env=prod --no-debug

echo "9. Permissions"
chmod -R 775 var
chmod -R 775 public/media
find "$SHARED_AVATARS" -type d -exec chmod 755 {} \;
find "$SHARED_AVATARS" -type f -exec chmod 644 {} \;

if [[ "$MODE" == "--initial-switch" ]]; then
    # Marker outside mappings, only after transfer, DB-reference checks and preparation succeed.
    (umask 007; printf '%s\n' 'orthogram-private-courses-v1' > "$READY_FILE")
fi
echo "Code bêta préparé. Conserver la maintenance jusqu'à la recette HTTP et PHP décrite dans la procédure."
echo "Témoin conservé : $MAINTENANCE_FILE. Retrait manuel uniquement lors de la réouverture contrôlée."

#!/usr/bin/env bash
# Mirrors .github/workflows/deploy.yml — rsync drupal/ to the server,
# write settings.php / .env, then start the Drupal stack.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/env.sh
source "$SCRIPT_DIR/../lib/env.sh"

require_ssh
require DRUPAL_DOMAIN
require VARNISH_SECRET
require DRUPAL_HASH_SALT
require DRUPAL_DB_DATABASE
require DRUPAL_DB_USERNAME
require DRUPAL_DB_PASSWORD
require DRUPAL_DB_HOST
require DRUPAL_DB_PORT
require DRUPAL_TRUSTED_HOST
require NEXTJS_DOMAIN
require NEXT_PUBLIC_API_URL
require NEXT_PUBLIC_SITE_URL
require REVALIDATE_SECRET
require OAUTH_PRIVATE_KEY
require OAUTH_PUBLIC_KEY

echo "==> Pushing drupal/ and docker-compose.production.yml to $REMOTE"
rsync -e "ssh ${SSH_KEY_FLAG[*]} -o StrictHostKeyChecking=accept-new" \
  --rsync-path="sudo rsync" \
  -rlvz --no-perms --chmod=ugo=rwX --delete \
  --include="docker-compose.production.yml" \
  --include="drupal/" \
  --exclude="drupal/.ddev/" \
  --exclude="drupal/vendor/" \
  --exclude="drupal/web/sites/default/files/" \
  --exclude="drupal/web/sites/default/settings.php" \
  --exclude="drupal/web/sites/default/settings.local.php" \
  --exclude="drupal/web/sites/default/settings.ddev.php" \
  --include="drupal/**" \
  --exclude="*" \
  "$DPLOY_REPO_ROOT/" "$REMOTE:$REMOTE_DRUPAL_PATH/"

echo "==> Building and configuring on $REMOTE"
ssh "${SSH_KEY_FLAG[@]}" -o StrictHostKeyChecking=accept-new "$REMOTE" \
  DRUPAL_DOMAIN="$DRUPAL_DOMAIN" \
  VARNISH_SECRET="$VARNISH_SECRET" \
  DRUPAL_HASH_SALT="$DRUPAL_HASH_SALT" \
  DRUPAL_DB_DATABASE="$DRUPAL_DB_DATABASE" \
  DRUPAL_DB_USERNAME="$DRUPAL_DB_USERNAME" \
  DRUPAL_DB_PASSWORD="$DRUPAL_DB_PASSWORD" \
  DRUPAL_DB_HOST="$DRUPAL_DB_HOST" \
  DRUPAL_DB_PORT="$DRUPAL_DB_PORT" \
  DRUPAL_TRUSTED_HOST="$DRUPAL_TRUSTED_HOST" \
  NEXTJS_DOMAIN="$NEXTJS_DOMAIN" \
  NEXT_PUBLIC_API_URL="$NEXT_PUBLIC_API_URL" \
  NEXT_PUBLIC_SITE_URL="$NEXT_PUBLIC_SITE_URL" \
  REVALIDATE_SECRET="$REVALIDATE_SECRET" \
  OAUTH_PRIVATE_KEY="$OAUTH_PRIVATE_KEY" \
  OAUTH_PUBLIC_KEY="$OAUTH_PUBLIC_KEY" \
  REMOTE_DRUPAL_PATH="$REMOTE_DRUPAL_PATH" \
  bash -s <<'REMOTE_EOF'
set -euo pipefail
cd "$REMOTE_DRUPAL_PATH"

# --- .env for docker-compose ---
sudo tee .env > /dev/null <<ENV
VARNISH_SECRET=$VARNISH_SECRET
DRUPAL_DOMAIN=$DRUPAL_DOMAIN
NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL
NEXT_PUBLIC_SITE_URL=$NEXT_PUBLIC_SITE_URL
NEXTJS_DOMAIN=$NEXTJS_DOMAIN
REVALIDATE_SECRET=$REVALIDATE_SECRET
NEXTJS_REVALIDATE_URL=https://$NEXTJS_DOMAIN/api/revalidate
ENV

# --- Ensure file dirs exist ---
sudo mkdir -p files/public files/private

# --- Fix ownership / perms after rsync ---
sudo chown -R 1000:1000 drupal/ files/

# --- Network + start ---
sudo docker network create traefik_net 2>/dev/null || true
sudo docker compose -p programhub \
  -f docker-compose.production.yml \
  up -d --force-recreate

sleep 10

# --- Container-side perm fixes ---
sudo docker exec -u 0 -t programhub-php-1 find /var/www/html/web/sites -type d -exec chmod 755 {} \;
sudo docker exec -u 0 -t programhub-php-1 find /var/www/html/web/sites -type f -exec chmod 644 {} \;
sudo docker exec -u 0 -t programhub-php-1 chown -R wodby:wodby /var/www/html/web/sites
sudo docker exec -u 0 -t programhub-php-1 chmod -R 777 /var/www/html/web/sites/default/files || true

# --- Composer ---
sudo docker exec -u 0 -t programhub-php-1 composer install --no-dev --optimize-autoloader

# --- settings.php ---
sudo cp -f drupal/web/sites/default/default.settings.php drupal/web/sites/default/settings.php
sudo tee -a drupal/web/sites/default/settings.php > /dev/null <<SETTINGS

\$databases['default']['default'] = [
  'database' => '$DRUPAL_DB_DATABASE',
  'username' => '$DRUPAL_DB_USERNAME',
  'password' => '$DRUPAL_DB_PASSWORD',
  'host' => '$DRUPAL_DB_HOST',
  'port' => '$DRUPAL_DB_PORT',
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
  'pdo' => [
    \PDO::MYSQL_ATTR_SSL_CA => '',
    \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => FALSE,
  ],
];

\$settings['hash_salt'] = '$DRUPAL_HASH_SALT';
\$settings['trusted_host_patterns'] = [
  '$DRUPAL_TRUSTED_HOST',
];
\$settings['config_sync_directory'] = '../config/sync';
\$settings['file_private_path'] = '/var/www/private';

// Redis - uncomment once the module is enabled.
// \$settings['redis.connection']['host'] = 'redis';
// \$settings['redis.connection']['port'] = 6379;
// \$settings['cache']['default'] = 'cache.backend.redis';

\$settings['reverse_proxy'] = TRUE;
\$settings['reverse_proxy_addresses'] = ['varnish'];
SETTINGS

sudo chown 1000:1000 drupal/web/sites/default/settings.php

# --- simple_oauth keys ---
sudo mkdir -p drupal/keys
printf '%s\n' "$OAUTH_PRIVATE_KEY" | sudo tee drupal/keys/private.key > /dev/null
printf '%s\n' "$OAUTH_PUBLIC_KEY"  | sudo tee drupal/keys/public.key  > /dev/null
sudo chown 1000:1000 drupal/keys/*.key
sudo chmod 600 drupal/keys/private.key
sudo chmod 644 drupal/keys/public.key

# --- yq + services.yml ---
if ! command -v yq &> /dev/null; then
  wget -q https://github.com/mikefarah/yq/releases/download/v4.35.1/yq_linux_amd64 -O /tmp/yq
  chmod +x /tmp/yq
  sudo mv /tmp/yq /usr/local/bin/yq
fi

SERVICES_FILE=drupal/web/sites/default/services.yml
if [ ! -f "$SERVICES_FILE" ]; then
  sudo cp drupal/web/sites/default/default.services.yml "$SERVICES_FILE"
fi

sudo DRUPAL_DOMAIN="$DRUPAL_DOMAIN" NEXTJS_DOMAIN="$NEXTJS_DOMAIN" yq -i '
  .parameters.["cors.config"].enabled = true |
  .parameters.["cors.config"].allowedHeaders = ["*"] |
  .parameters.["cors.config"].allowedMethods = ["GET", "POST", "PATCH", "OPTIONS", "DELETE"] |
  .parameters.["cors.config"].allowedOrigins = ["https://" + env(DRUPAL_DOMAIN), "https://" + env(NEXTJS_DOMAIN), "http://localhost:3000"] |
  .parameters.["cors.config"].supportsCredentials = true |
  .parameters.["session.storage.options"].cookie_domain = env(DRUPAL_DOMAIN)
' "$SERVICES_FILE"
sudo chown 1000:1000 "$SERVICES_FILE"

# --- updb / cim / cr ---
sudo docker compose -p programhub -f docker-compose.production.yml exec -T php bash -c "
  cd /var/www/html
  vendor/bin/drush deploy -y || true
"

# --- Cleanup ---
sudo docker image prune -f
REMOTE_EOF

echo "==> Drupal deploy complete"

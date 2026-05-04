#!/usr/bin/env bash
# Restore: upload the snapshot back to GCS and `gcloud sql import` it into
# the production Cloud SQL database. Destructive — Cloud SQL import drops
# and recreates objects defined in the dump.
#
# DPLOY_SNAPSHOT_ID + DPLOY_SNAPSHOT_ENV are set by `dploy restore`.
#
# One-time setup: the Cloud SQL instance's service account must have
# objectAdmin on the bucket. See .dploy/.env.production.example.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/env.sh
source "$SCRIPT_DIR/../lib/env.sh"

: "${DPLOY_SNAPSHOT_ID:?DPLOY_SNAPSHOT_ID must be set (run via 'dploy restore')}"

require GCP_PROJECT
require CLOUD_SQL_INSTANCE
require CLOUD_SQL_BUCKET
require DRUPAL_DB_DATABASE

PREFIX="${CLOUD_SQL_BUCKET_PREFIX:-dploy}"
GCS_URI="gs://${CLOUD_SQL_BUCKET}/${PREFIX}/${DPLOY_SNAPSHOT_ID}.sql.gz"
ARTIFACT_DIR="$DPLOY_REPO_ROOT/.dploy/artifacts"
LOCAL_FILE="$ARTIFACT_DIR/${DPLOY_SNAPSHOT_ID}.sql.gz"

for cmd in gcloud gsutil; do
  command -v "$cmd" >/dev/null || { echo "ERROR: $cmd not found in PATH" >&2; exit 1; }
done

# If we still have the bucket copy from capture, reuse it. Otherwise upload.
if gsutil -q stat "$GCS_URI"; then
  echo "==> Snapshot already in bucket: $GCS_URI"
else
  if [[ ! -f "$LOCAL_FILE" ]]; then
    echo "ERROR: snapshot artifact not found at $LOCAL_FILE and not in $GCS_URI" >&2
    exit 1
  fi
  echo "==> Uploading $LOCAL_FILE → $GCS_URI"
  gsutil cp "$LOCAL_FILE" "$GCS_URI"
fi

echo "==> Importing $GCS_URI into $CLOUD_SQL_INSTANCE/$DRUPAL_DB_DATABASE"
gcloud sql import sql "$CLOUD_SQL_INSTANCE" "$GCS_URI" \
  --project="$GCP_PROJECT" \
  --database="$DRUPAL_DB_DATABASE" \
  --quiet

# Optional post-import: SSH to the app server and run drush updb + cr inside
# the container. Skipped if REMOTE_DRUPAL_HOST isn't set — in that case run
# manually:  sudo docker exec programhub-php-1 ./vendor/bin/drush updb -y --root=/var/www/html/web
#            sudo docker exec programhub-php-1 ./vendor/bin/drush cr --root=/var/www/html/web
if [[ -n "${REMOTE_DRUPAL_HOST:-}" && -n "${REMOTE_DRUPAL_USER:-}" ]]; then
  require_ssh
  echo "==> Running drush updb + cr in programhub-php-1 on $REMOTE"
  ssh "${SSH_KEY_FLAG[@]}" -o StrictHostKeyChecking=accept-new "$REMOTE" \
    "sudo docker exec programhub-php-1 ./vendor/bin/drush updb -y --root=/var/www/html/web && \
     sudo docker exec programhub-php-1 ./vendor/bin/drush cr --root=/var/www/html/web"
else
  echo "==> Skipping post-import drush updb/cr (no REMOTE_DRUPAL_HOST). Run manually on the app server."
fi

echo "==> Restore complete"

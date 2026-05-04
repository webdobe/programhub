#!/usr/bin/env bash
# Capture: export the Cloud SQL database to GCS, then download to local.
# DPLOY_SNAPSHOT_ID is set by `dploy capture` and used to tag the artifact.
#
# Flow: gcloud sql export sql → gs://$BUCKET/$PREFIX/<id>.sql.gz
#       gsutil cp             → .dploy/artifacts/<id>.sql.gz

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/env.sh
source "$SCRIPT_DIR/../lib/env.sh"

: "${DPLOY_SNAPSHOT_ID:?DPLOY_SNAPSHOT_ID must be set (run via 'dploy capture')}"

require GCP_PROJECT
require CLOUD_SQL_INSTANCE
require CLOUD_SQL_BUCKET
require DRUPAL_DB_DATABASE

PREFIX="${CLOUD_SQL_BUCKET_PREFIX:-dploy}"
GCS_URI="gs://${CLOUD_SQL_BUCKET}/${PREFIX}/${DPLOY_SNAPSHOT_ID}.sql.gz"
ARTIFACT_DIR="$DPLOY_REPO_ROOT/.dploy/artifacts"
LOCAL_FILE="$ARTIFACT_DIR/${DPLOY_SNAPSHOT_ID}.sql.gz"
mkdir -p "$ARTIFACT_DIR"

for cmd in gcloud gsutil; do
  command -v "$cmd" >/dev/null || { echo "ERROR: $cmd not found in PATH" >&2; exit 1; }
done

echo "==> Exporting $DRUPAL_DB_DATABASE from $CLOUD_SQL_INSTANCE → $GCS_URI"
gcloud sql export sql "$CLOUD_SQL_INSTANCE" "$GCS_URI" \
  --project="$GCP_PROJECT" \
  --database="$DRUPAL_DB_DATABASE" \
  --offload

echo "==> Downloading $GCS_URI → $LOCAL_FILE"
gsutil cp "$GCS_URI" "$LOCAL_FILE"

echo "==> Captured database to $LOCAL_FILE ($(du -h "$LOCAL_FILE" | cut -f1))"
echo "    Bucket copy retained at $GCS_URI"

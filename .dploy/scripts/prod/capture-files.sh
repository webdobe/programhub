#!/usr/bin/env bash
# Capture: tar+stream the production files tree (public + private) down
# to the local artifacts directory.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/env.sh
source "$SCRIPT_DIR/../lib/env.sh"

require_ssh

: "${DPLOY_SNAPSHOT_ID:?DPLOY_SNAPSHOT_ID must be set (run via 'dploy capture')}"

ARTIFACT_DIR="$DPLOY_REPO_ROOT/.dploy/artifacts"
mkdir -p "$ARTIFACT_DIR"
LOCAL_FILE="$ARTIFACT_DIR/${DPLOY_SNAPSHOT_ID}.files.tar.gz"

REMOTE_PUB="${REMOTE_FILES_PUBLIC:-$REMOTE_DRUPAL_PATH/files/public}"
REMOTE_PRIV="${REMOTE_FILES_PRIVATE:-$REMOTE_DRUPAL_PATH/files/private}"

echo "==> Streaming $REMOTE:$REMOTE_PUB and $REMOTE_PRIV → $LOCAL_FILE"
ssh "${SSH_KEY_FLAG[@]}" -o StrictHostKeyChecking=accept-new "$REMOTE" \
  "sudo tar -czf - -C / ${REMOTE_PUB#/} ${REMOTE_PRIV#/}" > "$LOCAL_FILE"

echo "==> Captured files to $LOCAL_FILE ($(du -h "$LOCAL_FILE" | cut -f1))"

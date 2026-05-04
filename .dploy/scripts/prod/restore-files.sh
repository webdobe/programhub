#!/usr/bin/env bash
# Restore: stream a captured files tarball to production and untar it in place.
# Destructive — overwrites whatever is currently at REMOTE_FILES_*.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/env.sh
source "$SCRIPT_DIR/../lib/env.sh"

require_ssh

: "${DPLOY_SNAPSHOT_ID:?DPLOY_SNAPSHOT_ID must be set (run via 'dploy restore')}"

ARTIFACT_DIR="$DPLOY_REPO_ROOT/.dploy/artifacts"
LOCAL_FILE="$ARTIFACT_DIR/${DPLOY_SNAPSHOT_ID}.files.tar.gz"

if [[ ! -f "$LOCAL_FILE" ]]; then
  echo "ERROR: snapshot artifact not found at $LOCAL_FILE" >&2
  exit 1
fi

echo "==> Streaming $LOCAL_FILE to $REMOTE and untarring at /"
ssh "${SSH_KEY_FLAG[@]}" -o StrictHostKeyChecking=accept-new "$REMOTE" \
  "sudo tar -xzf - -C /" < "$LOCAL_FILE"

ssh "${SSH_KEY_FLAG[@]}" -o StrictHostKeyChecking=accept-new "$REMOTE" \
  "sudo chown -R 1000:1000 ${REMOTE_FILES_PUBLIC:-$REMOTE_DRUPAL_PATH/files/public} ${REMOTE_FILES_PRIVATE:-$REMOTE_DRUPAL_PATH/files/private}"

echo "==> Files restore complete"

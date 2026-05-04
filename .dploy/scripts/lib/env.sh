#!/usr/bin/env bash
# Sourced by every production-side script. Loads .env.production.dploy and
# exports its variables, then validates the ones we always need.

set -euo pipefail

DPLOY_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
DPLOY_ENV_FILE="${DPLOY_ENV_FILE:-$DPLOY_REPO_ROOT/.dploy/.env.production.dploy}"

if [[ ! -f "$DPLOY_ENV_FILE" ]]; then
  echo "ERROR: $DPLOY_ENV_FILE not found." >&2
  echo "Copy .dploy/.env.production.example to .dploy/.env.production.dploy and fill it in." >&2
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "$DPLOY_ENV_FILE"
set +a

require() {
  local name="$1"
  if [[ -z "${!name:-}" ]]; then
    echo "ERROR: required variable $name is empty in $DPLOY_ENV_FILE" >&2
    exit 1
  fi
}

# Call this from any script that needs to SSH/rsync to the server.
# Validates SSH-related vars + populates $REMOTE and $SSH_KEY_FLAG.
require_ssh() {
  require REMOTE_DRUPAL_HOST
  require REMOTE_DRUPAL_USER
  require REMOTE_DRUPAL_PATH

  SSH_KEY_FLAG=()
  if [[ -n "${SSH_PRIVATE_KEY_PATH:-}" ]]; then
    # Expand ~ / $HOME if the user wrote them literally.
    SSH_PRIVATE_KEY_PATH="${SSH_PRIVATE_KEY_PATH/#\~/$HOME}"
    if [[ ! -f "$SSH_PRIVATE_KEY_PATH" ]]; then
      echo "ERROR: SSH_PRIVATE_KEY_PATH points to a missing file: $SSH_PRIVATE_KEY_PATH" >&2
      exit 1
    fi
    SSH_KEY_FLAG=(-i "$SSH_PRIVATE_KEY_PATH")
  fi

  REMOTE="${REMOTE_DRUPAL_USER}@${REMOTE_DRUPAL_HOST}"
  export REMOTE
}

# Run a command on the remote host. Quote arguments carefully — the command
# is wrapped in `bash -lc` on the remote so we get a normal login shell.
remote_exec() {
  ssh "${SSH_KEY_FLAG[@]}" -o StrictHostKeyChecking=accept-new "$REMOTE" "bash -lc $(printf '%q' "$*")"
}

# Run a heredoc on the remote host. Usage: remote_run <<'EOF' ... EOF
remote_run() {
  ssh "${SSH_KEY_FLAG[@]}" -o StrictHostKeyChecking=accept-new "$REMOTE" "bash -s"
}

# Rsync a path from the local repo to the remote project path.
# Usage: rsync_push <relative_local> <relative_remote> [extra rsync args...]
rsync_push() {
  local src="$1"; shift
  local dst="$1"; shift
  rsync -e "ssh ${SSH_KEY_FLAG[*]} -o StrictHostKeyChecking=accept-new" \
    --rsync-path="sudo rsync" \
    -rlvz --no-perms --chmod=ugo=rwX --delete \
    "$@" \
    "$DPLOY_REPO_ROOT/$src" "$REMOTE:$REMOTE_DRUPAL_PATH/$dst"
}

export DPLOY_REPO_ROOT

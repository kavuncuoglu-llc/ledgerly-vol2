#!/usr/bin/env bash
# Chapter 43, Topic 6: the provider that is this machine. It runs the whole session flow (the manifest check, the stops, the results, the ledger) with no
# rented GPU and no money, which is how the session script is tested. GPU_SESSION_TRAIN_CMD is what "training" runs (it sees DATASET, CONFIG, VOLUME and
# RESULTS in its environment); GPU_SESSION_FAKE_UTILIZATION_FILE holds the utilization percent the idle stop reads. A local directory is not an encrypted
# volume, so it is refused unless GPU_SESSION_LOCAL_ALLOW_UNENCRYPTED=1 says you know that.

provider_create() {
  echo "local"
}

provider_volume_create() {
  mktemp -d "${TMPDIR:-/tmp}/gpu-session-volume.XXXXXX"
}

provider_volume_encrypted() {
  [ "${GPU_SESSION_LOCAL_ALLOW_UNENCRYPTED:-0}" = "1" ]
}

provider_issue_credentials() {
  return 0
}

provider_set_deadline() {
  return 0
}

provider_train() {
  export DATASET="$4" CONFIG="$3" VOLUME="$2" RESULTS="$5"
  exec bash -c "${GPU_SESSION_TRAIN_CMD:-echo no training command: set GPU_SESSION_TRAIN_CMD >&2; exit 1}"
}

provider_gpu_utilization() {
  if [ -n "${GPU_SESSION_FAKE_UTILIZATION_FILE:-}" ] && [ -f "$GPU_SESSION_FAKE_UTILIZATION_FILE" ]; then
    cat "$GPU_SESSION_FAKE_UTILIZATION_FILE"
  else
    echo 100
  fi
}

provider_push_results() {
  local volume="$2" destination="${3#file://}"

  mkdir -p "$destination"

  if [ -d "$volume/results" ]; then
    cp -R "$volume/results/." "$destination/"
  fi
}

provider_volume_destroy() {
  rm -rf "$1"
}

provider_stop() {
  return 0
}

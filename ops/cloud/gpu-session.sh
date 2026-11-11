#!/usr/bin/env bash
# Appendix L.7: a rented cloud GPU for an hour. Run it on the instance, from the repository root.
#
#   ./ops/cloud/gpu-session.sh         check the NVIDIA driver, install nvidia-container-toolkit, write .env, start the vllm profile, print ai:doctor
#   ./ops/cloud/gpu-session.sh --down  stop the stack (the model download stays on disk); then stop or delete the instance in the provider console
#   ./ops/cloud/gpu-session.sh train --provider=P --region=R --gpu=G --dataset=NAME@REV --config=FILE --max-hours=H --results=DEST [--idle-stop=10m] [--interruptible]
#                                      Chapter 43, Topic 6: a rented training session with the stops built in. Run it from the machine that has the app and the
#                                      dataset manifests, not on the instance: it refuses a dataset whose manifest does not allow the provider and region, makes
#                                      an encrypted volume and destroys it, hands the instance short-lived credentials, stops the machine at a hard time limit
#                                      and when the GPU has been idle, pushes only the results, and writes the session to the cost ledger. Providers are
#                                      ops/cloud/providers/<name>.sh (template.sh lists what one must define; local.sh runs the whole flow with no machine).
#
# The instance bills until you stop it. Set a budget alert with the provider before you start.
#
# Secrets: HF_TOKEN (a gated model) is read from your shell and passed through by Compose; this script never writes it anywhere.
# VLLM_API_KEY is generated into .env (mode 600) the first time and kept; it is never printed.
# Tested here with `bash -n` and shellcheck only: the repository's author had no GPU. Written from Appendix L.7 and NVIDIA's install guide.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

COMPOSE=(docker compose --env-file .env -f ops/compose/base.yml -f ops/compose/vllm.yml --profile vllm)
WAIT_SECONDS="${GPU_SESSION_WAIT_SECONDS:-1500}"

say() { printf '==> %s\n' "$*"; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

usage() {
  sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'
}

need_root() {
  if [ "$(id -u)" -eq 0 ]; then
    "$@"
  else
    sudo "$@"
  fi
}

check_driver() {
  command -v nvidia-smi >/dev/null 2>&1 || die "nvidia-smi not found. Choose an Ubuntu 24.04 image with the NVIDIA driver preinstalled, or install the driver and reboot (Appendix L.7)."
  nvidia-smi --query-gpu=name,memory.total,driver_version --format=csv,noheader || die "nvidia-smi found no GPU. Is this a GPU instance?"
}

check_docker() {
  command -v docker >/dev/null 2>&1 || die "docker not found. Install it (sudo apt install docker.io docker-compose-v2) or use an image that has it."
  docker compose version >/dev/null 2>&1 || die "the docker compose plugin is missing (sudo apt install docker-compose-v2)."
}

install_container_toolkit() {
  if docker info --format '{{json .Runtimes}}' 2>/dev/null | grep -q nvidia; then
    say "nvidia-container-toolkit is already configured for Docker"
    return
  fi

  say "installing nvidia-container-toolkit"

  need_root apt-get update -y
  need_root apt-get install -y curl gnupg ca-certificates

  curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey | need_root gpg --dearmor --yes -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg
  curl -fsSL https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list \
    | sed 's#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g' \
    | need_root tee /etc/apt/sources.list.d/nvidia-container-toolkit.list >/dev/null

  need_root apt-get update -y

  if [ -n "${NVIDIA_CTK_VERSION:-}" ]; then
    need_root apt-get install -y "nvidia-container-toolkit=${NVIDIA_CTK_VERSION}" "nvidia-container-toolkit-base=${NVIDIA_CTK_VERSION}" "libnvidia-container-tools=${NVIDIA_CTK_VERSION}" "libnvidia-container1=${NVIDIA_CTK_VERSION}"
  else
    need_root apt-get install -y nvidia-container-toolkit
  fi

  need_root nvidia-ctk runtime configure --runtime=docker
  need_root systemctl restart docker

  say "nvidia-container-toolkit $(nvidia-ctk --version 2>/dev/null | head -n 1)"
}

set_env() {
  local name="$1" value="$2"

  if grep -q "^${name}=" .env; then
    sed -i.bak "s|^${name}=.*|${name}=${value}|" .env
    rm -f .env.bak
  else
    printf '%s=%s\n' "$name" "$value" >> .env
  fi
}

env_value() {
  grep "^$1=" .env | head -n 1 | cut -d= -f2-
}

write_env() {
  if [ ! -f .env ]; then
    say "writing .env from .env.example"
    cp .env.example .env
  fi

  chmod 600 .env

  if [ -z "$(env_value VLLM_API_KEY)" ]; then
    say "generating VLLM_API_KEY in .env"
    set_env VLLM_API_KEY "$(openssl rand -hex 24)"
  fi

  set_env AI_LOCAL_PROVIDER vllm
  set_env VLLM_URL "http://localhost:$(env_value VLLM_PORT)/v1"
}

wait_until_healthy() {
  local waited=0 state

  say "waiting for vLLM (it downloads and loads the model: minutes on the first start, up to ${WAIT_SECONDS}s here)"

  while [ "$waited" -lt "$WAIT_SECONDS" ]; do
    state="$("${COMPOSE[@]}" ps --format '{{.Health}}' vllm 2>/dev/null | head -n 1 || true)"

    if [ "$state" = "healthy" ]; then
      say "vLLM is healthy"
      "${COMPOSE[@]}" logs vllm 2>/dev/null | grep -E 'KV cache|concurrency' | tail -n 2 || true
      return 0
    fi

    sleep 10
    waited=$((waited + 10))
  done

  "${COMPOSE[@]}" logs --tail 40 vllm || true
  die "vLLM was not healthy after ${WAIT_SECONDS}s. The log above says why (a wrong VLLM_REVISION, a gated model without HF_TOKEN, or too little memory: lower VLLM_MAX_MODEL_LEN)."
}

print_doctor() {
  say "ai:doctor"

  if command -v php >/dev/null 2>&1 && [ -d vendor ]; then
    php artisan ai:doctor --provider=vllm || true
    return
  fi

  say "PHP and vendor/ are not on this instance, so here is the same first check by hand (install them and run: php artisan ai:doctor --provider=vllm)"

  local root key
  root="http://localhost:$(env_value VLLM_PORT)"
  key="$(env_value VLLM_API_KEY)"

  curl -fsS "${root}/health" >/dev/null && echo "  /health ok"
  curl -fsS -H "Authorization: Bearer ${key}" "${root}/v1/models" | head -c 400 && echo
  curl -fsS "${root}/metrics" | grep -E '^vllm:cache_config_info' | head -n 1 || true
}

stop_stack() {
  say "stopping the stack (the Hugging Face cache volume is kept, so a restart does not download the model again)"

  "${COMPOSE[@]}" down

  say "Now stop or delete the instance in the provider console. It bills until you do."
}

SESSION_ID=""
SESSION_STARTED=0
SESSION_STATUS="not started"
SESSION_INSTANCE=""
SESSION_VOLUME=""
SESSION_OPENED=0
SESSION_WATCHDOG_PID=""
SESSION_TRAIN_PID=""
SESSION_RATE="0"
SESSION_PROVIDER=""
SESSION_GPU=""
SESSION_RESULTS=""
SESSION_DATASET=""
SESSION_RECEIPTS="${GPU_SESSION_RECEIPTS:-storage/ft/sessions}"
LEDGER_CMD="${GPU_SESSION_LEDGER_CMD:-php artisan ai:training-session}"
ALLOWS_CMD="${GPU_SESSION_ALLOWS_CMD:-php artisan dataset:allows}"
POLL_SECONDS="${GPU_SESSION_POLL_SECONDS:-5}"

duration_seconds() {
  case "$1" in
    *h) awk -v n="${1%h}" 'BEGIN { printf "%d", n * 3600 }' ;;
    *m) awk -v n="${1%m}" 'BEGIN { printf "%d", n * 60 }' ;;
    *s) awk -v n="${1%s}" 'BEGIN { printf "%d", n }' ;;
    *) die "a duration is a number and h, m or s (10m): got '$1'" ;;
  esac
}

hours_seconds() {
  awk -v n="$1" 'BEGIN { printf "%d", n * 3600 }'
}

train_usage() {
  usage
  exit 2
}

train_option() {
  case "$1" in
    --provider=*) SESSION_PROVIDER="${1#*=}" ;;
    --region=*) TRAIN_REGION="${1#*=}" ;;
    --gpu=*) SESSION_GPU="${1#*=}" ;;
    --interruptible) TRAIN_INTERRUPTIBLE=1 ;;
    --dataset=*) SESSION_DATASET="${1#*=}" ;;
    --config=*) TRAIN_CONFIG="${1#*=}" ;;
    --max-hours=*) TRAIN_MAX_HOURS="${1#*=}" ;;
    --idle-stop=*) TRAIN_IDLE_STOP="${1#*=}" ;;
    --results=*) SESSION_RESULTS="${1#*=}" ;;
    --rate=*) SESSION_RATE="${1#*=}" ;;
    --retries=*) TRAIN_RETRIES="${1#*=}" ;;
    *) train_usage ;;
  esac
}

train_parse() {
  TRAIN_REGION=""
  TRAIN_INTERRUPTIBLE=0
  TRAIN_CONFIG=""
  TRAIN_MAX_HOURS=""
  TRAIN_IDLE_STOP="10m"
  TRAIN_RETRIES=2
  SESSION_RATE="${GPU_SESSION_RATE_USD_PER_HOUR:-0}"

  local arg

  for arg in "$@"; do
    train_option "$arg"
  done

  local missing=""

  [ -n "$SESSION_PROVIDER" ] || missing="$missing --provider"
  [ -n "$TRAIN_REGION" ] || missing="$missing --region"
  [ -n "$SESSION_GPU" ] || missing="$missing --gpu"
  [ -n "$SESSION_DATASET" ] || missing="$missing --dataset"
  [ -n "$TRAIN_CONFIG" ] || missing="$missing --config"
  [ -n "$TRAIN_MAX_HOURS" ] || missing="$missing --max-hours"
  [ -n "$SESSION_RESULTS" ] || missing="$missing --results"

  [ -z "$missing" ] || die "a session needs:$missing (a hard time limit and a place for the results are not optional)"
  [ -f "$TRAIN_CONFIG" ] || die "no training config at $TRAIN_CONFIG"
}

load_provider() {
  local file
  file="$(dirname "${BASH_SOURCE[0]}")/providers/${SESSION_PROVIDER}.sh"

  [ -f "$file" ] || die "no provider script at $file: copy providers/template.sh and fill it in"

  # shellcheck source=/dev/null
  . "$file"
}

check_manifest() {
  # shellcheck disable=SC2086
  if ! $ALLOWS_CMD "$SESSION_DATASET" --provider="$SESSION_PROVIDER" --region="$TRAIN_REGION"; then
    die "refused: the manifest of $SESSION_DATASET does not allow $SESSION_PROVIDER in $TRAIN_REGION. Nothing was created."
  fi

  say "manifest allows provider and region: yes"
}

write_receipt() {
  local ended hours
  ended="$(date +%s)"
  hours="$(awk -v s="$SESSION_STARTED" -v e="$ended" 'BEGIN { printf "%.3f", (e - s) / 3600 }')"

  mkdir -p "$SESSION_RECEIPTS"

  printf '{"session":"%s","provider":"%s","region":"%s","gpu":"%s","dataset":"%s","config":"%s","results":"%s","status":"%s","started":%s,"ended":%s,"hours_billed":%s,"usd_per_hour":%s}\n' \
    "$SESSION_ID" "$SESSION_PROVIDER" "$TRAIN_REGION" "$SESSION_GPU" "$SESSION_DATASET" "$TRAIN_CONFIG" "$SESSION_RESULTS" "$SESSION_STATUS" \
    "$SESSION_STARTED" "$ended" "$hours" "$SESSION_RATE" > "$SESSION_RECEIPTS/$SESSION_ID.json"

  SESSION_HOURS="$hours"
}

train_cleanup() {
  local code=$?

  trap - EXIT INT TERM

  [ -z "$SESSION_WATCHDOG_PID" ] || kill "$SESSION_WATCHDOG_PID" 2>/dev/null || true
  [ -z "$SESSION_TRAIN_PID" ] || kill "$SESSION_TRAIN_PID" 2>/dev/null || true

  if [ -n "$SESSION_INSTANCE" ]; then
    [ -z "$SESSION_VOLUME" ] || provider_volume_destroy "$SESSION_VOLUME" || say "WARNING: the volume $SESSION_VOLUME was not destroyed: delete it in the provider console"
    provider_stop "$SESSION_INSTANCE" || say "WARNING: the machine $SESSION_INSTANCE did not stop: stop it in the provider console now"
  fi

  if [ "$SESSION_OPENED" -eq 1 ]; then
    write_receipt

    # shellcheck disable=SC2086
    $LEDGER_CMD close --session="$SESSION_ID" --provider="$SESSION_PROVIDER" --gpu="$SESSION_GPU" --hours="$SESSION_HOURS" --rate="$SESSION_RATE" --note="$SESSION_STATUS" \
      || say "WARNING: the ledger row was not written: the receipt is $SESSION_RECEIPTS/$SESSION_ID.json"

    say "run $SESSION_STATUS · volume destroyed · machine stopped · ${SESSION_HOURS} h billed"
  fi

  exit "$code"
}

start_machine() {
  SESSION_INSTANCE="$(provider_create "$TRAIN_REGION" "$SESSION_GPU" "$TRAIN_INTERRUPTIBLE")" || die "the provider created no machine"
  SESSION_VOLUME="$(provider_volume_create "$SESSION_INSTANCE")" || die "the provider created no volume"

  provider_volume_encrypted "$SESSION_VOLUME" || die "the volume $SESSION_VOLUME is not encrypted: refusing to put training data on it"

  provider_issue_credentials "$SESSION_DATASET" "$(( $(hours_seconds "$TRAIN_MAX_HOURS") + 1800 ))" || die "no short-lived credentials for $SESSION_DATASET"
  provider_set_deadline "$SESSION_INSTANCE" "$(hours_seconds "$TRAIN_MAX_HOURS")" || die "the machine could not be given its own shutdown deadline"

  say "encrypted volume created · scoped credentials valid $(awk -v n="$TRAIN_MAX_HOURS" 'BEGIN { printf "%.1f", n + 0.5 }')h"
}

open_ledger() {
  # shellcheck disable=SC2086
  $LEDGER_CMD open --session="$SESSION_ID" --provider="$SESSION_PROVIDER" --gpu="$SESSION_GPU" --rate="$SESSION_RATE" || say "WARNING: no ledger row could be opened: the receipt will say what the session cost"

  SESSION_OPENED=1

  say "checkpointing every 200 steps to $SESSION_RESULTS · ledger: training session row opened"
}

watch_training() {
  local deadline idle_limit started idle_for utilization
  deadline="$(hours_seconds "$TRAIN_MAX_HOURS")"
  idle_limit="$(duration_seconds "$TRAIN_IDLE_STOP")"
  started="$(date +%s)"
  idle_for=0

  while kill -0 "$SESSION_TRAIN_PID" 2>/dev/null; do
    if [ $(( $(date +%s) - started )) -ge "$deadline" ]; then
      say "hard time limit reached: stopping the run"
      SESSION_STATUS="stopped at the time limit"
      return 124
    fi

    utilization="$(provider_gpu_utilization "$SESSION_INSTANCE" 2>/dev/null || echo 100)"

    if [ "${utilization:-100}" -lt 5 ]; then
      idle_for=$(( idle_for + POLL_SECONDS ))
    else
      idle_for=0
    fi

    if [ "$idle_for" -ge "$idle_limit" ]; then
      say "the GPU has been idle for $TRAIN_IDLE_STOP: stopping the run and the machine"
      SESSION_STATUS="stopped idle"
      return 125
    fi

    sleep "$POLL_SECONDS"
  done

  return 0
}

run_training() {
  local attempt=0 code

  while :; do
    provider_train "$SESSION_INSTANCE" "$SESSION_VOLUME" "$TRAIN_CONFIG" "$SESSION_DATASET" "$SESSION_RESULTS" &
    SESSION_TRAIN_PID=$!

    watch_training
    code=$?

    if [ "$code" -ne 0 ]; then
      kill "$SESSION_TRAIN_PID" 2>/dev/null || true
      wait "$SESSION_TRAIN_PID" 2>/dev/null || true
      return "$code"
    fi

    wait "$SESSION_TRAIN_PID"
    code=$?

    if [ "$code" -eq 75 ] && [ "$TRAIN_INTERRUPTIBLE" -eq 1 ] && [ "$attempt" -lt "$TRAIN_RETRIES" ]; then
      attempt=$(( attempt + 1 ))
      say "the instance was reclaimed: resuming from the last checkpoint on a new machine ($attempt of $TRAIN_RETRIES)"
      provider_volume_destroy "$SESSION_VOLUME" || true
      provider_stop "$SESSION_INSTANCE" || true
      start_machine
      continue
    fi

    return "$code"
  done
}

train_session() {
  train_parse "$@"
  load_provider

  SESSION_ID="$(date -u +%Y%m%dT%H%M%S)-$$"
  SESSION_STARTED="$(date +%s)"

  check_manifest

  trap train_cleanup EXIT INT TERM

  start_machine
  open_ledger

  local code=0
  run_training || code=$?

  if [ "$code" -eq 0 ]; then
    provider_push_results "$SESSION_INSTANCE" "$SESSION_VOLUME" "$SESSION_RESULTS" || { SESSION_STATUS="finished, results not pushed"; exit 1; }
    SESSION_STATUS="finished"
    say "run finished · adapter and MLflow run pushed to $SESSION_RESULTS"
    exit 0
  fi

  if [ "$SESSION_STATUS" = "not started" ]; then
    SESSION_STATUS="failed (exit $code)"
  fi

  provider_push_results "$SESSION_INSTANCE" "$SESSION_VOLUME" "$SESSION_RESULTS" || true

  exit "$code"
}

main() {
  case "${1:-}" in
    --down)
      stop_stack
      ;;
    train)
      shift
      train_session "$@"
      ;;
    -h | --help)
      usage
      ;;
    "")
      check_docker
      check_driver
      install_container_toolkit
      write_env
      say "starting the vllm profile (image and model are pinned in .env)"
      "${COMPOSE[@]}" up -d
      wait_until_healthy
      print_doctor
      say "work through Chapter 24 or 25, then run: ./ops/cloud/gpu-session.sh --down"
      ;;
    *)
      usage
      exit 2
      ;;
  esac
}

main "$@"

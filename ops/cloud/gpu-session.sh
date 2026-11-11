#!/usr/bin/env bash
# Appendix L.7: a rented cloud GPU for an hour. Run it on the instance, from the repository root.
#
#   ./ops/cloud/gpu-session.sh         check the NVIDIA driver, install nvidia-container-toolkit, write .env, start the vllm profile, print ai:doctor
#   ./ops/cloud/gpu-session.sh --down  stop the stack (the model download stays on disk); then stop or delete the instance in the provider console
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
  sed -n '2,6p' "$0" | sed 's/^# \{0,1\}//'
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

main() {
  case "${1:-}" in
    --down)
      stop_stack
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

#!/usr/bin/env bash
# ops/llama/pull.sh: fetch the GGUF files llama-server serves (Chapter 23) into ./models, pinned to a Hugging Face commit and checked against a SHA-256.
#
#   ./ops/llama/pull.sh            # the chat model (ledgerly-support-q4_k_m.gguf, 4.9 GB)
#   ./ops/llama/pull.sh draft      # the 1B draft model for speculative decoding (1.3 GB)
#   ./ops/llama/pull.sh cpu        # the 4B model for the CPU profile (3.2 GB)
#   ./ops/llama/pull.sh all
#   ./ops/llama/pull.sh --check    # verify what is already in ./models; download nothing
#
# A file that is already there and matches is left alone, a partial download resumes. A file that downloads but does not match is deleted and the script fails.
# The models are Llama 3.1, Llama 3.2 and Gemma 3: read their licences before you serve them (config/ai.php records the reviewed date).
# These repositories are public. For a gated one, export HF_TOKEN in your shell; it is sent as a header and never written anywhere.

set -euo pipefail

MODELS_DIR="${MODELS_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/models}"
HF_BASE="${HF_BASE:-https://huggingface.co}"          # a mirror, or a local server in a test

# name | repository | pinned commit | file in the repository | saved as | sha256 | bytes
CATALOG=(
  "chat|bartowski/Meta-Llama-3.1-8B-Instruct-GGUF|bf5b95e96dac0462e2a09145ec66cae9a3f12067|Meta-Llama-3.1-8B-Instruct-Q4_K_M.gguf|ledgerly-support-q4_k_m.gguf|7b064f5842bf9532c91456deda288a1b672397a54fa729aa665952863033557c|4920739232"
  "draft|bartowski/Llama-3.2-1B-Instruct-GGUF|067b946cf014b7c697f3654f621d577a3e3afd1c|Llama-3.2-1B-Instruct-Q8_0.gguf|llama-3.2-1b-instruct-q8_0.gguf|432f310a77f4650a88d0fd59ecdd7cebed8d684bafea53cbff0473542964f0c3|1321083008"
  "cpu|bartowski/google_gemma-3-4b-it-GGUF|71506238f970075ca85125cd749c28b1b0eee84e|google_gemma-3-4b-it-Q6_K.gguf|gemma-3-4b-it-q6_k.gguf|ee175bbd0c5ab8b3fdc9b707c98df2d0086d5b22e4d79e4dbeec927385b8c659|3190604192"
)

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | cut -d' ' -f1
  else
    shasum -a 256 "$1" | cut -d' ' -f1
  fi
}

usage() {
  sed -n '2,13p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

fetch() {
  local repo="$1" commit="$2" file="$3" target="$4" expected="$5" bytes="$6"
  local path="${MODELS_DIR}/${target}"
  local url="${HF_BASE}/${repo}/resolve/${commit}/${file}"
  local header=()

  if [[ -n "${HF_TOKEN:-}" ]]; then
    header=(-H "Authorization: Bearer ${HF_TOKEN}")
  fi

  mkdir -p "${MODELS_DIR}"

  if [[ -f "${path}" && "$(wc -c < "${path}" | tr -d ' ')" == "${bytes}" ]] && [[ "$(sha256_of "${path}")" == "${expected}" ]]; then
    echo "ok       ${target} (already here, checksum matches)"
    return 0
  fi

  echo "download ${target} (${bytes} bytes) from ${repo}@${commit:0:8}"
  curl --fail --location --retry 3 --continue-at - --output "${path}.part" ${header[@]+"${header[@]}"} "${url}"

  if [[ "$(sha256_of "${path}.part")" != "${expected}" ]]; then
    rm -f "${path}.part"
    echo "FAILED   ${target}: the download does not match the pinned SHA-256; it was deleted" >&2
    return 1
  fi

  mv "${path}.part" "${path}"
  echo "ok       ${target}"
}

check() {
  local repo="$1" commit="$2" file="$3" target="$4" expected="$5" bytes="$6"
  local path="${MODELS_DIR}/${target}"

  if [[ ! -f "${path}" ]]; then
    echo "missing  ${target}"
    return 0
  fi

  if [[ "$(sha256_of "${path}")" == "${expected}" ]]; then
    echo "ok       ${target}"
    return 0
  fi

  echo "MISMATCH ${target}" >&2
  return 1
}

main() {
  local action="fetch" wanted="chat"

  case "${1:-chat}" in
    -h|--help) usage; return 0 ;;
    --check) action="check"; wanted="all" ;;
    chat|draft|cpu|all) wanted="$1" ;;
    *) echo "unknown target '$1'" >&2; usage >&2; return 2 ;;
  esac

  local row name repo commit file target sha bytes status=0

  for row in "${CATALOG[@]}"; do
    IFS='|' read -r name repo commit file target sha bytes <<< "${row}"

    if [[ "${wanted}" != "all" && "${wanted}" != "${name}" ]]; then
      continue
    fi

    "${action}" "${repo}" "${commit}" "${file}" "${target}" "${sha}" "${bytes}" || status=1
  done

  return "${status}"
}

main "$@"

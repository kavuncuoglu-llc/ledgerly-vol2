#!/usr/bin/env bash
# Chapter 25, Figure 25-3 and Topic 3: compile the 8B at FP8 into a TensorRT-LLM engine for THIS machine's GPU architecture.
#
#   ./ops/triton/build-trtllm.sh [--check]
#
# Needs an NVIDIA GPU, the container toolkit, and the model's Hugging Face files in ./ops/triton/hf/llama-8b (download them once at the pinned revision, see HF_DIR below).
# The engine runs only on the GPU architecture it was built for (an engine built on an H100 will not load on an L4) and only within max_batch_size 64, max_input_len 8192, max_seq_len 8704.
# Change the card, the context, the quantization or the toolkit version and you rebuild (about 20 minutes on an L4).
#
# TRT_IMAGE is PINNED: the tritonserver image that carries TensorRT-LLM (TRT_IMAGE_TAG in .env.example). The conversion scripts and flags below move between TensorRT-LLM releases:
# this is the shape of Figure 25-3, written from the book and NVIDIA's llama example, and it was NOT run by the author (no GPU). Appendix G has the current commands.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

TRT_IMAGE_TAG="${TRT_IMAGE_TAG:-26.07-trtllm-python-py3}"
TRT_IMAGE="${TRT_IMAGE:-nvcr.io/nvidia/tritonserver:${TRT_IMAGE_TAG}}"
HF_DIR="${HF_DIR:-ops/triton/hf}"
ENGINE_DIR="${ENGINE_DIR:-ops/triton/engines}"

die() { printf 'error: %s\n' "$*" >&2; exit 1; }

check() {
  command -v docker >/dev/null 2>&1 || die "docker not found"
  command -v nvidia-smi >/dev/null 2>&1 || die "nvidia-smi not found: the engine is built for the GPU in this machine, so it must have one"
  [ -d "${HF_DIR}/llama-8b" ] || die "${HF_DIR}/llama-8b is missing: download meta-llama/Llama-3.1-8B-Instruct at the revision in .env (VLLM_REVISION is for the FP8 repository; use the base model's own commit) with huggingface-cli download --local-dir"

  local architecture
  architecture="$(nvidia-smi --query-gpu=compute_cap --format=csv,noheader | head -n 1 | tr -d '.')"

  printf 'GPU architecture sm_%s (the engine will run on this architecture only)\n' "$architecture"
}

build() {
  mkdir -p "$ENGINE_DIR"

  docker run --gpus all --rm -v "$PWD/${HF_DIR}:/hf" -v "$PWD/${ENGINE_DIR}:/engines" "$TRT_IMAGE" bash -c '
    set -euo pipefail
    python3 /app/tensorrt_llm/examples/llama/convert_checkpoint.py --model_dir /hf/llama-8b --output_dir /ckpt --dtype float16 &&
    python3 /app/tensorrt_llm/quantization/quantize.py --model_dir /hf/llama-8b --qformat fp8 --output_dir /ckpt-fp8 &&
    trtllm-build --checkpoint_dir /ckpt-fp8 --output_dir /engines/llama-8b-fp8 \
        --max_batch_size 64 --max_input_len 8192 --max_seq_len 8704 \
        --paged_kv_cache enable --use_paged_context_fmha enable --gemm_plugin auto'

  printf 'engine in %s/llama-8b-fp8 (rank0.engine, config.json), built for this GPU architecture\n' "$ENGINE_DIR"
}

case "${1:-}" in
  --check)
    check
    ;;
  "")
    check
    build
    ;;
  *)
    printf 'usage: %s [--check]\n' "$0" >&2
    exit 2
    ;;
esac

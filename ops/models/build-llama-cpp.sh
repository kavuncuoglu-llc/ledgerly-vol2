#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
lock="${LLAMA_CPP_LOCK:-$root/training/llama-cpp.lock}"
dest="${LLAMA_CPP_DIR:-$root/training/tools/llama.cpp}"
cmake="${CMAKE:-cmake}"

tag="$(sed -n 's/^tag=//p' "$lock")"
commit="$(sed -n 's/^commit=//p' "$lock")"

if [ -z "$tag" ] || [ -z "$commit" ]; then
    echo "build-llama-cpp: $lock must name a tag= and a commit=" >&2
    exit 2
fi

if [ ! -d "$dest/.git" ]; then
    git clone --quiet --depth 1 --branch "$tag" https://github.com/ggml-org/llama.cpp "$dest"
fi

actual="$(git -C "$dest" rev-parse HEAD)"

if [ "$actual" != "$commit" ]; then
    echo "build-llama-cpp: $dest is at $actual, the lock pins $tag = $commit. Delete it and run again." >&2
    exit 1
fi

"$cmake" -S "$dest" -B "$dest/build" -DCMAKE_BUILD_TYPE=Release -DLLAMA_CURL=OFF -DLLAMA_BUILD_TESTS=OFF -DLLAMA_BUILD_EXAMPLES=OFF -DLLAMA_BUILD_SERVER=OFF -DLLAMA_BUILD_TOOLS=ON
"$cmake" --build "$dest/build" --target llama-quantize llama-imatrix -j "$(getconf _NPROCESSORS_ONLN)"

echo "llama.cpp $tag ($commit)"
echo "  $dest/build/bin/llama-quantize"
echo "  $dest/build/bin/llama-imatrix"
echo "  convert_hf_to_gguf.py and convert_lora_to_gguf.py are in $dest"

#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

for name in embed-batch.v1 embed-result.v1 eval-sample.v1 eval-conversations.v1 eval-scores.v1; do
    uv run datamodel-codegen \
        --input "../contracts/${name}.json" \
        --input-file-type jsonschema \
        --output-model-type pydantic_v2.BaseModel \
        --use-annotated \
        --disable-timestamp \
        --formatters ruff-check ruff-format \
        --output "contracts/$(echo "${name}" | tr '.-' '__').py"
done

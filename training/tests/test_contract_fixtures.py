import json
from pathlib import Path

import pytest
from pydantic import ValidationError

from contracts.embed_batch_v1 import EmbedBatch
from contracts.embed_result_v1 import EmbedResult
from contracts.eval_conversations_v1 import EvalConversations
from contracts.eval_sample_v1 import EvalSample
from contracts.eval_scores_v1 import EvalScores

FIXTURES = Path(__file__).resolve().parents[2] / "contracts" / "fixtures"
MODELS = {
    "embed-batch.v1": EmbedBatch,
    "embed-result.v1": EmbedResult,
    "eval-sample.v1": EvalSample,
    "eval-conversations.v1": EvalConversations,
    "eval-scores.v1": EvalScores,
}


def fixtures(prefix: str) -> list:
    return [
        pytest.param(schema, path, id=f"{schema}/{path.name}")
        for schema in MODELS
        for path in sorted((FIXTURES / schema).glob(f"{prefix}-*.json"))
    ]


def test_every_schema_has_valid_and_invalid_fixtures():
    for schema in MODELS:
        assert list((FIXTURES / schema).glob("valid-*.json")), schema
        assert list((FIXTURES / schema).glob("invalid-*.json")), schema


def test_every_fixture_is_named_valid_or_invalid():
    for schema in MODELS:
        for path in (FIXTURES / schema).glob("*.json"):
            assert path.name.startswith(("valid-", "invalid-")), path


@pytest.mark.parametrize(("schema", "path"), fixtures("valid"))
def test_python_accepts_the_valid_fixtures(schema, path):
    MODELS[schema].model_validate_json(path.read_text(), strict=True)


@pytest.mark.parametrize(("schema", "path"), fixtures("invalid"))
def test_python_rejects_the_invalid_fixtures(schema, path):
    with pytest.raises(ValidationError):
        MODELS[schema].model_validate_json(path.read_text(), strict=True)


def test_a_result_dumps_back_under_its_schema_field_name():
    result = EmbedResult.model_validate_json((FIXTURES / "embed-result.v1" / "valid-minimal.json").read_text())

    dumped = json.loads(result.model_dump_json(by_alias=True))

    assert dumped["schema"] == "embed-result.v1"
    assert "schema_" not in dumped


def test_the_schemas_json_files_are_the_ones_the_models_were_generated_from():
    for schema in MODELS:
        document = json.loads((FIXTURES.parent / f"{schema}.json").read_text())

        assert document["properties"]["schema"] == {"const": schema}

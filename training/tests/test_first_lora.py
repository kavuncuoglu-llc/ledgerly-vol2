import json
from types import SimpleNamespace

from scripts.first_lora import load_splits, lora_config, pick_device, pick_dtype, sft_config

FIXTURE = "data/fixtures/billing_sft_small.jsonl"


def torch_with(cuda: bool, mps: bool):
    return SimpleNamespace(
        cuda=SimpleNamespace(is_available=lambda: cuda),
        backends=SimpleNamespace(mps=SimpleNamespace(is_available=lambda: mps)),
        bfloat16="bf16",
        float32="f32",
    )


def test_device_prefers_cuda_then_mps_then_cpu():
    assert pick_device(torch_with(True, True)) == "cuda"
    assert pick_device(torch_with(False, True)) == "mps"
    assert pick_device(torch_with(False, False)) == "cpu"


def test_dtype_is_bfloat16_only_on_cuda():
    assert pick_dtype(torch_with(True, False), "cuda") == "bf16"
    assert pick_dtype(torch_with(False, True), "mps") == "f32"


def test_the_fixture_has_200_rows_in_prompt_and_completion_format():
    rows = [json.loads(line) for line in open(FIXTURE)]
    assert len(rows) == 200
    assert all(set(row) == {"prompt", "completion"} for row in rows)
    assert all(row["prompt"][-1]["role"] == "user" and row["completion"][0]["role"] == "assistant" for row in rows)


def test_the_fixture_is_split_ninety_ten_with_a_fixed_seed():
    first = load_splits(FIXTURE)
    second = load_splits(FIXTURE)
    assert (len(first["train"]), len(first["test"])) == (180, 20)
    assert first["test"]["completion"] == second["test"]["completion"]


def test_the_adapter_is_rank_16_on_all_linear_layers():
    config = lora_config()
    assert (config.r, config.lora_alpha, config.target_modules) == (16, 16, "all-linear")


def test_the_run_uses_the_books_settings():
    config = sft_config(report_to="none")
    assert config.learning_rate == 2e-4
    assert config.num_train_epochs == 2
    assert config.per_device_train_batch_size * config.gradient_accumulation_steps == 16
    assert (config.eval_steps, config.logging_steps) == (10, 5)


def test_the_config_builds_on_a_machine_without_a_bf16_device(monkeypatch):
    import torch

    monkeypatch.setattr(torch.cuda, "is_available", lambda: False)
    monkeypatch.setattr(torch.backends.mps, "is_available", lambda: False)

    config = sft_config(report_to="none")

    assert config.bf16 is False

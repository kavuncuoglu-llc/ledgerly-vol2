import json

import pytest

from scripts import train_estimate as te

MLX_LINES = [
    "Iter 10: Train loss 2.4, Learning Rate 1.000e-04, It/sec 1.0, Tokens/sec 200.0, Trained Tokens 1000, Peak mem 6.0 GB",  # noqa: E501
    "Iter 20: Train loss 2.1, Learning Rate 1.000e-04, It/sec 2.0, Tokens/sec 400.0, Trained Tokens 2000, Peak mem 6.5 GB",  # noqa: E501
    "Iter 30: Train loss 1.9, Learning Rate 1.000e-04, It/sec 2.0, Tokens/sec 600.0, Trained Tokens 3000, Peak mem 6.6 GB",  # noqa: E501
]


def test_the_kind_of_config_is_read_from_its_keys():
    assert te.kind_of({"model": "m", "fine_tune_type": "lora"}) == "mlx"
    assert te.kind_of({"base": "b", "trainer": {}}) == "peft"
    with pytest.raises(ValueError):
        te.kind_of({"x": 1})


def test_the_first_steps_are_discarded_as_warm_up_and_the_rest_averaged():
    reports = te.reports_from("mlx", MLX_LINES)

    rate, peak, per_step = te.sustained("mlx", reports, warmup=10)

    assert rate == 500.0 and peak == 6.6 and per_step == 100.0


def test_the_estimate_is_tokens_times_steps_over_the_sustained_rate():
    result = te.estimate("mlx", {"iters": 2236}, te.reports_from("mlx", MLX_LINES), warmup=10)

    assert result["total_tokens"] == 223_600
    assert result["seconds"] == 447 and result["duration"] == "0:07"
    assert result["peak_memory_gb"] == 6.6


def test_the_pytorch_trial_reads_the_step_records_the_training_script_prints():
    lines = [
        "device mps · dtype float32 · base b",
        'TRAIN_STEP {"step": 10, "loss": 1.0, "tokens_seen": 1000, "elapsed": 10.0}',
        'TRAIN_STEP {"step": 30, "loss": 0.9, "tokens_seen": 5000, "elapsed": 30.0}',
        'TRAIN_STEP {"step": 50, "loss": 0.8, "tokens_seen": 9000, "elapsed": 50.0}',
        "peak memory 11.5 GB · adapter in runs/x/adapter",
    ]
    cfg = {"trainer": {"num_train_epochs": 2, "per_device_train_batch_size": 2, "gradient_accumulation_steps": 8}}

    result = te.estimate("peft", cfg, te.reports_from("peft", lines), warmup=10, dataset_rows=3200)

    assert result["tokens_per_second"] == 200.0 and result["peak_memory_gb"] == 11.5
    assert result["steps"] == 400 and result["total_tokens"] == 72_000


def test_a_config_with_max_steps_counts_those():
    assert te.total_steps("peft", {"trainer": {"max_steps": 4, "per_device_train_batch_size": 1}}, None) == 4


def test_durations_are_hours_and_minutes():
    assert te.format_duration(3 * 3600 + 20 * 60) == "3:20"


def test_the_trial_command_runs_the_real_config_for_a_few_steps_in_a_scratch_directory(tmp_path):
    mlx = te.trial_command("mlx", "c.yaml", 50, str(tmp_path))
    peft = te.trial_command("peft", "c.yaml", 50, str(tmp_path))

    assert mlx[mlx.index("--iters") + 1] == "50" and mlx[mlx.index("--adapter-path") + 1] == str(tmp_path)
    assert peft[peft.index("--max-steps") + 1] == "50" and "none" in peft


def test_dry_run_prints_the_command_and_runs_nothing(tmp_path, capsys):
    config = tmp_path / "c.yaml"
    config.write_text("model: m\nfine_tune_type: lora\niters: 5\ndata: d\n")

    assert te.main(["--config", str(config), "--dry-run"]) == 0

    assert "mlx_lm lora --config" in capsys.readouterr().out
    assert json.dumps({}) == "{}"

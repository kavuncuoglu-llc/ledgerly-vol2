import json

import pytest

from scripts import run_mlx

REPORT = "Iter 10: Train loss 2.435, Learning Rate 1.000e-04, It/sec 2.100, Tokens/sec 400.250, Trained Tokens 1920, Peak mem 5.300 GB"  # noqa: E501
VALID = "Iter 200: Val loss 0.911, Val took 3.200s"


class Tracker:
    def __init__(self):
        self.logged, self.status = [], None

    def log(self, step, metrics):
        self.logged.append((step, metrics))

    def finish(self, status):
        self.status = status


class Process:
    def __init__(self, lines, code=0):
        self.stdout, self.code = iter(lines), code

    def wait(self):
        return self.code


def test_a_training_line_gives_its_loss_throughput_and_memory():
    parsed = run_mlx.parse_line(REPORT)

    assert parsed["step"] == 10
    assert parsed["metrics"] == {
        "train_loss": 2.435,
        "learning_rate": 1e-4,
        "iterations_per_second": 2.1,
        "tokens_per_second": 400.25,
        "trained_tokens": 1920.0,
        "peak_memory_gb": 5.3,
    }


def test_a_validation_line_gives_the_validation_loss_and_anything_else_gives_nothing():
    assert run_mlx.parse_line(VALID) == {"step": 200, "metrics": {"val_loss": 0.911}}
    assert run_mlx.parse_line("Loading pretrained model") is None


def test_the_losses_are_copied_to_the_tracker_and_the_output_is_echoed():
    tracker, echoed = Tracker(), []

    last = run_mlx.follow(["Loading", REPORT, VALID], tracker, echoed.append)

    assert [step for step, _ in tracker.logged] == [10, 200]
    assert last["val_loss"] == 0.911 and last["train_loss"] == 2.435
    assert echoed[0] == "Loading"


@pytest.mark.parametrize(("code", "status"), [(0, "FINISHED"), (1, "FAILED")])
def test_the_run_is_marked_finished_or_failed_by_the_exit_code(code, status):
    tracker = Tracker()
    seen = {}

    def popen(command, **kwargs):
        seen["command"] = command
        return Process([REPORT], code)

    assert (
        run_mlx.run("configs/billing-mlx.yaml", tracker, ["--iters", "5"], popen=popen, echo=lambda line: None) == code
    )
    assert tracker.status == status
    assert seen["command"][-3:] == ["configs/billing-mlx.yaml", "--iters", "5"]


def test_params_come_from_the_config_and_the_dataset_provenance(tmp_path):
    data = tmp_path / "mlx"
    data.mkdir()
    (data / "source.json").write_text(
        json.dumps({"source": "billing-v1", "train_file_hash": "sha256:abc", "tool_turns": "keep"})
    )
    config = tmp_path / "c.yaml"
    config.write_text(f"model: m\ndata: {data}\niters: 5\nlora_parameters:\n  rank: 16\n")

    params = run_mlx.load_params(str(config))

    assert params["lora_rank"] == 16 and params["iters"] == 5
    assert params["dataset"] == "billing-v1" and params["dataset_train_hash"] == "sha256:abc"

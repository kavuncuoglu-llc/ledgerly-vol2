import math

import numpy as np
import pytest
from train import batch, latest_checkpoint, lr_at, parse_args, pick_device, train


def test_learning_rate_warms_up_then_decays_to_a_tenth():
    assert lr_at(0) == pytest.approx(3e-4 / 200)
    assert lr_at(99) == pytest.approx(1.5e-4)
    assert lr_at(199) == pytest.approx(3e-4)
    assert lr_at(200) == pytest.approx(3e-4)
    assert lr_at(5_100) == pytest.approx(3e-5 + 0.5 * (3e-4 - 3e-5))
    assert lr_at(10_000) == pytest.approx(3e-5)
    assert lr_at(12_000) == pytest.approx(3e-5)
    assert all(lr_at(s) >= lr_at(s + 1) for s in range(200, 10_000, 97))


def test_targets_are_the_inputs_shifted_by_one():
    src = np.arange(1000, dtype=np.uint16)

    x, y = batch(src, ctx=16, size=8)

    assert x.shape == y.shape == (8, 16)
    assert (y == x + 1).all()


def test_device_choice_is_one_the_machine_has():
    assert pick_device() in {"cuda", "mps", "cpu"}


def write_toy_corpus(folder):
    folder.mkdir()
    pattern = np.array([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 0], dtype=np.uint16)  # 0 marks a document's end
    np.tile(pattern, 400).tofile(folder / "train.bin")
    np.tile(pattern, 40).tofile(folder / "val.bin")


def test_a_few_cpu_steps_on_a_toy_corpus_bring_the_loss_down_and_resume(tmp_path, monkeypatch):
    monkeypatch.delenv("MLFLOW_TRACKING_URI", raising=False)
    write_toy_corpus(tmp_path / "corpus")
    common = ["--corpus", str(tmp_path / "corpus"), "--tokenizer", str(tmp_path / "none.json"), "--device", "cpu"]
    common += ["--out", str(tmp_path / "ckpt"), "--batch", "8", "--ctx", "16", "--every", "10", "--eval-batches", "2"]

    first = train(parse_args([*common, "--steps", "20", "--warmup", "5", "--lr", "1e-3"]))
    resumed = train(parse_args([*common, "--steps", "30", "--warmup", "5", "--lr", "1e-3", "--resume", "auto"]))

    start = first["history"][0]
    assert start["step"] == 0
    assert abs(start["train_loss"] - math.log(13)) < 0.5
    assert first["history"][-1]["train_loss"] < start["train_loss"] - 1.0
    assert [r["step"] for r in resumed["history"]] == [30]
    assert latest_checkpoint(tmp_path / "ckpt").name == "step-30.pt"

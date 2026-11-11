import argparse
import json
import re
import subprocess
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from run_mlx import parse_line  # noqa: E402
from train_lora import STEP_MARKER  # noqa: E402

SCRIPTS = Path(__file__).resolve().parent


def kind_of(cfg: dict) -> str:
    if "model" in cfg and "fine_tune_type" in cfg:
        return "mlx"
    if "base" in cfg and "trainer" in cfg:
        return "peft"
    raise ValueError("the config is neither an mlx-lm config (model, fine_tune_type) nor a PyTorch one (base, trainer)")


def trial_command(kind: str, config: str, steps: int, scratch: str) -> list[str]:
    if kind == "mlx":
        return [
            sys.executable,
            "-m",
            "mlx_lm",
            "lora",
            "--config",
            config,
            "--iters",
            str(steps),
            "--steps-per-report",
            "10",
            "--steps-per-eval",
            str(10**9),
            "--val-batches",
            "2",
            "--save-every",
            str(10**9),
            "--adapter-path",
            scratch,
        ]
    return [
        sys.executable,
        str(SCRIPTS / "train_lora.py"),
        "--config",
        config,
        "--max-steps",
        str(steps),
        "--output-dir",
        scratch,
        "--report-to",
        "none",
    ]


def reports_from(kind: str, lines: list[str]) -> list[dict]:
    reports = []
    for line in lines:
        if kind == "mlx":
            parsed = parse_line(line)
            if parsed and "tokens_per_second" in parsed["metrics"]:
                reports.append({"step": parsed["step"], **parsed["metrics"]})
        elif line.startswith(STEP_MARKER):
            record = json.loads(line[len(STEP_MARKER) :])
            reports.append({"step": record["step"], "tokens_seen": record["tokens_seen"], "elapsed": record["elapsed"]})
        else:
            match = re.search(r"peak memory ([\d.]+) GB", line)
            if match:
                reports.append({"peak_memory_gb": float(match.group(1))})
    return reports


def sustained(kind: str, reports: list[dict], warmup: int) -> tuple[float, float, float]:
    peak = max((r.get("peak_memory_gb", 0.0) for r in reports), default=0.0)
    steps = [r for r in reports if "step" in r]
    if kind == "mlx":
        kept = [r for r in steps if r["step"] > warmup] or steps
        rate = sum(r["tokens_per_second"] for r in kept) / len(kept) if kept else 0.0
        per_step = steps[-1]["trained_tokens"] / steps[-1]["step"] if steps else 0.0
        return rate, peak, per_step
    kept = [r for r in steps if r["step"] > warmup]
    if len(kept) >= 2:
        span = kept[-1]["tokens_seen"] - kept[0]["tokens_seen"]
        seconds = kept[-1]["elapsed"] - kept[0]["elapsed"]
        rate = span / seconds if seconds > 0 else 0.0
    else:
        rate = 0.0
    per_step = steps[-1]["tokens_seen"] / steps[-1]["step"] if steps else 0.0
    return rate, peak, per_step


def total_steps(kind: str, cfg: dict, dataset_rows: int | None) -> int:
    if kind == "mlx":
        return int(cfg["iters"])
    trainer = cfg["trainer"]
    if trainer.get("max_steps", -1) > 0:
        return int(trainer["max_steps"])
    batch = trainer["per_device_train_batch_size"] * trainer.get("gradient_accumulation_steps", 1)
    return int((dataset_rows or 0) * trainer["num_train_epochs"] / batch)


def format_duration(seconds: float) -> str:
    minutes = int(round(seconds / 60))
    return f"{minutes // 60}:{minutes % 60:02d}"


def estimate(kind: str, cfg: dict, reports: list[dict], warmup: int, dataset_rows: int | None = None) -> dict:
    rate, peak, per_step = sustained(kind, reports, warmup)
    steps = total_steps(kind, cfg, dataset_rows)
    tokens = per_step * steps
    seconds = tokens / rate if rate else 0.0
    return {
        "tokens_per_second": round(rate, 1),
        "peak_memory_gb": peak,
        "total_tokens": int(tokens),
        "steps": steps,
        "seconds": round(seconds),
        "duration": format_duration(seconds),
    }


def count_rows(cfg: dict, kind: str) -> int | None:
    root = Path(cfg["data"])
    train = root / "train.jsonl"
    if kind == "mlx" or not train.exists():
        return None if not train.exists() else sum(1 for _ in train.open())
    return sum(1 for _ in train.open())


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Run the real configuration for a few steps and estimate the full run from the sustained throughput"
    )
    parser.add_argument("--config", required=True)
    parser.add_argument("--steps", type=int, default=50)
    parser.add_argument("--warmup", type=int, default=10, help="steps discarded while things warm up")
    parser.add_argument("--dry-run", action="store_true", help="print the command and stop")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args(argv)

    import yaml

    cfg = yaml.safe_load(Path(args.config).read_text())
    kind = kind_of(cfg)
    with tempfile.TemporaryDirectory() as scratch:
        command = trial_command(kind, args.config, args.steps, scratch)
        if args.dry_run:
            print(" ".join(command))
            return 0
        process = subprocess.run(command, capture_output=True, text=True)
    lines = (process.stdout + "\n" + process.stderr).splitlines()
    if process.returncode != 0:
        print("\n".join(lines[-15:]), file=sys.stderr)
        print(
            f"the {args.steps}-step trial failed (exit {process.returncode}): it fails here rather than three hours into a run",  # noqa: E501
            file=sys.stderr,
        )
        return process.returncode
    result = estimate(kind, cfg, reports_from(kind, lines), args.warmup, count_rows(cfg, kind))
    if args.json:
        print(json.dumps(result))
        return 0
    print(
        f"sustained {result['tokens_per_second']:,} tokens/s (steps {args.warmup}–{args.steps}) · peak memory {result['peak_memory_gb']} GB · "  # noqa: E501
        f"{result['total_tokens'] / 1e6:.2f}M tokens → estimated run time {result['duration']}"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())

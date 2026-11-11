import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

TRAIN = re.compile(
    r"Iter (\d+): Train loss ([\d.]+), Learning Rate ([\d.e+-]+), It/sec ([\d.]+), Tokens/sec ([\d.]+), Trained Tokens (\d+), Peak mem ([\d.]+) GB"  # noqa: E501
)
VALID = re.compile(r"Iter (\d+): Val loss ([\d.]+)")


def parse_line(line: str) -> dict | None:
    match = TRAIN.search(line)
    if match:
        step, loss, rate, its, tokens_per_second, trained, peak = match.groups()
        return {
            "step": int(step),
            "metrics": {
                "train_loss": float(loss),
                "learning_rate": float(rate),
                "iterations_per_second": float(its),
                "tokens_per_second": float(tokens_per_second),
                "trained_tokens": float(trained),
                "peak_memory_gb": float(peak),
            },
        }
    match = VALID.search(line)
    if match:
        return {"step": int(match.group(1)), "metrics": {"val_loss": float(match.group(2))}}
    return None


def mlx_command(config: str, extra: list[str] | None = None) -> list[str]:
    return [sys.executable, "-m", "mlx_lm", "lora", "--config", config, *(extra or [])]


def load_params(config: str) -> dict:
    import yaml

    cfg = yaml.safe_load(Path(config).read_text())
    params = {key: value for key, value in cfg.items() if not isinstance(value, dict)}
    params.update({f"lora_{key}": value for key, value in cfg.get("lora_parameters", {}).items()})
    source = Path(cfg["data"]) / "source.json"
    if source.exists():
        provenance = json.loads(source.read_text())
        params.update(
            {
                "dataset": provenance["source"],
                "dataset_train_hash": provenance["train_file_hash"],
                "tool_turns": provenance["tool_turns"],
            }
        )
    return params


class MlflowTracker:
    def __init__(self, experiment: str, run_name: str, params: dict):
        import mlflow

        self.mlflow = mlflow
        mlflow.set_experiment(experiment)
        self.run = mlflow.start_run(run_name=run_name)
        mlflow.log_params({key: str(value) for key, value in params.items()})

    def log(self, step: int, metrics: dict) -> None:
        self.mlflow.log_metrics(metrics, step=step)

    def finish(self, status: str) -> None:
        self.mlflow.end_run(status=status)


class NoTracker:
    def log(self, step: int, metrics: dict) -> None:
        pass

    def finish(self, status: str) -> None:
        pass


def follow(lines, tracker, echo=print) -> dict:
    last = {}
    for line in lines:
        echo(line.rstrip())
        parsed = parse_line(line)
        if parsed:
            tracker.log(parsed["step"], parsed["metrics"])
            last.update(parsed["metrics"])
    return last


def run(config: str, tracker, extra: list[str] | None = None, popen=subprocess.Popen, echo=print) -> int:
    process = popen(mlx_command(config, extra), stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, bufsize=1)
    try:
        follow(process.stdout, tracker, echo)
        code = process.wait()
    except BaseException:
        tracker.finish("KILLED")
        raise
    tracker.finish("FINISHED" if code == 0 else "FAILED")
    return code


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="mlx_lm.lora with its logged losses copied into MLflow; other options go on to mlx_lm (--iters 50)"
    )
    parser.add_argument("config")
    parser.add_argument("--experiment", default="billing-mlx")
    parser.add_argument("--run-name")
    parser.add_argument("--no-mlflow", action="store_true")
    args, extra = parser.parse_known_args(argv)
    tracker = (
        NoTracker()
        if args.no_mlflow
        else MlflowTracker(args.experiment, args.run_name or Path(args.config).stem, load_params(args.config))
    )
    return run(args.config, tracker, extra)


if __name__ == "__main__":
    sys.exit(main())

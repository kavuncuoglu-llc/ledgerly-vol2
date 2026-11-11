import argparse
import json
import os
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from data.chat_rows import render_prompt_completion  # noqa: E402

STEP_MARKER = "TRAIN_STEP "


def load_config(path: str) -> dict:
    import yaml

    with open(path) as handle:
        return yaml.safe_load(handle)


def pick_device(torch, requested: str = "auto") -> str:
    if requested == "cpu":
        return "cpu"
    if torch.cuda.is_available():
        return "cuda"
    if torch.backends.mps.is_available():
        return "mps"
    return "cpu"


def pick_dtype_name(torch, device: str) -> str:
    if device == "cuda":
        return "bfloat16" if torch.cuda.is_bf16_supported() else "float16"
    return "float32"


def lora_kwargs(cfg: dict) -> dict:
    return {**cfg["lora"], "target_modules": "all-linear", "task_type": "CAUSAL_LM"}


def trainer_kwargs(cfg: dict, dtype_name: str, device: str, overrides: dict | None = None) -> dict:
    kwargs = {**cfg["trainer"], "max_length": cfg["max_seq_length"], "include_num_input_tokens_seen": True}
    kwargs["bf16"] = dtype_name == "bfloat16"
    kwargs["fp16"] = dtype_name == "float16"
    if device == "cpu":
        kwargs["use_cpu"] = True
    kwargs.update({key: value for key, value in (overrides or {}).items() if value is not None})
    return kwargs


def render_dataset(dataset, tokenizer):
    return dataset.map(lambda row: render_prompt_completion(tokenizer, row), remove_columns=dataset.column_names)


def load_rendered(cfg: dict, tokenizer, limit: int | None = None):
    from datasets import load_dataset

    root = Path(cfg["data"])
    files = {"train": str(root / "train.jsonl")}
    if (root / "validation.jsonl").exists():
        files["validation"] = str(root / "validation.jsonl")
    elif (root / "valid.jsonl").exists():
        files["validation"] = str(root / "valid.jsonl")
    raw = load_dataset("json", data_files=files)
    if limit:
        raw = {name: split.select(range(min(limit, len(split)))) for name, split in raw.items()}
    return {name: render_dataset(split, tokenizer) for name, split in raw.items()}


def step_logger():
    from transformers import TrainerCallback

    class StepLogger(TrainerCallback):
        started = 0.0

        def on_train_begin(self, args, state, control, **kwargs):
            self.started = time.monotonic()

        def on_log(self, args, state, control, logs=None, **kwargs):
            if not logs or "loss" not in logs:
                return
            record = {
                "step": state.global_step,
                "loss": logs["loss"],
                "tokens_seen": int(state.num_input_tokens_seen or 0),
                "elapsed": round(time.monotonic() - self.started, 3),
            }
            print(STEP_MARKER + json.dumps(record), flush=True)

    return StepLogger()


def peak_memory_gb(torch, device: str) -> float:
    if device == "cuda":
        return round(torch.cuda.max_memory_allocated() / 2**30, 2)
    if device == "mps":
        return round(torch.mps.driver_allocated_memory() / 2**30, 2)
    return 0.0


UNSLOTH_TARGETS = ["q_proj", "k_proj", "v_proj", "o_proj", "gate_proj", "up_proj", "down_proj"]


def unsloth_lora_kwargs(cfg: dict) -> dict:
    lora = cfg["lora"]
    return {
        "r": lora["r"],
        "lora_alpha": lora["lora_alpha"],
        "lora_dropout": 0,
        "target_modules": UNSLOTH_TARGETS,
        "use_gradient_checkpointing": "unsloth",
        "random_state": cfg.get("seed", 42),
    }


def build_standard(cfg: dict, torch, dtype_name: str):
    from peft import LoraConfig
    from transformers import AutoModelForCausalLM, AutoTokenizer

    kwargs = {"dtype": getattr(torch, dtype_name), "attn_implementation": "sdpa"}
    if cfg.get("revision"):
        kwargs["revision"] = cfg["revision"]
    model = AutoModelForCausalLM.from_pretrained(cfg["base"], **kwargs)
    tokenizer = AutoTokenizer.from_pretrained(cfg["base"], revision=cfg.get("revision"))
    return model, tokenizer, LoraConfig(**lora_kwargs(cfg))


def build_unsloth(cfg: dict, load_in_4bit: bool):
    from unsloth import FastLanguageModel

    model, tokenizer = FastLanguageModel.from_pretrained(
        model_name=cfg["base"], max_seq_length=cfg["max_seq_length"], load_in_4bit=load_in_4bit
    )
    return FastLanguageModel.get_peft_model(model, **unsloth_lora_kwargs(cfg)), tokenizer, None


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="LoRA fine-tuning with transformers, peft and trl: one script for NVIDIA, AMD (ROCm), Apple (MPS) and CPU"  # noqa: E501
    )
    parser.add_argument("--config", required=True)
    parser.add_argument("--device", default="auto", choices=["auto", "cpu"])
    parser.add_argument("--max-steps", type=int)
    parser.add_argument("--output-dir")
    parser.add_argument("--report-to", default="mlflow")
    parser.add_argument("--limit-rows", type=int)
    parser.add_argument("--run-name")
    parser.add_argument(
        "--unsloth", action="store_true", help="NVIDIA only: Unsloth's fused kernels (uv sync --extra cuda)"
    )
    parser.add_argument("--load-in-4bit", action="store_true", help="with --unsloth: QLoRA, a 4-bit base")
    args = parser.parse_args(argv)

    if args.unsloth:
        import unsloth  # noqa: F401  (imported before trl and transformers so its patches apply)

    import torch
    from trl import SFTConfig, SFTTrainer

    cfg = load_config(args.config)
    device = pick_device(torch, args.device)
    dtype_name = pick_dtype_name(torch, device)
    print(
        f"device {device} · dtype {dtype_name} · base {cfg['base']}" + (" · unsloth" if args.unsloth else ""),
        flush=True,
    )
    os.environ.setdefault("MLFLOW_EXPERIMENT_NAME", cfg.get("experiment", "billing-peft"))
    model, tokenizer, peft_config = (
        build_unsloth(cfg, args.load_in_4bit) if args.unsloth else build_standard(cfg, torch, dtype_name)
    )
    data = load_rendered(cfg, tokenizer, args.limit_rows)
    overrides = {"max_steps": args.max_steps, "output_dir": args.output_dir, "run_name": args.run_name}
    sft = SFTConfig(report_to=args.report_to, **trainer_kwargs(cfg, dtype_name, device, overrides))
    trainer = SFTTrainer(
        model=model,
        processing_class=tokenizer,
        train_dataset=data["train"],
        eval_dataset=data.get("validation"),
        peft_config=peft_config,
        args=sft,
        callbacks=[step_logger()],
    )
    trainer.model.print_trainable_parameters()
    trainer.train()
    output = Path(sft.output_dir)
    trainer.save_model(str(output / "adapter"))
    print(f"peak memory {peak_memory_gb(torch, device)} GB · adapter in {output / 'adapter'}", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())

import os

BASE = "Qwen/Qwen2.5-0.5B-Instruct"
BASE_REVISION = "7ae557604adf67be50417f59c2c2f167def9a775"
DATA = "data/fixtures/billing_sft_small.jsonl"
OUTPUT_DIR = "runs/first-lora"
EXPERIMENT = "first-lora"


def pick_device(torch) -> str:
    if torch.cuda.is_available():
        return "cuda"
    if torch.backends.mps.is_available():
        return "mps"
    return "cpu"


def pick_dtype(torch, device: str):
    if device == "cuda":
        return torch.bfloat16
    return torch.float32


def load_splits(path: str, test_size: float = 0.1, seed: int = 42):
    from datasets import load_dataset

    data = load_dataset("json", data_files=path, split="train")  # rows: prompt, completion
    return data.train_test_split(test_size=test_size, seed=seed)


def lora_config():
    from peft import LoraConfig

    return LoraConfig(r=16, lora_alpha=16, lora_dropout=0.05, target_modules="all-linear", task_type="CAUSAL_LM")


def sft_config(epochs: int = 2, report_to: str = "mlflow"):
    from trl import SFTConfig

    return SFTConfig(
        output_dir=OUTPUT_DIR,
        num_train_epochs=epochs,
        learning_rate=2e-4,
        per_device_train_batch_size=4,
        gradient_accumulation_steps=4,
        warmup_ratio=0.05,
        eval_strategy="steps",
        eval_steps=10,
        logging_steps=5,
        report_to=report_to,
    )


def main(epochs: int = 2, data: str = DATA, report_to: str = "mlflow") -> None:
    import torch
    from transformers import AutoModelForCausalLM, AutoTokenizer
    from trl import SFTTrainer

    os.environ.setdefault("MLFLOW_EXPERIMENT_NAME", EXPERIMENT)
    device = pick_device(torch)
    splits = load_splits(data)
    trainer = SFTTrainer(
        model=AutoModelForCausalLM.from_pretrained(BASE, revision=BASE_REVISION, dtype=pick_dtype(torch, device)),
        processing_class=AutoTokenizer.from_pretrained(BASE, revision=BASE_REVISION),
        train_dataset=splits["train"],
        eval_dataset=splits["test"],
        peft_config=lora_config(),
        args=sft_config(epochs, report_to),
    )
    trainer.model.print_trainable_parameters()
    trainer.train()
    trainer.save_model(f"{OUTPUT_DIR}/adapter")


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument("--epochs", type=int, default=2)
    parser.add_argument("--data", default=DATA)
    parser.add_argument("--report-to", default="mlflow")
    args = parser.parse_args()
    main(args.epochs, args.data, args.report_to)

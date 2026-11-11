import argparse


def mix(task_path: str, replay_path: str, replay_share: float = 0.15, seed: int = 42):
    from datasets import interleave_datasets, load_dataset

    task = load_dataset("json", data_files=task_path, split="train")
    replay = load_dataset("json", data_files=replay_path, split="train")  # base model's own good answers
    return interleave_datasets(
        [task, replay],
        probabilities=[1 - replay_share, replay_share],
        seed=seed,
        stopping_strategy="first_exhausted",
    )


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--task", default="data/billing_sft.jsonl")
    parser.add_argument("--replay", default="data/replay_from_base.jsonl")
    parser.add_argument("--share", type=float, default=0.15)
    parser.add_argument("--out", default="data/billing_mixed.jsonl")
    args = parser.parse_args()

    mixed = mix(args.task, args.replay, args.share)
    mixed.to_json(args.out)
    print(f"{len(mixed)} rows to {args.out}")

import argparse
import hashlib
import json
import random
from collections import Counter, defaultdict
from pathlib import Path

FIXTURES = Path(__file__).parent / "fixtures"
SOURCE_FILES = {
    "exemplars": FIXTURES / "billing_exemplars.jsonl",
    "synthetic": FIXTURES / "billing_sft_small.jsonl",
    "replay": FIXTURES / "replay_from_base.jsonl",
}


def read_jsonl(path: Path) -> list[dict]:
    return [json.loads(line) for line in path.read_text().splitlines() if line.strip()]


def write_jsonl(path: Path, rows: list[dict]) -> None:
    path.write_text("".join(json.dumps(row, ensure_ascii=False) + "\n" for row in rows))


def parse_mix(text: str) -> dict[str, float]:
    shares = {}
    for part in filter(None, text.split(",")):
        name, value = part.split("=")
        if name not in ("synthetic", "replay"):
            raise ValueError(f"cannot mix in {name!r}: synthetic or replay")
        shares[name] = float(value)
    if sum(shares.values()) >= 1:
        raise ValueError("the mixed-in shares must leave room for the task examples")
    return shares


def digest(row: dict) -> str:
    return hashlib.sha256(json.dumps(row, sort_keys=True).encode()).hexdigest()


def external_record(row: dict, source: str) -> dict:
    key = digest(row)
    meta = {
        "id": key[:16],
        "source": source,
        "category": "billing" if source != "replay" else "general",
        "subcategory": source,
        "conversation": key,
        "tenant": "",
        "user": "",
        "consent": "n/a",
    }
    return {"id": key[:16], **row, "meta": meta}


def take(rows: list[dict], count: int, seed: int) -> list[dict]:
    rng = random.Random(seed)
    return rng.sample(rows, min(count, len(rows)))


def mixed_in(task_total: int, shares: dict[str, float], seed: int) -> dict[str, list[dict]]:
    task_share = 1 - sum(shares.values())
    chosen = {}
    for name, share in shares.items():
        path = SOURCE_FILES[name]
        available = (
            [external_record({"prompt": r["prompt"], "completion": r["completion"]}, name) for r in read_jsonl(path)]
            if path.exists()
            else []
        )
        chosen[name] = take(available, round(task_total * share / task_share), seed)
    return chosen


def exemplars() -> list[dict]:
    path = SOURCE_FILES["exemplars"]
    if not path.exists():
        return []
    return [
        external_record({"prompt": r["prompt"], "completion": r["completion"]}, "exemplars") for r in read_jsonl(path)
    ]


def stratum(record: dict) -> str:
    meta = record["meta"]
    return meta["subcategory"] if meta["source"] in ("production", "edits") else meta["source"]


def split_by_conversation(records: list[dict], val_share: float, seed: int) -> tuple[list[dict], list[dict]]:
    groups: dict[str, list[dict]] = defaultdict(list)
    for record in records:
        groups[record["meta"]["conversation"]].append(record)
    by_stratum: dict[str, list[str]] = defaultdict(list)
    for key in sorted(groups):
        by_stratum[stratum(groups[key][0])].append(key)
    rng = random.Random(seed)
    held_out: set[str] = set()
    for keys in by_stratum.values():
        rng.shuffle(keys)
        count = round(len(keys) * val_share)
        if count == 0 and len(keys) >= 3:
            count = 1
        held_out.update(keys[:count])
    train = [r for key, rows in groups.items() if key not in held_out for r in rows]
    validation = [r for key in sorted(held_out) for r in groups[key]]
    return train, validation


def plain(record: dict) -> dict:
    return {"prompt": record["prompt"], "completion": record["completion"]}


def write_version(directory: Path, train: list[dict], validation: list[dict]) -> None:
    write_jsonl(directory / "train.jsonl", [plain(r) for r in train])
    write_jsonl(directory / "validation.jsonl", [plain(r) for r in validation])
    write_jsonl(directory / "train.meta.jsonl", [r["meta"] for r in train])
    write_jsonl(directory / "validation.meta.jsonl", [r["meta"] for r in validation])


def keep_the_export(directory: Path) -> None:
    export = directory / "export"
    raw_rows, raw_meta = directory / "train.jsonl", directory / "manifest.jsonl"
    if raw_rows.exists() and raw_meta.exists() and not (export / "train.jsonl").exists():
        export.mkdir(exist_ok=True)
        raw_rows.rename(export / "train.jsonl")
        raw_meta.rename(export / "manifest.jsonl")


def build(directory: Path, shares: dict[str, float], val_share: float, seed: int) -> dict:
    cleaned = read_jsonl(directory / "cleaned.jsonl")
    ours = exemplars()
    task_total = len(cleaned) + len(ours)
    mixed = mixed_in(task_total, shares, seed)
    everything = [*cleaned, *ours, *[r for rows in mixed.values() for r in rows]]
    train, validation = split_by_conversation(everything, val_share, seed)
    keep_the_export(directory)
    write_version(directory, train, validation)
    composition = Counter(r["meta"]["source"] for r in everything)
    return {
        "examples": len(everything),
        "train": len(train),
        "validation": len(validation),
        "composition": dict(composition),
        "shares_asked": shares,
        "shares_made": {name: round(composition[name] / len(everything), 4) for name in shares} if everything else {},
        "validation_share": val_share,
        "seed": seed,
        "split": "by conversation, stratified by subcategory",
    }


def parse_args(argv=None):
    parser = argparse.ArgumentParser(description="Mix in exemplars, synthetic and replay, then split by conversation")
    parser.add_argument("--in", dest="source", required=True)
    parser.add_argument("--mix", default="synthetic=0.15,replay=0.15")
    parser.add_argument("--val", type=float, default=0.08)
    parser.add_argument("--seed", type=int, default=42)
    return parser.parse_args(argv)


def main(argv=None) -> None:
    args = parse_args(argv)
    directory = Path(args.source)
    report = build(directory, parse_mix(args.mix), args.val, args.seed)
    (directory / "split_report.json").write_text(json.dumps(report, indent=2) + "\n")
    print(f"{report['examples']} examples -> train {report['train']} · validation {report['validation']}")
    print(f"composition {report['composition']}")


if __name__ == "__main__":
    main()

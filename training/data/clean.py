import argparse
import json
import re
from collections import Counter
from pathlib import Path

EMBEDDER = "BAAI/bge-small-en-v1.5"
EMBEDDER_REVISION = "5c38ec7c405ec4b44b94cc5a9bb96e735b38267a"
TOKENIZER = "Qwen/Qwen2.5-0.5B-Instruct"
TOKENIZER_REVISION = "7ae557604adf67be50417f59c2c2f167def9a775"
PLACEHOLDER = re.compile(r"<[A-Z]+_\d+>")
CITATION = re.compile(r"\[[^\]]+\]")
UNTRUSTED_TAG = re.compile(r"</?untrusted-[^>]*>")


def read_jsonl(path: Path) -> list[dict]:
    return [json.loads(line) for line in path.read_text().splitlines() if line.strip()]


def write_jsonl(path: Path, rows: list[dict]) -> None:
    path.write_text("".join(json.dumps(row, ensure_ascii=False) + "\n" for row in rows))


def question(record: dict) -> str:
    users = [m for m in record["prompt"] if m["role"] == "user"]
    text = users[-1]["content"] if users else ""
    return UNTRUSTED_TAG.sub("", text).replace("Question:", "").strip()


def reply(record: dict) -> str:
    return record["completion"][0]["content"]


def flatten(record: dict) -> str:
    parts = []
    for message in [*record["prompt"], *record["completion"]]:
        body = message.get("content") or json.dumps(message.get("tool_calls", ""))
        parts.append(f"{message['role']}: {body}")
    return "\n".join(parts)


def count_tokens(tokenizer, record: dict) -> int:
    return len(tokenizer.encode(flatten(record)))


def sources_were_shown(record: dict) -> bool:
    return any('source="help_center"' in (m.get("content") or "") for m in record["prompt"])


def filter_reason(record: dict, max_tokens: int) -> str | None:
    if record["n_tokens"] > max_tokens:
        return "over_length"
    if PLACEHOLDER.search(json.dumps(record["prompt"]) + reply(record)):
        return "leaked_placeholder"
    if sources_were_shown(record) and not CITATION.search(reply(record)):
        return "uncited"
    return None


def run_filters(records: list[dict], max_tokens: int) -> tuple[list[dict], Counter]:
    kept, removed = [], Counter()
    for record in records:
        reason = filter_reason(record, max_tokens)
        if reason:
            removed[reason] += 1
        else:
            kept.append(record)
    return kept, removed


def near_duplicates(vectors, threshold: float) -> set[int]:
    from sentence_transformers import util

    groups = util.community_detection(vectors, threshold=threshold, min_community_size=2)
    return {i for group in groups for i in group[1:]}


def drop_near_duplicates(records: list[dict], embed, threshold: float) -> tuple[list[dict], int]:
    if len(records) < 2:
        return records, 0
    drop = near_duplicates(embed([question(r) for r in records]), threshold)
    return [r for i, r in enumerate(records) if i not in drop], len(drop)


def words(text: str) -> list[str]:
    return re.findall(r"[a-z0-9$.%']+", text.lower())


def ngrams(tokens: list[str], n: int) -> set[tuple[str, ...]]:
    return {tuple(tokens[i : i + n]) for i in range(len(tokens) - n + 1)}


def load_golden(directory: Path) -> tuple[list[str], list[str]]:
    questions, references = [], []
    for path in sorted(directory.rglob("*.jsonl")):
        for row in read_jsonl(path):
            questions.append(row["question"])
            if row.get("reference_answer"):
                references.append(row["reference_answer"])
    return questions, references


def decontaminate(
    records: list[dict], embed, golden_questions: list[str], golden_references: list[str], threshold: float, run: int
):
    if not records or not golden_questions:
        return records, Counter()
    from sentence_transformers import util

    close = (
        util.cos_sim(embed([question(r) for r in records]), embed(golden_questions)).max(dim=1).values > threshold
    ).tolist()
    reference_runs = (
        set().union(*[ngrams(words(text), run) for text in golden_references]) if golden_references else set()
    )
    kept, removed = [], Counter()
    for record, is_close in zip(records, close, strict=True):
        if is_close:
            removed["similar_question"] += 1
        elif ngrams(words(reply(record)), run) & reference_runs:
            removed["shares_reference_run"] += 1
        else:
            kept.append(record)
    return kept, removed


def balance(records: list[dict], caps: dict[str, float]) -> tuple[list[dict], Counter]:
    total = len(records)
    removed = Counter()
    for field in ("tenant", "user", "subcategory"):
        limit = max(1, int(caps[field] * total))
        seen: Counter = Counter()
        kept = []
        for record in records:
            value = record["meta"][field]
            seen[value] += 1
            if seen[value] > limit:
                removed[field] += 1
            else:
                kept.append(record)
        records = kept
    return records, removed


def clean(records: list[dict], tokenizer, embed, golden_dir: Path, args) -> tuple[list[dict], dict]:
    for record in records:
        record["n_tokens"] = count_tokens(tokenizer, record)
    report: dict = {"input": len(records)}
    records, report["filters"] = run_filters(records, args.max_tokens)
    records, report["near_duplicates"] = drop_near_duplicates(records, embed, args.dup)
    golden_questions, golden_references = load_golden(golden_dir)
    records, report["decontaminated"] = decontaminate(
        records, embed, golden_questions, golden_references, args.decon, args.run
    )
    records, report["balance"] = balance(
        records, {"tenant": args.cap_tenant, "user": args.cap_user, "subcategory": args.cap_subcategory}
    )
    report["filters"], report["decontaminated"], report["balance"] = (
        dict(report["filters"]),
        dict(report["decontaminated"]),
        dict(report["balance"]),
    )
    report["output"] = len(records)
    report["thresholds"] = {
        "max_tokens": args.max_tokens,
        "near_duplicate": args.dup,
        "decontaminate": args.decon,
        "exact_run_words": args.run,
        **{
            f"cap_{k}": v
            for k, v in {"tenant": args.cap_tenant, "user": args.cap_user, "subcategory": args.cap_subcategory}.items()
        },
    }
    return records, report


def load_models(tokenizer_repo: str):
    from sentence_transformers import SentenceTransformer
    from transformers import AutoTokenizer

    tokenizer = AutoTokenizer.from_pretrained(
        tokenizer_repo, revision=TOKENIZER_REVISION if tokenizer_repo == TOKENIZER else None
    )
    model = SentenceTransformer(EMBEDDER, revision=EMBEDDER_REVISION)

    def embed(texts: list[str]):
        return model.encode(texts, normalize_embeddings=True, convert_to_tensor=True)

    return tokenizer, embed


def parse_args(argv=None):
    parser = argparse.ArgumentParser(
        description="Filters, near-duplicates, decontamination and balance caps over reviewed.jsonl"
    )
    parser.add_argument(
        "--in", dest="source", required=True, help="the dataset directory (storage/datasets/billing-v1)"
    )
    parser.add_argument(
        "--golden", default="../tests/golden", help="the golden set directory, read for decontamination, never exported"
    )
    parser.add_argument(
        "--tokenizer",
        default=TOKENIZER,
        help="the tokenizer lengths are measured with; the serving base's own where you have access",
    )
    parser.add_argument("--max-tokens", type=int, default=2048)
    parser.add_argument("--dup", type=float, default=0.95)
    parser.add_argument("--decon", type=float, default=0.90)
    parser.add_argument(
        "--run",
        type=int,
        default=13,
        help="words of exact overlap with a golden reference answer that remove an example",
    )
    parser.add_argument("--cap-tenant", type=float, default=0.05)
    parser.add_argument("--cap-user", type=float, default=0.01)
    parser.add_argument("--cap-subcategory", type=float, default=0.40)
    return parser.parse_args(argv)


def main(argv=None) -> None:
    args = parse_args(argv)
    directory = Path(args.source)
    tokenizer, embed = load_models(args.tokenizer)
    records, report = clean(read_jsonl(directory / "reviewed.jsonl"), tokenizer, embed, Path(args.golden), args)
    report["tokenizer"] = args.tokenizer
    write_jsonl(directory / "cleaned.jsonl", records)
    (directory / "clean_report.json").write_text(json.dumps(report, indent=2) + "\n")
    removed = report["input"] - report["output"]
    print(f"{report['input']} reviewed -> {report['output']} kept ({removed} removed)")
    print(f"filters {report['filters']} · near-duplicates {report['near_duplicates']}")
    print(f"decontaminated {report['decontaminated']} · balance {report['balance']}")


if __name__ == "__main__":
    main()

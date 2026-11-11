import argparse
import json
import os
from pathlib import Path

NOMIC = "nomic-ai/nomic-embed-text-v1.5"
NOMIC_REVISION = os.environ.get(
    "NOMIC_REVISION", "e9b6763023c676ca8431644204f50c2b100d9aab"
)  # the pins the embedding worker runs (.env.example)
QUERY_PREFIX = "search_query: "
DOCUMENT_PREFIX = "search_document: "


def read_jsonl(path: Path) -> list[dict]:
    with Path(path).open() as handle:
        return [json.loads(line) for line in handle if line.strip()]


def prefix(row: dict) -> dict:
    prefixed = {"anchor": QUERY_PREFIX + row["anchor"], "positive": DOCUMENT_PREFIX + row["positive"]}
    if row.get("negative"):
        prefixed["negative"] = DOCUMENT_PREFIX + row["negative"]
    return prefixed


def load_pairs(path: Path, hard_negatives: bool = False) -> list[dict]:
    rows = []
    for row in read_jsonl(path):
        if not hard_negatives:
            row = {"anchor": row["anchor"], "positive": row["positive"]}
        rows.append(prefix(row))
    return rows


def load_heldout(heldout: Path, chunks: Path) -> tuple[dict, dict, dict]:
    corpus = {
        str(chunk["id"]): DOCUMENT_PREFIX + chunk["text"] for chunk in read_jsonl(chunks)
    }  # dataset:pairs writes the text each chunk is embedded as
    queries = {}
    relevant = {}
    for row in read_jsonl(heldout):
        known = [str(chunk_id) for chunk_id in row["relevant"] if str(chunk_id) in corpus]
        if not known:
            continue
        queries[str(row["id"])] = QUERY_PREFIX + row["question"]
        relevant[str(row["id"])] = set(known)
    return queries, corpus, relevant


def load_model(args):
    from sentence_transformers import SentenceTransformer

    code_revision = os.environ.get(
        "NOMIC_CODE_REVISION", "7710840340a098cfb869c4f65e87cf2b1b70caca"
    )  # the second repo, nomic-ai/nomic-bert-2048, whose code runs
    pinned = {"model_kwargs": {"code_revision": code_revision}, "config_kwargs": {"code_revision": code_revision}}
    return SentenceTransformer(
        args.model, revision=args.revision, trust_remote_code=True, device=args.device, **pinned
    )  # repo code, pinned (Chapter 26)


def train(args, model=None) -> dict:
    from datasets import Dataset
    from sentence_transformers import SentenceTransformerTrainer, SentenceTransformerTrainingArguments, losses
    from sentence_transformers.evaluation import InformationRetrievalEvaluator

    model = model or load_model(args)
    queries, corpus, relevant = load_heldout(Path(args.heldout), Path(args.chunks))
    evaluator = InformationRetrievalEvaluator(
        queries=queries,
        corpus=corpus,
        relevant_docs=relevant,
        name="ledgerly-heldout",
        mrr_at_k=[10],
        ndcg_at_k=[10],
        accuracy_at_k=[1, 5],
        precision_recall_at_k=[5],
        show_progress_bar=False,
    )
    before = evaluator(model)
    pairs = Dataset.from_list(load_pairs(Path(args.pairs), args.hard_negatives))
    arguments = SentenceTransformerTrainingArguments(
        output_dir=str(Path(args.out) / "checkpoints"),
        num_train_epochs=args.epochs,
        max_steps=args.max_steps,
        per_device_train_batch_size=args.batch,
        learning_rate=args.lr,
        warmup_ratio=0.1,
        batch_sampler="no_duplicates",
        save_strategy="no",
        logging_steps=5,
        report_to="none",
        use_cpu=args.device == "cpu",
        seed=42,
    )
    trainer = SentenceTransformerTrainer(
        model=model,
        args=arguments,
        train_dataset=pairs,
        loss=losses.MultipleNegativesRankingLoss(model),
        evaluator=evaluator,
    )
    trainer.train()
    after = evaluator(model)
    model.save_pretrained(args.out)
    return {"before": before, "after": after, "pairs": len(pairs), "queries": len(queries)}


def summary(result: dict, name: str = "ledgerly-heldout") -> str:
    metrics = [
        ("recall@5", f"{name}_cosine_recall@5"),
        ("MRR@10", f"{name}_cosine_mrr@10"),
        ("ndcg@10", f"{name}_cosine_ndcg@10"),
    ]
    lines = [f"held-out questions ({result['queries']})".ljust(36) + "base".rjust(10) + "tuned".rjust(10)]
    for label, key in metrics:
        lines.append(
            label.ljust(36)
            + f"{result['before'].get(key, float('nan')):10.3f}{result['after'].get(key, float('nan')):10.3f}"
        )
    return "\n".join(lines)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Fine-tune the app's own embedding model (Chapter 46, Topic 6)")
    parser.add_argument(
        "--pairs", required=True, help="JSON Lines: anchor (a question), positive (the chunk), optional negative"
    )
    parser.add_argument(
        "--heldout", required=True, help="JSON Lines: id, question, relevant (chunk ids) (dataset:pairs writes it)"
    )
    parser.add_argument("--chunks", default="data/retrieval/chunks.jsonl", help="the corpus: dataset:pairs writes it")
    parser.add_argument("--model", default=NOMIC)
    parser.add_argument("--revision", default=NOMIC_REVISION)
    parser.add_argument("--out", default="runs/embeddings/ledgerly-nomic-v1")
    parser.add_argument("--epochs", type=int, default=1)
    parser.add_argument("--max-steps", type=int, default=-1)
    parser.add_argument("--batch", type=int, default=32)
    parser.add_argument("--lr", type=float, default=2e-5)
    parser.add_argument("--hard-negatives", action="store_true", help="use the negative column too (Exercise 3)")
    parser.add_argument("--device", default=None, help="cpu, mps or cuda (default: the library's choice)")
    args = parser.parse_args(argv)

    result = train(args)
    print(summary(result))
    print(
        f"saved to {args.out}: the Python worker serves it by its name "
        f"(ai:reembed --model={Path(args.out).name} --into=shadow)"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

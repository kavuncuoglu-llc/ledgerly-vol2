import argparse
import json
import sys
from pathlib import Path

QUERY_PREFIX = "search_query: "


def embed_queries(model, questions: list[str]) -> dict[str, list[float]]:
    vectors = model.encode(
        [QUERY_PREFIX + question for question in questions], batch_size=32, normalize_embeddings=True
    )
    return {question: vector.tolist() for question, vector in zip(questions, vectors, strict=True)}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Embed questions with the model the shadow vectors came from (Chapter 46, Topic 6)"
    )
    parser.add_argument(
        "--model",
        required=True,
        help="the Hub repo the worker serves, or a model tune_embeddings.py saved under runs/embeddings",
    )
    parser.add_argument("--questions", required=True, help="a JSON list of question strings")
    parser.add_argument("--out", required=True)
    args = parser.parse_args(argv)

    sys.path.insert(0, str(Path(__file__).resolve().parents[1]))  # run as a script, the project root is not on the path
    from workers.embed_worker import model_for

    questions = json.loads(Path(args.questions).read_text())
    Path(args.out).write_text(json.dumps(embed_queries(model_for(args.model), questions)))
    print(f"{len(questions)} questions embedded by {args.model}")
    return 0


if __name__ == "__main__":
    sys.exit(main())

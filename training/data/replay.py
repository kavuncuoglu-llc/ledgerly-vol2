import argparse
import json
import os
import re
from pathlib import Path

import requests

OLLAMA = os.environ.get("OLLAMA_URL", "http://localhost:11434")
MAX_WORDS = 250


def read_jsonl(path: Path) -> list[dict]:
    return [json.loads(line) for line in path.read_text().splitlines() if line.strip()]


def ask(base_url: str, model: str, messages: list[dict]) -> str:
    response = requests.post(
        f"{base_url}/api/chat",
        json={"model": model, "messages": messages, "stream": False, "options": {"temperature": 0, "seed": 42}},
        timeout=300,
    )
    response.raise_for_status()
    return response.json()["message"]["content"].strip()


REFUSAL = re.compile(r"\b(can't|can\u2019t|cannot|can not|won't|won\u2019t|unable to|not able to)\b", re.IGNORECASE)


def good(answer: str, expect: str = "answer") -> bool:
    if not answer or len(answer.split()) > MAX_WORDS:
        return False
    if expect == "refuse":
        return bool(REFUSAL.search(answer))
    return True


def replay_row(prompt: list[dict], answer: str) -> dict:
    return {"prompt": prompt, "completion": [{"role": "assistant", "content": answer}]}


def generate(prompts: list[dict], model: str, base_url: str = OLLAMA, ask_fn=ask) -> list[dict]:
    rows = []
    for row in prompts:
        answer = ask_fn(base_url, model, row["prompt"])
        if good(answer, row.get("expect", "answer")):
            rows.append(replay_row(row["prompt"], answer))
    return rows


def main() -> None:
    parser = argparse.ArgumentParser(
        description="The base model's own answers to general prompts: replay against forgetting (Chapter 39, Topic 7)"
    )
    parser.add_argument("--prompts", default="data/fixtures/replay_prompts.jsonl")
    parser.add_argument("--model", default="llama3.1:8b")
    parser.add_argument("--out", default="data/fixtures/replay_from_base.jsonl")
    args = parser.parse_args()
    rows = generate(read_jsonl(Path(args.prompts)), args.model)
    Path(args.out).write_text("".join(json.dumps(row, ensure_ascii=False) + "\n" for row in rows))
    print(f"{len(rows)} replay examples from {args.model} to {args.out}")


if __name__ == "__main__":
    main()

import argparse
import json
import random
from pathlib import Path

from make_dialogues import help_sections

HELD_OUT = "Why was a late fee added to my invoice?"  # Figure 45-8's question stays out of the pairs


def build_pairs(sections, dialogues: list[dict], count: int = 500, help_share: float = 0.6, seed: int = 45):
    rng = random.Random(seed)
    pairs, seen = [], {HELD_OUT}

    def add(question, answer, source):
        if question not in seen:
            seen.add(question)
            pairs.append({"question": question, "answer": answer, "source": source})

    help_rows = list(sections)
    rng.shuffle(help_rows)
    for slug, question, answer in help_rows:
        if len(pairs) >= round(count * help_share):
            break
        add(question, answer, f"help:{slug}")
    for record in dialogues:
        if len(pairs) >= count:
            break
        if not record["topic"].startswith("help:"):
            add(record["turns"][0]["text"], record["turns"][1]["text"], f"synthetic:{record['id']}")
    return pairs


def main():
    parser = argparse.ArgumentParser(description="Build the post-training Q&A pairs")
    parser.add_argument("--help-center", type=Path, default=Path("../../resources/help/"))
    parser.add_argument("--synthetic", type=Path, default=Path("../data/synthetic/support-dialogue.jsonl"))
    parser.add_argument("--out", type=Path, default=Path("data/qa_pairs.jsonl"))
    parser.add_argument("--count", type=int, default=500)
    args = parser.parse_args()
    dialogues = [json.loads(line) for line in args.synthetic.read_text().splitlines() if line.strip()]
    pairs = build_pairs(help_sections(args.help_center), dialogues, args.count)
    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text("".join(json.dumps(p) + "\n" for p in pairs), encoding="utf-8")
    sources = sum(p["source"].startswith("help:") for p in pairs)
    print(f"  {len(pairs)} pairs ({sources} from the help center, {len(pairs) - sources} from dialogues) -> {args.out}")


if __name__ == "__main__":
    main()

import argparse
import hashlib
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from data.chat_rows import has_tool_turns, messages_of  # noqa: E402

TOOL_TURNS = ("keep", "drop")


def read_rows(path: Path) -> list[dict]:
    return [json.loads(line) for line in path.read_text().splitlines() if line.strip()]


def to_chat(row: dict) -> dict:
    return {"messages": messages_of(row)}


def split_file(rows: list[dict], every: int = 10) -> tuple[list[dict], list[dict]]:
    train = [row for index, row in enumerate(rows) if index % every != every - 1]
    valid = [row for index, row in enumerate(rows) if index % every == every - 1]
    return train, valid


def load_source(source: Path) -> tuple[list[dict], list[dict]]:
    if source.is_file():
        return split_file(read_rows(source))
    return read_rows(source / "train.jsonl"), read_rows(source / "validation.jsonl")


def write_rows(path: Path, rows: list[dict]) -> None:
    path.write_text("".join(json.dumps(row, ensure_ascii=False) + "\n" for row in rows))


def sha256_of(path: Path) -> str:
    return "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()


def convert(source: Path, out: Path, tool_turns: str = "keep") -> dict:
    if tool_turns not in TOOL_TURNS:
        raise ValueError(f"--tool-turns is one of {', '.join(TOOL_TURNS)}")
    train, valid = load_source(source)
    dropped = 0
    if tool_turns == "drop":
        dropped = sum(has_tool_turns(row) for row in [*train, *valid])
        train = [row for row in train if not has_tool_turns(row)]
        valid = [row for row in valid if not has_tool_turns(row)]
    with_tools = sum(has_tool_turns(row) for row in [*train, *valid])
    out.mkdir(parents=True, exist_ok=True)
    write_rows(out / "train.jsonl", [to_chat(row) for row in train])
    write_rows(out / "valid.jsonl", [to_chat(row) for row in valid])
    train_file = source if source.is_file() else source / "train.jsonl"
    provenance = {"source": source.name, "train_file_hash": sha256_of(train_file), "tool_turns": tool_turns}
    (out / "source.json").write_text(json.dumps(provenance, indent=2) + "\n")
    return {
        "train": len(train),
        "valid": len(valid),
        "with_tool_turns": with_tools,
        "dropped_tool_rows": dropped,
        **provenance,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="prompt-and-completion rows to the chat-format files mlx_lm.lora reads"
    )
    parser.add_argument(
        "source",
        type=Path,
        help="a dataset directory (train.jsonl, validation.jsonl) or one prompt-and-completion file",
    )
    parser.add_argument("out", type=Path)
    parser.add_argument("--tool-turns", choices=TOOL_TURNS, default="keep")
    args = parser.parse_args(argv)
    summary = convert(args.source, args.out, args.tool_turns)
    print(f"train {summary['train']} · valid {summary['valid']} → {args.out}")
    if summary["with_tool_turns"]:
        print(
            f"{summary['with_tool_turns']} rows have tool turns: run `php artisan ai:template-check` on the served model before trusting them (Chapter 40, Topic 5)",  # noqa: E501
            file=sys.stderr,
        )
    if summary["dropped_tool_rows"]:
        print(f"dropped {summary['dropped_tool_rows']} rows with tool turns", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())

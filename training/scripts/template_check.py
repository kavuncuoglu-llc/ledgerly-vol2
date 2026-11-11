import argparse
import json
from pathlib import Path


def first_row(path: str, index: int = 0) -> dict:
    lines = [line for line in Path(path).read_text().splitlines() if line.strip()]
    return json.loads(lines[index])


def tool_arguments_as_objects(messages: list[dict]) -> list[dict]:
    fixed = []
    for message in messages:
        calls = message.get("tool_calls")
        if not calls:
            fixed.append(message)
            continue
        rewritten = []
        for call in calls:
            arguments = call["function"]["arguments"]
            if isinstance(arguments, str):
                arguments = json.loads(arguments)
            rewritten.append({**call, "function": {**call["function"], "arguments": arguments}})
        fixed.append({**message, "tool_calls": rewritten})
    return fixed


def render(tokenizer, row: dict) -> dict:
    messages = tool_arguments_as_objects(row["prompt"])
    text = tokenizer.apply_chat_template(messages, tokenize=False, add_generation_prompt=True)
    return {"text": text, "ids": tokenizer(text, add_special_tokens=False)["input_ids"]}


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Render one training prompt with the tokenizer's chat template (ai:template-check's training side)"
    )
    parser.add_argument("tokenizer", help="the base the adapter was trained on")
    parser.add_argument("--rows", default="data/billing/train.jsonl")
    parser.add_argument("--index", type=int, default=0)
    parser.add_argument("--revision", default=None)
    args = parser.parse_args()

    from transformers import AutoTokenizer

    tok = AutoTokenizer.from_pretrained(args.tokenizer, revision=args.revision)
    print(json.dumps(render(tok, first_row(args.rows, args.index))))


if __name__ == "__main__":
    main()

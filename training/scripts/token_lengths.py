import argparse
import json


def token_lengths(path: str, tokenizer) -> list[int]:
    with open(path) as f:
        return [len(tokenizer.encode(json.loads(line)["text"])) for line in f]


def summary(lengths: list[int]) -> str:
    return f"{len(lengths)} rows, max {max(lengths)}, mean {sum(lengths) / len(lengths):.0f}"


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("path")
    args = parser.parse_args()

    from transformers import AutoTokenizer

    tok = AutoTokenizer.from_pretrained(
        "Qwen/Qwen2.5-0.5B-Instruct", revision="7ae557604adf67be50417f59c2c2f167def9a775"
    )
    print(summary(token_lengths(args.path, tok)))

import tiktoken
from transformers import AutoTokenizer

TEXT = "Invoice #4471 is overdue by 30 days; a 1.5% late fee applies."

QWEN = ("Qwen/Qwen2.5-0.5B-Instruct", "7ae557604adf67be50417f59c2c2f167def9a775")
BGE = ("BAAI/bge-small-en-v1.5", "5c38ec7c405ec4b44b94cc5a9bb96e735b38267a")


def hub_count(text: str, repo: str, revision: str) -> int:
    tok = AutoTokenizer.from_pretrained(repo, revision=revision)

    return len(tok.encode(text, add_special_tokens=False))


def tiktoken_count(text: str, encoding: str) -> int:
    return len(tiktoken.get_encoding(encoding).encode(text))


def count_all(text: str) -> dict[str, int]:
    return {
        "Qwen2.5 (byte-level BPE)": hub_count(text, *QWEN),
        "bge-small (WordPiece)": hub_count(text, *BGE),
        "o200k_base (tiktoken)": tiktoken_count(text, "o200k_base"),
    }


def render(counts: dict[str, int]) -> list[str]:
    return [f"{name:28} {n:3} tokens" for name, n in counts.items()]


def main() -> None:
    for line in render(count_all(TEXT)):
        print(line)


if __name__ == "__main__":
    main()

import argparse
from pathlib import Path

import numpy as np
from tokenizers import Tokenizer, decoders, models, pre_tokenizers, trainers

EOT, USER, ASSISTANT = "<|endoftext|>", "<|user|>", "<|assistant|>"
SPECIAL_TOKENS = [EOT, USER, ASSISTANT]


def train_tokenizer(files: list[str], vocab_size: int = 8000) -> Tokenizer:
    tok = Tokenizer(models.BPE())
    tok.pre_tokenizer = pre_tokenizers.ByteLevel(add_prefix_space=False)  # bytes first, so any text can be encoded
    tok.decoder = decoders.ByteLevel()
    trainer = trainers.BpeTrainer(
        vocab_size=vocab_size,
        special_tokens=SPECIAL_TOKENS,
        initial_alphabet=pre_tokenizers.ByteLevel.alphabet(),
    )
    docs = (doc for path in files for doc in documents(Path(path)))  # the markers between documents are not text
    tok.train_from_iterator(docs, trainer)
    return tok


def documents(path: Path) -> list[str]:
    return [d.strip("\n") for d in path.read_text(encoding="utf-8").split(f"\n{EOT}\n") if d.strip()]


def encode_documents(tok: Tokenizer, docs: list[str]) -> np.ndarray:
    eot = tok.token_to_id(EOT)
    ids = []
    for encoding in tok.encode_batch(docs):
        ids.extend(encoding.ids)
        ids.append(eot)  # one marker between documents
    assert tok.get_vocab_size() <= 2**16
    return np.array(ids, dtype=np.uint16)


def main():
    parser = argparse.ArgumentParser(description="Train the byte-level BPE tokenizer and write the corpus as token IDs")
    parser.add_argument("--corpus", type=Path, default=Path("corpus/"))
    parser.add_argument("--vocab", type=int, default=8000)
    parser.add_argument("--out", type=Path, default=Path("tokenizer.json"))
    args = parser.parse_args()

    tok = train_tokenizer([str(args.corpus / "train.txt")], args.vocab)
    tok.save(str(args.out))
    for word in ["invoice", "proration", "Ledgerly", "photosynthesis"]:
        print(word, tok.encode(word).tokens)

    for split in ("train", "val"):
        docs = documents(args.corpus / f"{split}.txt")
        ids = encode_documents(tok, docs)
        ids.tofile(args.corpus / f"{split}.bin")
        chars = sum(len(d) for d in docs)
        print(
            f"  {split}.bin: {len(ids):,} tokens from {len(docs):,} documents "
            f"({chars / len(ids):.2f} characters a token)"
        )


if __name__ == "__main__":
    main()

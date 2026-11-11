import json
from types import SimpleNamespace

import numpy as np
import pytest

from scripts import embed_queries as eq
from scripts import tune_embeddings as te


def write_jsonl(path, rows):
    path.write_text("\n".join(json.dumps(row) for row in rows) + "\n")


def tiny_model(tmp_path):
    from sentence_transformers import SentenceTransformer
    from tokenizers import Tokenizer, models, pre_tokenizers
    from transformers import BertConfig, BertModel, PreTrainedTokenizerFast

    words = [
        "[PAD]",
        "[UNK]",
        "[CLS]",
        "[SEP]",
        "[MASK]",
        "search_query",
        "search_document",
        ":",
        "late",
        "fee",
        "invoice",
        "plan",
        "pay",
        "card",
        "when",
        "is",
        "a",
        "the",
        "my",
    ]
    tokenizer = Tokenizer(models.WordLevel({w: i for i, w in enumerate(words)}, unk_token="[UNK]"))
    tokenizer.pre_tokenizer = pre_tokenizers.WhitespaceSplit()
    fast = PreTrainedTokenizerFast(
        tokenizer_object=tokenizer,
        unk_token="[UNK]",
        pad_token="[PAD]",
        cls_token="[CLS]",
        sep_token="[SEP]",
        mask_token="[MASK]",
    )
    config = BertConfig(
        vocab_size=len(words),
        hidden_size=16,
        num_hidden_layers=1,
        num_attention_heads=2,
        intermediate_size=32,
        max_position_embeddings=64,
    )
    directory = tmp_path / "tiny-bert"
    BertModel(config).save_pretrained(directory)
    fast.save_pretrained(directory)
    return SentenceTransformer(str(directory), device="cpu")


def corpus(tmp_path):
    chunks = tmp_path / "chunks.jsonl"
    write_jsonl(
        chunks,
        [
            {"id": 1, "text": "late fee on an invoice"},
            {"id": 2, "text": "Payments. \n\npay by card"},
            {"id": 3, "text": "change a plan"},
        ],
    )
    return chunks


def test_the_model_expects_task_prefixes_on_both_sides():
    row = te.prefix({"anchor": "when is a late fee", "positive": "late fee on an invoice"})

    assert row == {"anchor": "search_query: when is a late fee", "positive": "search_document: late fee on an invoice"}
    assert "negative" not in row
    assert te.prefix({"anchor": "a", "positive": "b", "negative": "c"})["negative"] == "search_document: c"


def test_pairs_use_hard_negatives_only_when_asked(tmp_path):
    pairs = tmp_path / "pairs.jsonl"
    write_jsonl(pairs, [{"anchor": "q", "positive": "p", "negative": "n", "origin": "consented", "chunk": 1}])

    assert te.load_pairs(pairs) == [{"anchor": "search_query: q", "positive": "search_document: p"}]
    assert te.load_pairs(pairs, hard_negatives=True)[0]["negative"] == "search_document: n"


def test_the_held_out_set_is_prefixed_and_keeps_only_chunks_that_exist(tmp_path):
    chunks = corpus(tmp_path)
    heldout = tmp_path / "heldout.jsonl"
    write_jsonl(
        heldout,
        [
            {"id": 7, "question": "when is a late fee", "relevant": [1, 99]},
            {"id": 8, "question": "nothing", "relevant": [99]},
        ],
    )

    queries, docs, relevant = te.load_heldout(heldout, chunks)

    assert queries == {"7": "search_query: when is a late fee"}
    assert relevant == {"7": {"1"}}
    assert docs["2"] == "search_document: Payments. \n\npay by card" and docs["3"] == "search_document: change a plan"


def test_the_summary_reads_recall_and_rank_before_and_after():
    result = {
        "queries": 42,
        "before": {"ledgerly-heldout_cosine_recall@5": 0.81, "ledgerly-heldout_cosine_mrr@10": 0.66},
        "after": {"ledgerly-heldout_cosine_recall@5": 0.92, "ledgerly-heldout_cosine_mrr@10": 0.79},
    }

    text = te.summary(result)

    assert "held-out questions (42)" in text
    assert "recall@5" in text and "0.810" in text and "0.920" in text
    assert "MRR@10" in text and "0.660" in text and "0.790" in text


def test_it_trains_a_tiny_model_end_to_end_on_cpu(tmp_path):
    chunks = corpus(tmp_path)
    pairs = tmp_path / "pairs.jsonl"
    heldout = tmp_path / "heldout.jsonl"
    write_jsonl(
        pairs,
        [
            {"anchor": f"when is a late fee {i}", "positive": text}
            for i, text in enumerate(
                ["late fee on an invoice", "pay by card", "change a plan", "the plan is a card"] * 2
            )
        ],
    )
    write_jsonl(
        heldout,
        [
            {"id": 1, "question": "when is a late fee", "relevant": [1]},
            {"id": 2, "question": "pay card", "relevant": [2]},
        ],
    )
    out = tmp_path / "out"
    args = SimpleNamespace(
        pairs=str(pairs),
        heldout=str(heldout),
        chunks=str(chunks),
        out=str(out),
        epochs=1,
        max_steps=2,
        batch=4,
        lr=1e-3,
        hard_negatives=False,
        device="cpu",
        model="tiny",
        revision=None,
    )

    result = te.train(args, model=tiny_model(tmp_path))

    assert result["pairs"] == 8 and result["queries"] == 2
    assert any(key.startswith("ledgerly-heldout_cosine_recall@5") for key in result["after"])
    assert (out / "modules.json").exists()


def test_queries_are_embedded_with_the_query_prefix():
    class Model:
        def encode(self, texts, batch_size, normalize_embeddings):
            self.texts = texts
            return np.array([[1.0, 0.0]] * len(texts))

    model = Model()

    vectors = eq.embed_queries(model, ["when is a late fee", "pay by card"])

    assert model.texts == ["search_query: when is a late fee", "search_query: pay by card"]
    assert vectors == {"when is a late fee": [1.0, 0.0], "pay by card": [1.0, 0.0]}


def test_the_nomic_pins_are_the_ones_the_worker_runs():
    assert (
        te.NOMIC_REVISION == "e9b6763023c676ca8431644204f50c2b100d9aab" and te.NOMIC == "nomic-ai/nomic-embed-text-v1.5"
    )
    with pytest.raises(SystemExit):
        te.main([])

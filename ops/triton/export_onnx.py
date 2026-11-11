#!/usr/bin/env python3
"""Export the Chapter 16 reranker and the Chapter 6 embedder to ONNX for Triton's repository (Chapter 25, Topic 2, Exercise 1).

    python3 -m venv .venv-export && .venv-export/bin/pip install -r ops/triton/requirements-export.txt
    .venv-export/bin/python ops/triton/export_onnx.py [reranker|embedder|all]

Each model is downloaded at a pinned commit, wrapped so the ONNX graph has exactly the tensors in its config.pbtxt, exported, and checked with onnxruntime against the PyTorch output.
The embedder's modelling code comes from the nomic-ai/nomic-bert-2048 repository (trust_remote_code), so that repository is pinned too: read it before you run this.
Written from the model cards and the torch/transformers documentation; NOT run by the author (no GPU, no 1.5 GB download).
"""

import sys
from pathlib import Path

import numpy as np
import onnxruntime
import torch
from transformers import AutoModel, AutoModelForSequenceClassification, AutoTokenizer

MODELS = Path(__file__).resolve().parent / "models"

RERANKER = ("BAAI/bge-reranker-base", "2cfc18c9415c912f9d8155881c133215df768a70")
EMBEDDER = ("nomic-ai/nomic-embed-text-v1.5", "e9b6763023c676ca8431644204f50c2b100d9aab")
EMBEDDER_CODE = ("nomic-ai/nomic-bert-2048", "7710840340a098cfb869c4f65e87cf2b1b70caca")


class Reranker(torch.nn.Module):
    def __init__(self, model):
        super().__init__()
        self.model = model

    def forward(self, input_ids, attention_mask):
        return self.model(input_ids=input_ids, attention_mask=attention_mask).logits


class MeanPooledEmbedder(torch.nn.Module):
    """nomic-embed-text is mean pooled and normalised; doing it in the graph makes the output one vector per text."""

    def __init__(self, model):
        super().__init__()
        self.model = model

    def forward(self, input_ids, attention_mask):
        hidden = self.model(input_ids=input_ids, attention_mask=attention_mask)[0]
        mask = attention_mask.unsqueeze(-1).to(hidden.dtype)
        pooled = (hidden * mask).sum(dim=1) / mask.sum(dim=1).clamp(min=1e-9)
        return torch.nn.functional.normalize(pooled, p=2, dim=1)


def export(wrapper, tokenizer, sample, output_name, destination):
    destination.parent.mkdir(parents=True, exist_ok=True)
    encoded = tokenizer(*sample, padding=True, truncation=True, max_length=512, return_tensors="pt")
    inputs = (encoded["input_ids"], encoded["attention_mask"])

    torch.onnx.export(
        wrapper.eval(),
        inputs,
        str(destination),
        input_names=["input_ids", "attention_mask"],
        output_names=[output_name],
        dynamic_axes={
            "input_ids": {0: "batch", 1: "sequence"},
            "attention_mask": {0: "batch", 1: "sequence"},
            output_name: {0: "batch"},
        },
        opset_version=17,
    )

    session = onnxruntime.InferenceSession(str(destination), providers=["CPUExecutionProvider"])
    got = session.run([output_name], {"input_ids": inputs[0].numpy(), "attention_mask": inputs[1].numpy()})[0]
    with torch.no_grad():
        expected = wrapper(*inputs).numpy()

    if not np.allclose(got, expected, atol=1e-3):
        raise SystemExit(f"{destination}: the ONNX output differs from PyTorch by more than 1e-3")

    print(f"wrote {destination} ({destination.stat().st_size / 1e6:.0f} MB), output {got.shape}, matches PyTorch")


def export_reranker():
    name, revision = RERANKER
    tokenizer = AutoTokenizer.from_pretrained(name, revision=revision)
    model = AutoModelForSequenceClassification.from_pretrained(name, revision=revision)
    pair = (["How is a late fee calculated?"], ["Fees start after a 30 day grace period."])
    export(Reranker(model), tokenizer, pair, "logits", MODELS / "reranker" / "1" / "model.onnx")


def export_embedder():
    name, revision = EMBEDDER
    tokenizer = AutoTokenizer.from_pretrained(name, revision=revision)
    model = AutoModel.from_pretrained(name, revision=revision, trust_remote_code=True)
    export(MeanPooledEmbedder(model), tokenizer, (["search_query: How do I set up recurring invoices?"],), "embedding", MODELS / "embedder" / "1" / "model.onnx")


if __name__ == "__main__":
    target = sys.argv[1] if len(sys.argv) > 1 else "all"

    if target not in ("reranker", "embedder", "all"):
        raise SystemExit("usage: export_onnx.py [reranker|embedder|all]")

    if target in ("reranker", "all"):
        export_reranker()

    if target in ("embedder", "all"):
        export_embedder()

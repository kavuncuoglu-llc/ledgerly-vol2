import argparse
from dataclasses import dataclass

import torch
import torch.nn as nn
import torch.nn.functional as F


@dataclass
class GPTConfig:
    vocab: int = 8000
    ctx: int = 256
    layers: int = 6
    heads: int = 6
    dim: int = 384
    causal: bool = True  # Exercise 2 turns it off


class Block(nn.Module):
    def __init__(self, c: GPTConfig):
        super().__init__()
        self.ln1, self.ln2 = nn.LayerNorm(c.dim), nn.LayerNorm(c.dim)
        self.qkv, self.proj = nn.Linear(c.dim, 3 * c.dim), nn.Linear(c.dim, c.dim)
        self.mlp = nn.Sequential(nn.Linear(c.dim, 4 * c.dim), nn.GELU(), nn.Linear(4 * c.dim, c.dim))
        self.heads, self.causal = c.heads, c.causal

    def forward(self, x):
        b, t, d = x.shape
        q, k, v = self.qkv(self.ln1(x)).split(d, dim=2)
        q, k, v = (z.view(b, t, self.heads, d // self.heads).transpose(1, 2) for z in (q, k, v))
        att = F.scaled_dot_product_attention(q, k, v, is_causal=self.causal)  # earlier positions only
        x = x + self.proj(att.transpose(1, 2).reshape(b, t, d))  # residual
        return x + self.mlp(self.ln2(x))  # residual


class GPT(nn.Module):
    def __init__(self, c: GPTConfig):
        super().__init__()
        self.config = c
        self.tok, self.pos = nn.Embedding(c.vocab, c.dim), nn.Embedding(c.ctx, c.dim)
        self.blocks = nn.ModuleList(Block(c) for _ in range(c.layers))
        self.ln_f, self.head = nn.LayerNorm(c.dim), nn.Linear(c.dim, c.vocab, bias=False)
        self.head.weight = self.tok.weight  # weight tying
        self.apply(init_weights)

    def forward(self, idx):
        x = self.tok(idx) + self.pos(torch.arange(idx.size(1), device=idx.device))
        for block in self.blocks:
            x = block(x)
        return self.head(self.ln_f(x))  # scores for every token


def init_weights(module):
    if isinstance(module, (nn.Linear, nn.Embedding)):
        nn.init.normal_(module.weight, std=0.02)  # small, so step 0 is a near-uniform guess
    if isinstance(module, nn.Linear) and module.bias is not None:
        nn.init.zeros_(module.bias)


def count_parameters(model: nn.Module) -> int:
    return sum(p.numel() for p in model.parameters())  # parameters() counts the tied matrix once


def expected_parameters(c: GPTConfig) -> dict:
    attention = c.dim * 3 * c.dim + 3 * c.dim + c.dim * c.dim + c.dim
    feed_forward = c.dim * 4 * c.dim + 4 * c.dim + 4 * c.dim * c.dim + c.dim
    norms = 2 * 2 * c.dim
    return {
        "token embedding": c.vocab * c.dim,
        "position embedding": c.ctx * c.dim,
        "attention per block": attention,
        "feed-forward per block": feed_forward,
        "blocks in total": c.layers * (attention + feed_forward + norms),
        "final normalization": 2 * c.dim,
        "output layer (tied)": 0,
    }


def main():
    parser = argparse.ArgumentParser(description="The tiny GPT")
    parser.add_argument("--count", action="store_true", help="print the parameter count")
    parser.add_argument("--vocab", type=int, default=8000)
    parser.add_argument("--ctx", type=int, default=256)
    parser.add_argument("--layers", type=int, default=6)
    parser.add_argument("--heads", type=int, default=6)
    parser.add_argument("--dim", type=int, default=384)
    args = parser.parse_args()
    c = GPTConfig(vocab=args.vocab, ctx=args.ctx, layers=args.layers, heads=args.heads, dim=args.dim)
    parts = expected_parameters(c)
    for name, value in parts.items():
        print(f"  {name:<24} {value / 1e6:>7.3f}M")
    total = count_parameters(GPT(c))
    print(f"  {'total':<24} {total / 1e6:>7.3f}M ({total:,}; Figure 45-4: ~13.8M)")
    assert total == sum(v for k, v in parts.items() if "per block" not in k)


if __name__ == "__main__":
    main()

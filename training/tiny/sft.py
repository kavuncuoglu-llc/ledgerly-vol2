import argparse
import json
import random
from pathlib import Path

import torch
import torch.nn.functional as F
from model import GPTConfig
from sample import chat_prompt
from tokenizers import Tokenizer
from train import load_model, pick_device, save_checkpoint
from train_tokenizer import EOT

IGNORE = -100


def encode_pair(tok: Tokenizer, question: str, answer: str, ctx: int) -> tuple[list[int], list[int]]:
    prompt = tok.encode(chat_prompt(question)).ids
    reply = tok.encode(answer).ids + [tok.token_to_id(EOT)]
    ids = (prompt + reply)[: ctx + 1]
    targets = [IGNORE] * (len(prompt) - 1) + reply  # loss only on the answer and the end-of-text marker
    return ids[:-1], targets[: len(ids) - 1]


def collate(rows: list[tuple[list[int], list[int]]], pad_id: int, device: str):
    width = max(len(x) for x, _ in rows)
    x = torch.full((len(rows), width), pad_id, dtype=torch.long)
    y = torch.full((len(rows), width), IGNORE, dtype=torch.long)
    for i, (ids, targets) in enumerate(rows):
        x[i, : len(ids)] = torch.tensor(ids)
        y[i, : len(targets)] = torch.tensor(targets)
    return x.to(device), y.to(device)


def masked_loss(model, x, y):
    logits = model(x)
    return F.cross_entropy(logits.view(-1, logits.size(-1)), y.view(-1), ignore_index=IGNORE)


def read_pairs(path: Path) -> list[dict]:
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]


def fine_tune(model, rows, steps: int, lr: float, batch_size: int, device: str, pad_id: int, seed: int = 45):
    opt = torch.optim.AdamW(model.parameters(), lr=lr, betas=(0.9, 0.95), weight_decay=0.1)  # every weight trains
    rng, losses = random.Random(seed), []
    model.train()
    for step in range(steps):
        x, y = collate(rng.sample(rows, min(batch_size, len(rows))), pad_id, device)
        loss = masked_loss(model, x, y)
        opt.zero_grad(set_to_none=True)
        loss.backward()
        torch.nn.utils.clip_grad_norm_(model.parameters(), 1.0)
        opt.step()
        losses.append(loss.item())
        if step % 50 == 0 or step == steps - 1:
            print(f"  sft step {step:>4} · loss {loss.item():.3f}", flush=True)
    return opt, losses


def main():
    parser = argparse.ArgumentParser(description="Supervised post-training of the tiny GPT on Q&A pairs")
    parser.add_argument("checkpoint", type=Path)
    parser.add_argument("--pairs", type=Path, default=Path("data/qa_pairs.jsonl"))
    parser.add_argument("--steps", type=int, default=300)
    parser.add_argument("--lr", type=float, default=1e-4)
    parser.add_argument("--batch", type=int, default=16)
    parser.add_argument("--tokenizer", type=Path, default=Path("tokenizer.json"))
    parser.add_argument("--out", type=Path, default=Path("ckpt/"))
    parser.add_argument("--device", default=pick_device())
    args = parser.parse_args()

    tok = Tokenizer.from_file(str(args.tokenizer))
    model, checkpoint = load_model(args.checkpoint, args.device)
    cfg = GPTConfig(**checkpoint["config"])
    rows = [encode_pair(tok, p["question"], p["answer"], cfg.ctx) for p in read_pairs(args.pairs)]
    print(f"  {len(rows)} pairs · {args.steps} steps at lr {args.lr} · from {args.checkpoint}")
    opt, losses = fine_tune(model, rows, args.steps, args.lr, args.batch, args.device, tok.token_to_id(EOT))
    path = args.out / f"sft-{args.steps}.pt"
    save_checkpoint(path, model, None, args.steps, cfg, base=str(args.checkpoint))
    print(f"  loss {losses[0]:.3f} -> {losses[-1]:.3f} · checkpoint {path}")


if __name__ == "__main__":
    main()

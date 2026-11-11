import argparse
import contextlib
import math
import os
import time
from dataclasses import asdict
from pathlib import Path

import numpy as np
import torch
import torch.nn.functional as F
from model import GPT, GPTConfig, count_parameters


def pick_device() -> str:
    if torch.cuda.is_available():
        return "cuda"
    if torch.backends.mps.is_available():
        return "mps"
    return "cpu"


def batch(src, ctx: int, size: int = 64, device: str = "cpu", generator=None):
    starts = torch.randint(len(src) - ctx - 1, (size,), generator=generator)
    x = torch.stack([torch.from_numpy(src[s : s + ctx].astype(np.int64)) for s in starts])
    y = torch.stack([torch.from_numpy(src[s + 1 : s + 1 + ctx].astype(np.int64)) for s in starts])  # shifted by one
    return x.to(device), y.to(device)


def lr_at(step, warmup=200, total=10_000, peak=3e-4, floor=3e-5):
    if step < warmup:
        return peak * (step + 1) / warmup
    progress = min(1.0, (step - warmup) / max(1, total - warmup))
    return floor + 0.5 * (peak - floor) * (1 + math.cos(math.pi * progress))


def next_token_loss(model, x, y):
    logits = model(x)
    return F.cross_entropy(logits.view(-1, logits.size(-1)), y.view(-1))  # every token is a target


@torch.no_grad()
def estimate_loss(model, src, ctx, batches, size, device, mixed) -> float:
    model.eval()
    generator = torch.Generator().manual_seed(1234)  # the same windows at every evaluation
    losses = []
    for _ in range(batches):
        x, y = batch(src, ctx, size, device, generator)
        with mixed:
            losses.append(next_token_loss(model, x, y).item())
    model.train()
    return sum(losses) / len(losses)


def save_checkpoint(path: Path, model, opt, step: int, cfg: GPTConfig, run_id: str = "", **extra):
    path.parent.mkdir(parents=True, exist_ok=True)
    state = {"model": model.state_dict(), "opt": opt.state_dict() if opt else None, "step": step}
    torch.save({**state, "config": asdict(cfg), "mlflow_run_id": run_id, **extra}, path)


def load_model(path: Path, device: str):
    checkpoint = torch.load(path, map_location=device, weights_only=True)
    model = GPT(GPTConfig(**checkpoint["config"])).to(device)
    model.load_state_dict(checkpoint["model"])
    return model, checkpoint


def latest_checkpoint(folder: Path):
    found = sorted(folder.glob("step-*.pt"), key=lambda p: int(p.stem.split("-")[1]))
    return found[-1] if found else None


class Tracker:
    def __init__(self, enabled: bool):
        self.enabled, self.run_id = enabled, ""
        if enabled:
            import mlflow

            self.mlflow = mlflow

    def start(self, run_id: str, params: dict):
        if not self.enabled:
            return
        self.mlflow.set_experiment("tiny-gpt")
        run = self.mlflow.start_run(run_id=run_id or None)
        self.run_id = run.info.run_id
        if not run_id:
            self.mlflow.log_params(params)

    def metrics(self, values: dict, step: int):
        if self.enabled:
            self.mlflow.log_metrics(values, step=step)

    def text(self, text: str, name: str):
        if self.enabled:
            self.mlflow.log_text(text, name)

    def end(self):
        if self.enabled:
            self.mlflow.end_run()


def sample_text(model, tokenizer, prompt: str, device: str) -> str:
    from sample import generate

    if tokenizer is None:
        return ""
    ids = generate(model, tokenizer.encode(prompt).ids, 40, temperature=0.8, device=device)
    return tokenizer.decode(ids).replace("\n", " ")


def parse_args(argv=None):
    parser = argparse.ArgumentParser(description="Pre-train the tiny GPT on next-token prediction")
    parser.add_argument("--steps", type=int, default=10_000)
    parser.add_argument("--batch", type=int, default=64)
    parser.add_argument("--ctx", type=int, default=256)
    parser.add_argument("--corpus", type=Path, default=Path("corpus/"))
    parser.add_argument("--tokenizer", type=Path, default=Path("tokenizer.json"))
    parser.add_argument("--out", type=Path, default=Path("ckpt/"))
    parser.add_argument("--every", type=int, default=250, help="evaluate, log, sample and checkpoint")
    parser.add_argument("--eval-batches", type=int, default=20)
    parser.add_argument("--lr", type=float, default=3e-4)
    parser.add_argument("--warmup", type=int, default=200)
    parser.add_argument("--resume", default="", help="a checkpoint, or 'auto' for the latest in --out")
    parser.add_argument("--device", default=pick_device())
    parser.add_argument("--no-causal", action="store_true", help="Exercise 2: remove the causal mask")
    parser.add_argument("--seed", type=int, default=45)
    return parser.parse_args(argv)


def train(args) -> dict:
    device = args.device
    train_ids = np.memmap(args.corpus / "train.bin", dtype=np.uint16, mode="r")  # one long array of token IDs
    val_ids = np.memmap(args.corpus / "val.bin", dtype=np.uint16, mode="r")
    tokenizer = None
    if args.tokenizer.exists():
        from tokenizers import Tokenizer

        tokenizer = Tokenizer.from_file(str(args.tokenizer))
    vocab = tokenizer.get_vocab_size() if tokenizer else int(train_ids.max()) + 1
    cfg = GPTConfig(vocab=vocab, ctx=args.ctx, layers=6, heads=6, dim=384, causal=not args.no_causal)

    resume = latest_checkpoint(args.out) if args.resume == "auto" else (Path(args.resume) if args.resume else None)
    start, run_id = 0, ""
    torch.manual_seed(args.seed)
    model = GPT(cfg).to(device)
    opt = torch.optim.AdamW(model.parameters(), lr=args.lr, betas=(0.9, 0.95), weight_decay=0.1)
    if resume:
        checkpoint = torch.load(resume, map_location=device, weights_only=True)
        model.load_state_dict(checkpoint["model"])
        opt.load_state_dict(checkpoint["opt"])
        start, run_id = checkpoint["step"], checkpoint.get("mlflow_run_id", "")
        torch.manual_seed(args.seed + start)
        print(f"  resumed from {resume} at step {start}")
    mixed = torch.autocast("cuda", dtype=torch.bfloat16) if device == "cuda" else contextlib.nullcontext()

    tracker = Tracker(bool(os.environ.get("MLFLOW_TRACKING_URI")))
    params = {**asdict(cfg), "steps": args.steps, "batch": args.batch, "lr": args.lr, "warmup": args.warmup}
    tracker.start(run_id, {**params, "device": device, "parameters": count_parameters(model)})
    print(f"  device {device} · {count_parameters(model):,} parameters · {len(train_ids):,} training tokens")

    history, began, interval_start, interval_tokens = [], time.time(), time.time(), 0
    for step in range(start, args.steps + 1):
        if (step % args.every == 0 or step == args.steps) and not (resume and step == start):
            elapsed = time.time() - interval_start
            tokens_per_s = interval_tokens / elapsed if interval_tokens else 0.0
            record = evaluate_log_sample_checkpoint(
                step, model, opt, cfg, args, train_ids, val_ids, tokenizer, tracker, mixed, tokens_per_s
            )
            history.append(record)
            interval_start, interval_tokens = time.time(), 0
        if step == args.steps:
            break
        for group in opt.param_groups:
            group["lr"] = lr_at(step, warmup=args.warmup, total=args.steps, peak=args.lr, floor=args.lr / 10)
        x, y = batch(train_ids, cfg.ctx, args.batch, device)
        with mixed:
            loss = next_token_loss(model, x, y)
        opt.zero_grad(set_to_none=True)
        loss.backward()
        torch.nn.utils.clip_grad_norm_(model.parameters(), 1.0)
        opt.step()
        interval_tokens += x.numel()

    wall = time.time() - began
    print(f"  wall time {wall / 60:.1f} min for steps {start}-{args.steps}")
    tracker.metrics({"wall_seconds": wall}, args.steps)
    tracker.end()
    return {"history": history, "wall_seconds": wall, "run_id": tracker.run_id}


def evaluate_log_sample_checkpoint(step, model, opt, cfg, args, train_ids, val_ids, tokenizer, tracker, mixed, tps):
    train_loss = estimate_loss(model, train_ids, cfg.ctx, args.eval_batches, args.batch, args.device, mixed)
    val_loss = estimate_loss(model, val_ids, cfg.ctx, args.eval_batches, args.batch, args.device, mixed)
    path = args.out / f"step-{step}.pt"
    save_checkpoint(path, model, opt, step, cfg, tracker.run_id)
    line = f"step {step:>5} · train {train_loss:.2f} · val {val_loss:.2f} · ppl {math.exp(val_loss):,.0f}"
    if step == 0:
        uniform = math.log(cfg.vocab)
        verdict = (
            "✓ wiring looks right" if abs(train_loss - uniform) < 0.5 else "✗ check the data, the shift and the loss"
        )
        line += f" · ln({cfg.vocab}) = {uniform:.2f} {verdict}"
    else:
        line += f" · gap {val_loss - train_loss:.2f} · {tps:,.0f} tokens/s"
    if step == args.steps:
        line += f" · checkpoint {path}"
    print(line, flush=True)
    sample = sample_text(model, tokenizer, "Your invoice", args.device)
    if sample:
        print(f"    sample: {sample}", flush=True)
    lr = opt.param_groups[0]["lr"]
    values = {"train_loss": train_loss, "val_loss": val_loss, "val_perplexity": math.exp(val_loss), "lr": lr}
    tracker.metrics({**values, "tokens_per_second": tps}, step)
    tracker.text(sample, f"samples/step-{step:05d}.txt")
    return {"step": step, **values, "tokens_per_second": tps, "sample": sample}


def main():
    train(parse_args())


if __name__ == "__main__":
    main()

import time

import torch
from transformers import AutoModelForCausalLM, AutoTokenizer

REPO = "Qwen/Qwen2.5-0.5B-Instruct"  # Apache 2.0, ungated, ~1 GB
REVISION = "7ae557604adf67be50417f59c2c2f167def9a775"  # pinned: a commit never changes, a branch does

MESSAGES = [
    {"role": "system", "content": "You are Ledgerly's support assistant. Answer in one sentence."},
    {"role": "user", "content": "What is a late fee?"},
]


def pick_device() -> str:
    if torch.cuda.is_available():
        return "cuda"

    if torch.backends.mps.is_available():
        return "mps"

    return "cpu"


def pick_dtype(device: str) -> torch.dtype:
    return {"cuda": torch.bfloat16, "mps": torch.float16, "cpu": torch.float32}[device]


def prompt_ids(tok, messages: list[dict], device: str) -> torch.Tensor:
    encoded = tok.apply_chat_template(messages, add_generation_prompt=True, return_tensors="pt", return_dict=True)

    return encoded["input_ids"].to(device)


def main() -> None:
    device = pick_device()
    dtype = pick_dtype(device)

    tok = AutoTokenizer.from_pretrained(REPO, revision=REVISION)
    model = AutoModelForCausalLM.from_pretrained(REPO, revision=REVISION, dtype=dtype)  # older releases: torch_dtype=
    model = model.to(device)

    ids = prompt_ids(tok, MESSAGES, device)

    start = time.perf_counter()
    with torch.inference_mode():
        out = model.generate(ids, max_new_tokens=60, do_sample=False)
    elapsed = time.perf_counter() - start

    new = out[0, ids.shape[-1] :]
    print(tok.decode(new, skip_special_tokens=True))
    print(f"{len(new)} tokens in {elapsed:.2f}s on {device} ({len(new) / elapsed:.1f} tok/s)")


if __name__ == "__main__":
    main()

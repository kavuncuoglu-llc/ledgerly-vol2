import argparse
from pathlib import Path

import torch
from tokenizers import Tokenizer
from train import load_model, pick_device
from train_tokenizer import ASSISTANT, EOT, USER


def chat_prompt(question: str) -> str:
    return f"{USER}{question}{ASSISTANT}"


@torch.no_grad()
def generate(model, ids: list[int], max_new: int, temperature: float = 0.8, device: str = "cpu", stop_id=None):
    model.eval()
    ctx = model.config.ctx
    out = list(ids)
    for _ in range(max_new):
        logits = model(torch.tensor([out[-ctx:]], device=device))[0, -1]  # the last position's scores
        if temperature <= 0:
            next_id = int(logits.argmax())
        else:
            probs = torch.softmax(logits / temperature, dim=-1)  # temperature reshapes the distribution
            next_id = int(torch.multinomial(probs, 1))
        out.append(next_id)
        if next_id == stop_id:
            break
    model.train()
    return out


def main():
    parser = argparse.ArgumentParser(description="Generate from a tiny GPT checkpoint")
    parser.add_argument("checkpoint", type=Path)
    parser.add_argument("--prompt", default="Your invoice")
    parser.add_argument("--chat", default="", help="ask a question in the post-training format")
    parser.add_argument("--temperature", type=float, default=0.8)
    parser.add_argument("--max-new", type=int, default=100)
    parser.add_argument("--samples", type=int, default=1)
    parser.add_argument("--tokenizer", type=Path, default=Path("tokenizer.json"))
    parser.add_argument("--seed", type=int, default=45)
    parser.add_argument("--device", default=pick_device())
    args = parser.parse_args()

    torch.manual_seed(args.seed)
    tok = Tokenizer.from_file(str(args.tokenizer))
    model, _ = load_model(args.checkpoint, args.device)
    prompt = chat_prompt(args.chat) if args.chat else args.prompt
    prompt_ids = tok.encode(prompt).ids
    print(f"prompt: {prompt} · temperature {args.temperature}")
    for _ in range(args.samples):
        ids = generate(model, prompt_ids, args.max_new, args.temperature, args.device, tok.token_to_id(EOT))
        text = tok.decode(ids[len(prompt_ids) :] if args.chat else ids)
        stopped = ids[-1] == tok.token_to_id(EOT)
        print(f"  {text.strip()}" + (f" {EOT}" if stopped else " …(length limit)"))


if __name__ == "__main__":
    main()
